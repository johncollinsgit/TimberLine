<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    headline="Marketing Results"
    subheadline="See Everbranch-attributed revenue, refunds, messaging costs, and return by channel."
    :app-navigation="$appNavigation"
    :page-subnav="[]"
    :page-actions="[]"
>
    <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6">
        @if($authorized && $reportingEnabled)
            <x-marketing-results-dashboard :results="$marketingResults" />
        @else
            <div class="border-l-4 border-amber-500 bg-amber-50 p-4 text-sm text-amber-950">Reporting is not available for this store yet.</div>
        @endif
    </div>
</x-shopify-embedded-shell>
