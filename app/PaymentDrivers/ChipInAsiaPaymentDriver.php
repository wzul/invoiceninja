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

class ChipInAsiaPaymentDriver extends BaseDriver
{
    use MakesHash;

    public $refundable = false;

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

    public function refund(Payment $payment, $amount, $return_client_response = false) {}

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
