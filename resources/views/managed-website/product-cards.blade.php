@php
    $catalogCards = $retailCards ?? app(\App\Services\ManagedWebsite\WebsiteStorefrontPresentation::class)->cards($site, $isDraftPreview ?? false);
    $cardProducts = $catalogCards['products'] ?? [];
    $cardCollections = $catalogCards['collections'] ?? [];
    $privateCatalog = (bool) ($isDraftPreview ?? $storePreview ?? false);
@endphp
@if($cardProducts !== [] || $privateCatalog)
<section class="section" data-website-product-cards>
    <div class="site-shell">
        <h2>{{ data_get($block ?? [], 'heading', 'Shop') }}</h2>
        @if($cardProducts === [])
            <p class="copy">Your catalog starts with your products and photos. Starter photographs are examples, not products for sale.</p>
            <a class="button" href="{{ route('managed-website.index', ['tenant' => $tenant->slug]) }}#retail-setup">Set up your catalog</a>
        @else
            @if($cardCollections !== [])
                <div class="card-grid" aria-label="Collections">
                @foreach($cardCollections as $card)
                    <article class="card">
                        @if($card['image'])@if($card['url'])<a href="{{ $card['url'] }}" aria-label="View {{ $card['title'] }}">@endif<img src="{{ $card['image'] }}" alt="{{ $card['alt'] }}" width="720" height="560" loading="lazy" decoding="async">@if($card['url'])</a>@endif @endif
                        @if($privateCatalog && $card['status'] !== 'active')<p class="eyebrow">Draft collection</p>@endif
                        <h3>@if($card['url'])<a href="{{ $card['url'] }}">{{ $card['title'] }}</a>@else{{ $card['title'] }}@endif</h3>
                    </article>
                @endforeach
                </div>
            @endif
            <div class="card-grid" aria-label="Products">
            @foreach($cardProducts as $card)
                <article class="card">
                    @if($card['image'])@if($card['url'])<a href="{{ $card['url'] }}" aria-label="View {{ $card['title'] }}">@endif<img src="{{ $card['image'] }}" alt="{{ $card['alt'] }}" width="720" height="560" loading="lazy" decoding="async">@if($card['url'])</a>@endif @else<p>{{ $privateCatalog ? 'Add your product photo.' : 'Photo coming soon' }}</p>@endif
                    @if($privateCatalog && $card['status'] !== 'active')<p class="eyebrow">Draft product</p>@endif
                    <h3>@if($card['url'])<a href="{{ $card['url'] }}">{{ $card['title'] }}</a>@else{{ $card['title'] }}@endif</h3>
                    @if($card['price'])<p>{{ $card['price'] }}</p>@endif
                    @if(! $card['available'] && ! $card['quote'])<p>Currently unavailable</p>@endif
                </article>
            @endforeach
            </div>
            @if(data_get($block ?? [], 'cta_url'))
                @php $catalogLink = $link(data_get($block, 'cta_url')); @endphp
                <p><a class="button" {!! $linkAttributes($catalogLink) !!}>{{ data_get($block, 'cta_label', 'View all') }}</a></p>
            @endif
        @endif
    </div>
</section>
@endif
