<article class="product-card">
    <a class="product-image" href="/products/{{ $listing->slug }}">
        @if($listing->images)<img src="{{ $listing->images[0] }}" alt="{{ $listing->title }}" loading="lazy" width="600" height="700">@else<div class="image-placeholder">Made with care</div>@endif
        <span class="product-tag">{{ $listing->shipping ? 'Ships to you' : 'Market pickup' }}</span>
    </a>
    <div class="product-meta"><a href="/makers/{{ $listing->maker->slug }}">{{ $listing->maker->name }}</a><span>{{ $listing->maker->city }}, SC</span></div>
    <div class="product-title"><h3><a href="/products/{{ $listing->slug }}">{{ $listing->title }}</a></h3><span>${{ number_format($listing->price_cents / 100, 2) }}</span></div>
    <p>{{ $listing->variant_label ?: $listing->category }}</p>
</article>
