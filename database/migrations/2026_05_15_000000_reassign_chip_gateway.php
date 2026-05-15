<?php

use App\Models\Gateway;
use App\Models\PaymentType;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {

    public function up(): void
    {
        \Illuminate\Database\Eloquent\Model::unguard();

        // If gateway 67 is still CHIP from the pre-merge state, reassign it to 68
        // so that Payware can take id 67 (matching upstream).
        $chip = Gateway::find(67);
        if ($chip && $chip->provider === 'ChipInAsia') {
            $chip->id = 68;
            $chip->save();
        }

        // Ensure Payware exists at id 67 (same logic as upstream migration).
        if (! Gateway::find(67)) {
            $fields = new \stdClass;
            $fields->partnerId = '';
            $fields->vposId = '';
            $fields->paywarePublicKey = '';
            $fields->testMode = false;
            $fields->timeToLive = 600;

            $gateway = new Gateway();
            $gateway->id = 67;
            $gateway->name = 'payware';
            $gateway->key = 'b0a6294fca4488c2bab58f3e11e3c623';
            $gateway->provider = 'Payware';
            $gateway->is_offsite = false;
            $gateway->fields = \json_encode($fields);
            $gateway->visible = true;
            $gateway->sort_order = 29;
            $gateway->site_url = 'https://payware.eu';
            $gateway->default_gateway_type_id = 30;
            $gateway->save();
        }

        // Ensure CHIP exists at id 68 (for fresh databases or if the reassign above happened).
        if (! Gateway::find(68)) {
            $fields = new \stdClass;
            $fields->apiKey = '';
            $fields->brandId = '';

            $gateway = new Gateway();
            $gateway->id = 68;
            $gateway->name = 'CHIP';
            $gateway->key = 'c7a8e2f1b4d90635a3f8e1c9b2d4a6e0';
            $gateway->provider = 'ChipInAsia';
            $gateway->is_offsite = true;
            $gateway->fields = \json_encode($fields);
            $gateway->visible = true;
            $gateway->sort_order = 30;
            $gateway->site_url = 'https://notes.chip-in.asia/s/faq/p/Qwsatm6PeN';
            $gateway->default_gateway_type_id = 14;
            $gateway->save();
        }

        // Generic Mobile Payment type for Payware (copied from upstream migration).
        if (! PaymentType::find(PaymentType::MOBILE_PAYMENT)) {
            $paymentType = new PaymentType();
            $paymentType->id = PaymentType::MOBILE_PAYMENT;
            $paymentType->name = 'Mobile Payment';
            $paymentType->gateway_type_id = 30;
            $paymentType->save();
        }

        \Illuminate\Database\Eloquent\Model::reguard();
    }

    public function down(): void
    {
        //
    }
};
