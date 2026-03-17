@extends('shopify.rewards-layout')

@section('rewards-content')
    <div class="rewards-placeholder">
        <h2>{{ $title ?? 'Coming soon' }}</h2>
        <p>{{ $message ?? 'We are building this section so you can manage the live Candle Cash program without leaving Shopify Admin.' }}</p>
    </div>
@endsection
