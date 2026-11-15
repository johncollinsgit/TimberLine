@extends('managed-website.store-layout')
@section('store-content')
@if(data_get($site->publishedSiteVersion?->settings ?? $site->settings, 'theme_key') === 'sawyer-naturals')
@include('managed-website.sawyer.product')
@else
@php
    $mediaUrls = app(\App\Services\ManagedWebsite\WebsiteMediaUrls::class);
    $photos = collect($product->media ?? [])->map(fn ($url) => $mediaUrls->forSite($site, $url))->filter()->values();
    $photoAlt = data_get($product->service_details, 'image_alt') ?: $product->title;
@endphp
<section class="store-shell store-product">
    <div class="store-product__photos">
        @forelse($photos as $photo)<div class="store-product__image"><img src="{{ $photo }}" alt="{{ $photoAlt }}" width="960" height="960" loading="{{ $loop->first ? 'eager' : 'lazy' }}" decoding="async"></div>@empty<div class="store-product__image"><span>Photo coming soon</span></div>@endforelse
    </div>
    <div>
        <a class="store-back" href="{{ route('managed-website.store.index') }}">Back to {{ $product->product_type === 'quote' ? 'services' : 'shop' }}</a>
        <p>{{ $product->product_type === 'quote' ? 'Request a quote' : str($product->product_type)->headline() }}</p>
        <h1>{{ $product->title }}</h1><p class="store-copy">{{ $product->description }}</p>
        @if($product->product_type === 'quote')
            @include('managed-website.quote-request')
        @else
            @include('managed-website.variant-picker')
        @endif
    </div>
</section>
@endif
@endsection
