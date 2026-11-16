@extends('managed-website.store-layout')
@section('store-content')
@if(data_get($site->settings, 'theme_key') === 'sawyer-naturals')
@include('managed-website.sawyer.order')
@else
<section class="store-shell store-order">
    <p class="store-order__eyebrow">THANK YOU</p>
    <h1>Order <span>{{ $order->number }}</span></h1>
    <p class="store-order__status">Your payment status is <strong>{{ str($order->payment_status)->headline() }}</strong>. We will coordinate {{ str($order->fulfillment_method)->replace('_', ' ')->lower() }} directly.</p>
    <section class="store-order__summary" aria-label="Order summary">
        @foreach($order->lines as $line)
            <div class="store-order__item">
                <div class="store-order__details">
                    <strong>{{ $line->title }}</strong>
                    <span>{{ $line->quantity }} × ${{ number_format($line->unit_price_cents / 100, 2) }}</span>
                </div>
                <strong class="store-order__amount">${{ number_format($line->line_total_cents / 100, 2) }}</strong>
            </div>
        @endforeach
        @if($order->tax_cents > 0)
            <div class="store-order__charge">
                <span>Tax</span>
                <span class="store-order__amount">${{ number_format($order->tax_cents / 100, 2) }}</span>
            </div>
        @endif
        @if($order->shipping_cents > 0)
            <div class="store-order__charge">
                <span>Shipping</span>
                <span class="store-order__amount">${{ number_format($order->shipping_cents / 100, 2) }}</span>
            </div>
        @endif
        <div class="store-order__total">
            <strong>Total</strong>
            <strong class="store-order__amount">${{ number_format($order->total_cents / 100, 2) }}</strong>
        </div>
    </section>
    <div class="store-order__actions">
        <a class="store-button" href="{{ route('managed-website.store.index') }}">Return to shop</a>
    </div>
    @include('managed-website.shipment-progress')
</section>
@endif
@endsection
