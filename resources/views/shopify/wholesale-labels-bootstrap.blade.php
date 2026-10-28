<x-shopify-embedded-shell :authorized="$authorized" :shopify-api-key="$shopifyApiKey" :shop-domain="$shopDomain" :host="$host" :store-label="$storeLabel" :headline="$headline" :subheadline="$subheadline" :app-navigation="$appNavigation" :page-actions="$pageActions">
<p id="label-access-status" role="status">Checking your artwork manager access…</p>
@vite('resources/js/wholesale-label-bootstrap.js')
</x-shopify-embedded-shell>
