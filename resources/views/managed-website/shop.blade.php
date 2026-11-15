@extends('managed-website.store-layout')
@section('store-content')
@if(data_get($site->publishedSiteVersion?->settings ?? $site->settings, 'theme_key') === 'sawyer-naturals')
@include('managed-website.sawyer.shop')
@else
@php
    $mediaUrls = app(\App\Services\ManagedWebsite\WebsiteMediaUrls::class);
    $quoteOnly = $products->isNotEmpty() && $products->every(fn ($product) => $product->product_type === 'quote');
@endphp
<section class="store-hero"><h1>{{ $tenant->brandProfile?->display_name ?: $tenant->name }}</h1></section>
<section class="store-shell">
    <div class="store-heading"><h2>{{ $quoteOnly ? 'Services' : 'Shop' }}</h2></div>
    <div class="store-grid">
        @forelse($products as $product)
            @php
                $photo = collect($product->media ?? [])->map(fn ($url) => $mediaUrls->forSite($site, $url))->filter()->first();
                $photoAlt = data_get($product->service_details, 'image_alt') ?: $product->title;
            @endphp
            <article class="store-card">
                <div class="store-card__image">@if($photo)<img src="{{ $photo }}" alt="{{ $photoAlt }}" width="720" height="560" loading="lazy" decoding="async">@else<span>Photo coming soon</span>@endif</div>
                @if($product->product_type === 'quote')<p>Request a quote</p>@endif
                <h3>{{ $product->title }}</h3>
                <span>{{ str($product->description)->limit(105) }}</span>
                <a href="{{ route('managed-website.store.products.show', ['handle' => $product->handle]) }}">{{ $product->product_type === 'quote' ? 'Request a quote' : 'View product' }}</a>
            </article>
        @empty
            <p class="store-empty">The catalog is being prepared. Please check back soon.</p>
        @endforelse
    </div>
</section>
@endif
@endsection
