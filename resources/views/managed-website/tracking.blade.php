@extends('managed-website.store-layout')
@section('store-content')
@php($sawyer = data_get($site->settings, 'theme_key') === 'sawyer-naturals')
<section class="{{ $sawyer ? 'sn-wrap' : 'store-shell' }}" style="max-width:870px;padding-top:64px;padding-bottom:64px">
    <h1 style="font-size:32px">Order {{ $order->number }}</h1>
    <p style="margin-top:12px">Placed {{ $order->created_at->format('M j, Y') }}</p>
    @include('managed-website.shipment-progress')
    <h2 style="font-size:24px;margin-top:32px">Items</h2>
    @foreach($order->lines as $line)<p style="margin-top:12px">{{ $line->quantity }} × {{ $line->title }}</p>@endforeach
    <p style="margin-top:32px">Keep this order link private.</p>
    <a href="{{ route('managed-website.store.index') }}" style="display:inline-block;text-decoration:underline;margin-top:16px">Return to shop</a>
</section>
@endsection
