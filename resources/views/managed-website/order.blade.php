@extends('managed-website.store-layout')
@section('store-content')
<section class="store-shell store-order"><p>THANK YOU</p><h1>Order {{ $order->number }}</h1><p>Your payment status is <strong>{{ str($order->payment_status)->headline() }}</strong>. We will coordinate {{ str($order->fulfillment_method)->replace('_', ' ')->lower() }} directly.</p><div class="store-cart">@foreach($order->lines as $line)<div><strong>{{ $line->title }}</strong><span>{{ $line->quantity }} × ${{ number_format($line->unit_price_cents / 100, 2) }}</span></div>@endforeach<div><strong>Total</strong><span>${{ number_format($order->total_cents / 100, 2) }}</span></div></div><a class="store-button" href="{{ route('managed-website.store.index') }}">Return to shop</a></section>
@endsection
