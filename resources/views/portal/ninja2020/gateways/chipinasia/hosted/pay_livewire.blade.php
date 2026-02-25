@extends('portal.ninja2020.layout.payments', ['gateway_title' => 'CHIP', 'card_title' => 'CHIP'])

@section('gateway_content')
    <div class="flex flex-col items-center justify-center py-8">
        <p class="text-gray-600 mb-4">{{ ctrans('texts.payment_processing') }}</p>
        @if(!empty($redirect_url ?? null))
            <meta http-equiv="refresh" content="0;url={{ $redirect_url }}">
            <a href="{{ $redirect_url }}" class="button primary">{{ ctrans('texts.click_here') }}</a>
        @endif
    </div>
    @include('portal.ninja2020.gateways.includes.payment_details')
@endsection
