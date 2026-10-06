<x-shopify-embedded-shell :authorized="$authorized" :shopify-api-key="$shopifyApiKey" :shop-domain="$shopDomain" :host="$host" :store-label="$storeLabel" :headline="$headline" :subheadline="$subheadline" :app-navigation="$appNavigation">
    @if (! $authorized)
        <div class="fb-panel p-6 text-sm text-zinc-600">Open Birthday Email Designer from the verified Shopify Admin rewards app.</div>
    @else
        <div id="birthday-email-composer-root" aria-live="polite"></div>
        <script id="birthday-email-composer-bootstrap" type="application/json">{!! json_encode($birthdayEmailComposerBootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
        @vite('resources/js/shopify/birthday-email-composer.tsx')
    @endif
</x-shopify-embedded-shell>
