<x-shopify-embedded-shell :authorized="false" :store-label="'Shopify Admin'" :headline="'Context missing'" :subheadline="'This page must be opened from Shopify Admin'">
    <section class="customers-detail-card">
        <h3>Context Missing</h3>
        <p>{{ $message ?? 'The Shopify context needed to open this page was not supplied.' }}</p>
        <p>Please open the Modern Forestry Backstage app again inside Shopify Admin to continue.</p>
    </section>
</x-shopify-embedded-shell>
