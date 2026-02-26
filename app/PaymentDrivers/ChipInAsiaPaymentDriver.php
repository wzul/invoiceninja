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

namespace App\PaymentDrivers;

use App\Http\Requests\Payments\PaymentNotificationWebhookRequest;
use App\Models\GatewayType;
use App\Models\Payment;
use App\Models\PaymentHash;
use App\Models\SystemLog;
use App\PaymentDrivers\ChipInAsia\Hosted;
use App\Utils\Traits\MakesHash;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class ChipInAsiaPaymentDriver extends BaseDriver
{
    use MakesHash;

    public $refundable = true;

    public $token_billing = false;

    public $can_authorise_credit_card = false;

    public $payment_method;

    public static $methods = [
        GatewayType::HOSTED_PAGE => Hosted::class,
    ];

    public const SYSTEM_LOG_TYPE = SystemLog::TYPE_CHIPINASIA;

    public function init(): self
    {
        return $this;
    }

    public function gatewayTypes(): array
    {
        $types = [];
        if (($this->client->currency()->code ?? '') === 'MYR') {
            $types[] = GatewayType::HOSTED_PAGE;
        }

        return $types;
    }

    public function setPaymentMethod($payment_method_id)
    {
        $class = self::$methods[$payment_method_id];
        $this->payment_method = new $class($this);

        return $this;
    }

    public function authorizeView(array $data)
    {
        return $this->payment_method->authorizeView($data);
    }

    public function authorizeResponse($request)
    {
        return $this->payment_method->authorizeResponse($request);
    }

    public function processPaymentView(array $data)
    {
        return $this->payment_method->paymentView($data);
    }

    public function processPaymentResponse($request)
    {
        return $this->payment_method->paymentResponse($request);
    }

    /**
     * Do not create a CHIP purchase here; return redirect_to_gateway_url so the Livewire view
     * shows a link. Purchase is created only when the user clicks (redirectToGateway).
     * Include gateway so the payments layout (required-client-info) has $gateway.
     */
    public function processPaymentViewData(array $data): array
    {
        $data['gateway'] = $this;
        $data['redirect_to_gateway_url'] = route('client.payments.redirect_to_gateway', [
            'payment_hash' => $this->payment_hash->hash,
            'company_gateway_id' => $this->company_gateway->id,
            'payment_method_id' => $data['payment_method_id'] ?? GatewayType::HOSTED_PAGE,
        ]);

        return $data;
    }

    /**
     * Refund a CHIP payment via POST /purchases/{id}/refund/
     * Amount in minor units (cents); omit for full refund.
     *
     * @return array{transaction_reference: string|null, transaction_response: string, success: bool, description: string, code: int|string, amount?: float}
     */
    public function refund(Payment $payment, $amount, $return_client_response = false): array
    {
        $this->init();
        $purchaseId = $payment->transaction_reference;
        if (empty($purchaseId)) {
            SystemLogger::dispatch(
                'CHIP refund: missing transaction_reference (purchase id) on payment',
                SystemLog::CATEGORY_GATEWAY_RESPONSE,
                SystemLog::EVENT_GATEWAY_FAILURE,
                SystemLog::TYPE_CHIPINASIA,
                $this->client,
                $this->client->company,
            );

            return [
                'transaction_reference' => null,
                'transaction_response' => '',
                'success' => false,
                'description' => 'Missing CHIP purchase id on payment.',
                'code' => 422,
            ];
        }

        $url = 'https://gate.chip-in.asia/api/v1/purchases/' . $purchaseId . '/refund/';
        $body = [];
        if ($amount > 0) {
            $body['amount'] = (int) round($amount * 100);
        }

        $response = Http::withToken($this->company_gateway->getConfigField('apiKey'))
            ->acceptJson()
            ->timeout(30)
            ->post($url, $body);

        if ($response->successful()) {
            $data = $response->json();
            SystemLogger::dispatch(
                ['server_response' => $data, 'payment_id' => $payment->id],
                SystemLog::CATEGORY_GATEWAY_RESPONSE,
                SystemLog::EVENT_GATEWAY_SUCCESS,
                SystemLog::TYPE_CHIPINASIA,
                $this->client,
                $this->client->company,
            );
            // CHIP response: Payment object with id, payment.amount (minor units), payment.description, status
            $refundAmount = isset($data['payment']['amount'])
                ? (float) $data['payment']['amount'] / 100
                : $amount;

            return [
                'transaction_reference' => $data['id'] ?? $purchaseId,
                'transaction_response' => json_encode($data),
                'success' => true,
                'description' => $data['payment']['description'] ?? 'Refunded',
                'code' => 200,
                'amount' => $refundAmount,
            ];
        }

        $errorBody = $response->json();
        $description = $errorBody['__all__']['message'] ?? $errorBody['message'] ?? $response->body() ?: 'Refund failed';
        SystemLogger::dispatch(
            ['server_response' => $errorBody, 'payment_id' => $payment->id],
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_FAILURE,
            SystemLog::TYPE_CHIPINASIA,
            $this->client,
            $this->client->company,
        );

        return [
            'transaction_reference' => null,
            'transaction_response' => json_encode($errorBody),
            'success' => false,
            'description' => $description,
            'code' => $response->status(),
        ];
    }

    public function tokenBilling(\App\Models\ClientGatewayToken $cgt, PaymentHash $payment_hash) {}

    /**
     * Handle CHIP success_callback: verify X-Signature with public key, then create payment if status is paid.
     */
    public function processWebhookRequest(PaymentNotificationWebhookRequest $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = $request->header('X-Signature');

        $this->setPaymentMethod(GatewayType::HOSTED_PAGE);

        if (! $this->payment_method->verifyCallbackSignature($rawBody, $signature ?? '')) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $purchase = json_decode($rawBody, true);
        if (! is_array($purchase)) {
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $reference = $purchase['reference'] ?? null;
        if (! $reference) {
            return response()->json(['message' => 'Missing reference'], 400);
        }

        $payment_hash = PaymentHash::where('hash', $reference)->first();
        if (! $payment_hash) {
            return response()->json(['message' => 'Payment hash not found'], 404);
        }

        $status = $purchase['status'] ?? '';
        if (strtolower($status) !== 'paid') {
            return response()->json([], 200);
        }

        $this->setPaymentHash($payment_hash);
        $this->payment_method->createPaymentFromCallback($purchase);

        return response()->json([], 200);
    }
}
