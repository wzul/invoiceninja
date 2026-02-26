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
        return render('gateways.chipinasia.hosted.authorize', $data);
    }

    public function authorizeResponse(Request $request): RedirectResponse
    {
        return redirect()->route('client.payment_methods.index');
    }

    public function paymentView(array $data): View|RedirectResponse
    {
        $data = $this->paymentData($data);

        if (! empty($data['redirect_url'])) {
            return redirect()->away($data['redirect_url']);
        }

        return render('gateways.chipinasia.hosted.pay', $data);
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
            'reference' => $this->driver->payment_hash->hash,
            'success_redirect' => $returnUrl,
            'failure_redirect' => $returnUrl,
            'cancel_redirect' => $returnUrl,
            'success_callback' => $this->driver->genericWebhookUrl(),
        ];

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

        return $request->post($url, $body);
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
     */
    public function createPaymentFromCallback(array $purchase): void
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

        $this->driver->createPayment($data, Payment::STATUS_COMPLETED);

        SystemLogger::dispatch(
            ['response' => $purchaseId, 'data' => $data],
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_SUCCESS,
            SystemLog::TYPE_CHIPINASIA,
            $this->driver->client,
            $this->driver->client->company,
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
