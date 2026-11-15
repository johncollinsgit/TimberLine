@php
    $mediaUrls = app(\App\Services\ManagedWebsite\WebsiteMediaUrls::class);
@endphp
<div class="sn-wrap">
    <div class="sn-breadcrumb"><a href="{{ $homeUrl ?? '/' }}">Home</a> / <a href="{{ ($shopUrl ?? route('managed-website.store.index')) }}">Shop</a> / {{ $product->title }}</div>
    <div class="sn-product">
        <div>
            <div class="sn-product-main">
                @if($mediaUrls->forSite($site, data_get($product->media, '0')))<img src="{{ $mediaUrls->forSite($site, data_get($product->media, '0')) }}" alt="{{ data_get($product->service_details, 'image_alt') ?: $product->title }}">@else<span class="sn-gift-art"><span>Sawyer Naturals</span><strong>A little care<br>goes a long way.</strong><small>eGift Card</small></span>@endif
            </div>
            @if(count((array) $product->media) > 1)<div class="sn-product-thumbs">@foreach(array_slice((array) $product->media, 1, 4) as $image)<img loading="lazy" src="{{ $mediaUrls->forSite($site, $image) }}" alt="Additional view of {{ $product->title }}">@endforeach</div>@endif
        </div>
        <div class="sn-product-details">
            <span class="sn-eyebrow">Made on the homestead</span><h1>{{ $product->title }}</h1>
            <p class="sn-copy">{{ $product->description }}</p>
            @if($product->product_type === 'quote')
                @include('managed-website.quote-request')
            @else
                @include('managed-website.variant-picker', ['design' => 'sawyer'])
            @endif
            <p class="sn-note">@if((int) data_get($storeSettings ?? $site->publishedSiteVersion?->settings, 'free_shipping_threshold_cents', 0) > 0)Free shipping on orders of ${{ number_format((int) data_get($storeSettings ?? $site->publishedSiteVersion?->settings, 'free_shipping_threshold_cents') / 100, 2) }} or more before tax. @endif Shipping options appear at checkout when the store is ready.</p>
            <hr class="sn-divider"><details><summary style="font-weight:700;cursor:pointer">Our ingredient promise</summary><p class="sn-copy">Sawyer Naturals uses essential oils and avoids perfumes or synthetic fragrances.</p></details>
            <hr class="sn-divider"><details><summary style="font-weight:700;cursor:pointer">Returns & exchanges</summary><p class="sn-copy">Unopened and unused full-size products may be returned or exchanged. Contact sawyernaturals@gmail.com within 14 days of delivery.</p></details>
        </div>
    </div>
</div>
