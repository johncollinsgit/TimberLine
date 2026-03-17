<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :store-label="$storeLabel"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :page-subnav="$pageSubnav ?? []"
    :page-actions="$pageActions"
>
    <div id="shopify-dashboard-root"></div>
    <script id="shopify-dashboard-bootstrap" type="application/json">
        {!! json_encode($dashboardBootstrap ?? [
            'authorized' => false,
            'status' => 'invalid_request',
            'storeLabel' => 'Shopify Admin',
            'links' => [],
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}
    </script>

    @vite('resources/js/shopify/dashboard.tsx')
</x-shopify-embedded-shell>
