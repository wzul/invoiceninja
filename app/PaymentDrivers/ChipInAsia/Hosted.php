<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\PaymentDrivers\ChipInAsia;

use App\Exceptions\PaymentFailed;
use App\Http\Requests\ClientPortal\Payments\PaymentResponseRequest;
use App\Jobs\Util\SystemLogger;
use App\Models\GatewayType;
use App\Models\Payment;
use App\Models\PaymentType;
use App\Models\SystemLog;
use App\PaymentDrivers\Common\LivewireMethodInterface;
use App\PaymentDrivers\Common\MethodInterface;
use App\PaymentDrivers\ChipInAsiaPaymentDriver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class Hosted implements MethodInterface, LivewireMethodInterface
{
    protected ChipInAsiaPaymentDriver $driver;

    private const API_BASE_URL = 'https://gate.chip-in.asia/api/v1';

    public function __construct(ChipInAsiaPaymentDriver $driver)
    {
        $this->driver = $driver;
        $this->driver->init();
    }

    public function authorizeView(array $data): View
    {
        $data['gateway'] = $this->driver;
        return render('gateways.chipinasia.hosted.authorize', $data);
    }

    public function authorizeResponse(Request $request): RedirectResponse
    {
        return redirect()->route('client.payment_methods.index');
    }

    /**
     * When the gateway does NOT require "Always show required fields form", redirect immediately to the
     * gateway so the user skips the intermediate "Pay Now" page. When that option is enabled, show the
     * pay view so the payments layout can display the required-client-info form; the user fills it and
     * then clicks "Pay Now" to go to CHIP. We do NOT create the CHIP purchase here in either case.
     */
    public function paymentView(array $data): View|RedirectResponse
    {
        $data['gateway'] = $this->driver;
        $redirect_to_gateway_url = route('client.payments.redirect_to_gateway', [
            'payment_hash' => $data['payment_hash'],
            'company_gateway_id' => $this->driver->company_gateway->id,
            'payment_method_id' => $data['payment_method_id'],
        ]);

        if ($this->driver->company_gateway->always_show_required_fields ?? false) {
            $data['redirect_to_gateway_url'] = $redirect_to_gateway_url;
            return render('gateways.chipinasia.hosted.pay', $data);
        }

        return redirect()->to($redirect_to_gateway_url);
    }

    public function paymentResponse(PaymentResponseRequest $request): RedirectResponse
    {
        // CHIP *_redirect URLs do not include purchase id; only success_callback (webhook) sends JSON with "id".
        // We store chip_purchase_id when creating the purchase and use it here for the redirect return.
        $purchaseId = $this->driver->payment_hash->data->chip_purchase_id ?? null;

        if (empty($purchaseId)) {
            $this->driver->sendFailureMail('Missing chip_purchase_id in payment hash (CHIP redirect does not pass id).');
            throw new PaymentFailed('Invalid return from payment gateway. Please contact support.');
        }

        $purchase = $this->getPurchase($purchaseId);

        if (! $purchase) {
            $this->driver->sendFailureMail('Could not verify payment with CHIP.');
            throw new PaymentFailed('Could not verify payment. Please contact support.');
        }

        $status = $purchase['status'] ?? null;

        if ($status === 'paid') {
            return $this->processSuccessfulPayment($purchase);
        }

        $message = 'Payment was not completed.';
        if (isset($purchase['transaction_data']['attempts'][0]['error']['message'])) {
            $message = $purchase['transaction_data']['attempts'][0]['error']['message'];
        }
        $this->processUnsuccessfulPayment($message, $purchaseId);
    }

    public function livewirePaymentView(array $data): string
    {
        return 'gateways.chipinasia.hosted.pay_livewire';
    }

    public function paymentData(array $data): array
    {
        $returnUrl = route('client.payments.response.get', [], true);
        $returnUrl .= '?payment_hash=' . $this->driver->payment_hash->hash;
        $returnUrl .= '&company_gateway_id=' . $this->driver->company_gateway->id;
        $returnUrl .= '&payment_method_id=' . GatewayType::HOSTED_PAGE;

        $contact = $this->driver->getContact();
        $client = $this->driver->client;
        $amountWithFee = (float) $this->driver->payment_hash->data->amount_with_fee;

        // CHIP only supports MYR
        $amountCents = (int) round($amountWithFee * 100);

        $purchasePayload = [
            'products' => [
                [
                    'name' => $this->driver->getDescription(true),
                    'price' => $amountCents,
                ],
            ],
            'currency' => 'MYR',
        ];

        $payload = [
            'brand_id' => $this->driver->company_gateway->getConfigField('brandId'),
            'client' => [
                'email' => $contact && $contact->email ? $contact->email : $client->contacts()->first()?->email ?? '',
                'full_name' => trim(($contact ? $contact->first_name . ' ' . $contact->last_name : '') ?: $client->name ?? ''),
                'phone' => $client->phone ?? '',
            ],
            'purchase' => $purchasePayload,
            'reference' => $this->driver->payment_hash->hash,
            'success_redirect' => $returnUrl,
            'failure_redirect' => $returnUrl,
            'cancel_redirect' => $returnUrl,
            'success_callback' => $this->driver->genericWebhookUrl(),
        ];

        // Token billing: "always" sends force_recurring + whitelist. "off" sends nothing and we never store token.
        // For always, optin, optout we set request_recurring_token so we store the token when CHIP returns it; for off we do not.
        $tokenBilling = $this->driver->company_gateway->token_billing ?? 'off';
        if ($tokenBilling === 'always') {
            $payload['force_recurring'] = true;
            $payload['payment_method_whitelist'] = ['visa', 'mastercard', 'maestro'];
        }
        if (in_array($tokenBilling, ['always', 'optin', 'optout'], true)) {
            $this->driver->payment_hash->withData('request_recurring_token', true);
        }
        // off: do not set force_recurring, payment_method_whitelist, or request_recurring_token

        $response = $this->chipRequest('POST', '/purchases/', $payload);

        if ($response->successful()) {
            $body = $response->json();
            $checkoutUrl = $body['checkout_url'] ?? null;
            $purchaseId = $body['id'] ?? null;
            if ($checkoutUrl) {
                if ($purchaseId) {
                    $this->driver->payment_hash->withData('chip_purchase_id', $purchaseId);
                }
                $data['redirect_url'] = $checkoutUrl;
                $data['gateway'] = $this->driver;
                return $data;
            }
        }

        $errorBody = $response->json();
        $error = $errorBody['message'] ?? $errorBody['errors'][0]['message'] ?? $response->body() ?: 'Failed to create payment.';
        $this->driver->sendFailureMail($error);
        throw new PaymentFailed($error);
    }

    /**
     * Call CHIP API with Bearer token.
     * Payload keys with empty string values are omitted (CHIP should not receive them).
     */
    private function chipRequest(string $method, string $path, array $body = []): \Illuminate\Http\Client\Response
    {
        $url = self::API_BASE_URL . $path;
        $request = Http::withToken($this->driver->company_gateway->getConfigField('apiKey'))
            ->acceptJson()
            ->timeout(30);

        if ($method === 'GET') {
            return $request->get($url);
        }

        return $request->post($url, $this->removeEmptyStrings($body));
    }

    /**
     * Recursively remove keys whose value is an empty string so they are not sent to CHIP.
     *
     * @param array<string, mixed> $arr
     * @return array<string, mixed>
     */
    private function removeEmptyStrings(array $arr): array
    {
        $result = [];
        foreach ($arr as $key => $value) {
            if (is_array($value)) {
                $filtered = $this->removeEmptyStrings($value);
                $result[$key] = $filtered;
            } elseif ($value !== '') {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * GET /purchases/{id}/ to retrieve purchase status.
     */
    private function getPurchase(string $purchaseId): ?array
    {
        $response = $this->chipRequest('GET', '/purchases/' . $purchaseId . '/');

        if (! $response->successful()) {
            return null;
        }

        return $response->json();
    }

    /**
     * Create a CHIP purchase for token billing (no redirect). Used to charge a saved card via POST .../charge/.
     *
     * @return string the new purchase id
     */
    public function createPurchaseForTokenCharge(): string
    {
        $payment_hash = $this->driver->payment_hash;
        $amountWithFee = (float) $payment_hash->data->amount_with_fee;
        $amountCents = (int) round($amountWithFee * 100);
        $contact = $this->driver->getContact();
        $client = $this->driver->client;

        $payload = [
            'brand_id' => $this->driver->company_gateway->getConfigField('brandId'),
            'client' => [
                'email' => $contact && $contact->email ? $contact->email : $client->contacts()->first()?->email ?? '',
                'full_name' => trim(($contact ? $contact->first_name . ' ' . $contact->last_name : '') ?: $client->name ?? ''),
                'phone' => $client->phone ?? '',
            ],
            'purchase' => [
                'products' => [
                    [
                        'name' => $this->driver->getDescription(true),
                        'price' => $amountCents,
                    ],
                ],
                'currency' => 'MYR',
            ],
            'reference' => $payment_hash->hash,
            'success_callback' => $this->driver->genericWebhookUrl(),
        ];

        $response = $this->chipRequest('POST', '/purchases/', $payload);
        if (! $response->successful()) {
            $errorBody = $response->json();
            $error = $errorBody['__all__']['message'] ?? $errorBody['message'] ?? $response->body() ?: 'Failed to create purchase for charge.';
            throw new PaymentFailed($error);
        }

        $body = $response->json();
        $purchaseId = $body['id'] ?? null;
        if (empty($purchaseId)) {
            throw new PaymentFailed('CHIP did not return a purchase id.');
        }

        return $purchaseId;
    }

    /**
     * Charge a CHIP purchase using a recurring token (saved card). POST /purchases/{id}/charge/.
     *
     * @return array the response body on success
     */
    public function chargeWithToken(string $purchaseId, string $recurringToken): array
    {
        $response = $this->chipRequest('POST', '/purchases/' . $purchaseId . '/charge/', [
            'recurring_token' => $recurringToken,
        ]);

        if (! $response->successful()) {
            $errorBody = $response->json();
            $code = $errorBody['__all__']['code'] ?? '';
            $message = $errorBody['__all__']['message'] ?? $errorBody['message'] ?? $response->body() ?: 'Charge failed.';
            throw new PaymentFailed($message, $response->status());
        }

        return $response->json();
    }

    /**
     * POST /purchases/{id}/cancel/ to cancel a purchase and prevent future payment.
     */
    private function cancelPurchase(string $purchaseId): bool
    {
        $response = $this->chipRequest('POST', '/purchases/' . $purchaseId . '/cancel/');

        return $response->successful();
    }

    /**
     * Retrieve the public key for authenticating CHIP callback payloads (e.g. success_callback or webhooks).
     * Cached per company gateway forever (no TTL) so the API is only called once; the key does not change.
     * See: https://docs.chip-in.asia/chip-collect/api-reference/public-key/retrieve
     *
     * @return string|null PEM-encoded RSA public key, or null on failure
     */
    public function getWebhookPublicKey(): ?string
    {
        $cacheKey = 'chip_webhook_public_key_' . $this->driver->company_gateway->id;

        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        $response = $this->chipRequest('GET', '/public_key/');
        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();
        if (is_string($body)) {
            $key = str_replace('\n', "\n", $body);
        } else {
            $raw = $body['public_key'] ?? $body['key'] ?? null;
            $key = is_string($raw) ? str_replace('\n', "\n", $raw) : null;
        }

        if (is_string($key)) {
            Cache::forever($cacheKey, $key);
        }

        return $key;
    }

    /**
     * Verify CHIP success_callback X-Signature (base64-encoded RSA PKCS#1 v1.5 signature of SHA256 digest of request body).
     * See: https://docs.chip-in.asia/chip-collect/overview/callbacks
     */
    public function verifyCallbackSignature(string $rawBody, string $signatureHeader): bool
    {
        if ($rawBody === '' || $signatureHeader === '') {
            return false;
        }

        $publicKeyPem = $this->getWebhookPublicKey();
        if (! $publicKeyPem) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            return false;
        }

        $signature = base64_decode($signatureHeader, true);
        if ($signature === false) {
            return false;
        }

        $verified = openssl_verify($rawBody, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        openssl_pkey_free($publicKey);

        return $verified === 1;
    }

    protected function processSuccessfulPayment(array $purchase): RedirectResponse
    {
        $this->createPaymentFromCallback($purchase);

        $purchaseId = $purchase['id'] ?? $purchase['purchase_id'] ?? '';

        return redirect()->route('client.payments.show', ['payment' => $this->driver->encodePrimaryKey($this->driver->payment_hash->payment_id)]);
    }

    /**
     * Create a payment record from verified CHIP success_callback payload (no redirect).
     * When token_billing was requested and CHIP returned a recurring token, store it as ClientGatewayToken.
     *
     * @return Payment the created payment
     */
    public function createPaymentFromCallback(array $purchase): Payment
    {
        $amount = isset($purchase['purchase']['total']) ? (float) $purchase['purchase']['total'] / 100
            : array_sum(array_column($this->driver->payment_hash->invoices(), 'amount')) + $this->driver->payment_hash->fee_total;
        $purchaseId = $purchase['id'] ?? $purchase['purchase_id'] ?? '';

        $data = [
            'gateway_type_id' => GatewayType::HOSTED_PAGE,
            'amount' => $amount,
            'payment_type' => PaymentType::HOSTED_PAGE,
            'transaction_reference' => (string) $purchaseId,
        ];

        $payment = $this->driver->createPayment($data, Payment::STATUS_COMPLETED);

        $requestRecurring = $this->driver->payment_hash->data->request_recurring_token ?? false;
        $isRecurringToken = $purchase['purchase']['is_recurring_token'] ?? $purchase['is_recurring_token'] ?? false;
        if ($requestRecurring && $isRecurringToken && $purchaseId !== '') {
            $this->storeRecurringToken($purchaseId, $purchase);
        }

        SystemLogger::dispatch(
            ['response' => $purchaseId, 'data' => $data],
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_SUCCESS,
            SystemLog::TYPE_CHIPINASIA,
            $this->driver->client,
            $this->driver->client->company,
        );

        return $payment;
    }

    /**
     * Store CHIP recurring token (purchase id) as ClientGatewayToken for later token billing.
     */
    private function storeRecurringToken(string $purchaseId, array $purchase): void
    {
        $extra = $purchase['transaction_data']['extra'] ?? $purchase['transaction_data']['attempts'][0]['extra'] ?? [];
        $paymentMeta = [];
        if (isset($extra['masked_pan'])) {
            $paymentMeta['last4'] = substr(preg_replace('/\s/', '', $extra['masked_pan']), -4);
        }
        if (isset($extra['cardholder_name'])) {
            $paymentMeta['cardholder_name'] = $extra['cardholder_name'];
        }

        $this->driver->storeGatewayToken(
            [
                'token' => $purchaseId,
                'payment_method_id' => GatewayType::HOSTED_PAGE,
                'payment_meta' => $paymentMeta,
            ],
            ['gateway_customer_reference' => $purchaseId]
        );
    }

    protected function processUnsuccessfulPayment(string $message, ?string $purchaseId = null): void
    {
        if ($purchaseId !== null && $purchaseId !== '') {
            $this->cancelPurchase($purchaseId);
        }

        $this->driver->sendFailureMail($message);

        SystemLogger::dispatch(
            $message,
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_FAILURE,
            SystemLog::TYPE_CHIPINASIA,
            $this->driver->client,
            $this->driver->client->company,
        );

        // Show generic message to user (same as Stripe, Razorpay, etc.); raw CHIP message is in mail/logs above.
        throw new PaymentFailed(ctrans('texts.payment_error'), 500);
    }
}
