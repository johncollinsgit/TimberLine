@php
    $sawyer = ($design ?? 'native') === 'sawyer';
    $canAdd = $cartEnabled && ($selectedVariant['available'] ?? false);
@endphp
<div class="retail-variant-picker" data-website-variant-picker data-cart-enabled="{{ $cartEnabled ? '1' : '0' }}">
    <link rel="stylesheet" href="{{ asset('css/website-retail.css') }}">
    <script src="{{ asset('js/website-retail.js') }}" defer></script>
    @if($variantChoices->isNotEmpty())
        <form method="GET" action="{{ ($productUrl ?? route('managed-website.store.products.show', ['handle' => $product->handle])) }}" class="retail-option-form" data-variant-choice-form>
            @if($storePreview ?? false)<input type="hidden" name="tenant" value="{{ $site->tenant->slug }}">@endif
            <label for="retail-variant">{{ $variantChoices->count() > 1 ? 'Choose an option' : 'Option' }}</label>
            <div class="retail-option-row">
                <select id="retail-variant" name="variant" aria-describedby="retail-availability" data-variant-choice>
                    @foreach($variantChoices as $choice)
                        <option value="{{ $choice['id'] }}" @selected($selectedVariant['id'] === $choice['id']) data-price="{{ $choice['price'] }}" data-compare-price="{{ $choice['compare_price'] }}" data-available="{{ $choice['available'] ? '1' : '0' }}" data-availability="{{ $choice['availability'] }}" data-maximum="{{ $choice['maximum'] }}" data-action="{{ $choice['action'] }}">{{ $choice['title'] }}{{ $choice['available'] ? '' : ' · Unavailable' }}</option>
                    @endforeach
                </select>
                <button type="submit" class="{{ $sawyer ? 'sn-btn sn-btn-light' : 'store-button' }}" data-variant-update>View option</button>
            </div>
        </form>
        <div class="retail-price {{ $sawyer ? 'sn-price' : 'store-price' }}" aria-live="polite" aria-atomic="true"><span data-variant-price>{{ $selectedVariant['price'] }}</span><s data-variant-compare @if($selectedVariant['compare_price'] === '') hidden @endif>{{ $selectedVariant['compare_price'] }}</s></div>
        <p id="retail-availability" class="retail-availability" role="status" data-variant-availability>{{ $selectedVariant['availability'] }}</p>
        @if($cartEnabled)
            <form method="POST" action="{{ $selectedVariant['action'] }}" data-variant-cart-form>
                @csrf
                <input type="hidden" name="product_id" value="{{ $product->id }}">
                <label for="retail-quantity">Quantity</label>
                <input id="retail-quantity" name="quantity" type="number" inputmode="numeric" min="1" max="{{ max(1, $selectedVariant['maximum']) }}" value="1" @disabled(! $canAdd) data-variant-quantity>
                <button class="{{ $sawyer ? 'sn-btn sn-add' : 'store-button' }}" type="submit" @disabled(! $canAdd) data-variant-add>{{ $sawyer ? 'Add to bag' : 'Add to cart' }}</button>
            </form>
            @if(! $checkoutReady)<p class="retail-shopping-note">You can save your bag. Online payment is not available yet. @if($contactUrl ?? null)<a href="{{ $contactUrl }}">Contact us to order.</a>@else Contact the business directly to order.@endif</p>@endif
        @else
            <p class="retail-shopping-note">Online shopping is not available on this address. @if($contactUrl ?? null)<a href="{{ $contactUrl }}">Contact us to order.</a>@else Contact the business directly to order.@endif</p>
        @endif
    @else
        <p class="retail-availability">Currently unavailable</p>
    @endif
</div>
