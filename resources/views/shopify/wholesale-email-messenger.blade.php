<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :store-label="$storeLabel"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
>
    @if (! $authorized)
        <div class="fb-panel p-6 text-sm text-zinc-600">Open Email Messenger from the verified Shopify Admin wholesale app.</div>
    @else
        <div id="wholesale-email-messenger-root" aria-live="polite"></div>
        <script id="wholesale-email-messenger-bootstrap" type="application/json">{!! json_encode($wholesaleMessengerBootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
        @vite('resources/js/shopify/wholesale-messaging.tsx')
    @endif
</x-shopify-embedded-shell>
