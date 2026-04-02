<x-shopify.customers-layout
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :store-label="$storeLabel"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :customer-subnav="$pageSubnav"
    :page-actions="$pageActions"
    :merchant-journey="$merchantJourney ?? []"
>
    @php
        $embeddedContext = \App\Support\Shopify\ShopifyEmbeddedContextQuery::fromRequest(
            request(),
            filled($host ?? null) ? (string) $host : null
        );
        $embeddedUrl = static fn (string $url): string => \App\Support\Shopify\ShopifyEmbeddedContextQuery::appendToUrl($url, $embeddedContext);
    @endphp

    <style>
        .customers-tab-panel {
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 12px;
            background: #fff;
            padding: 16px;
            display: grid;
            gap: 10px;
        }

        .customers-tab-panel h2 {
            margin: 0;
            font-size: 1rem;
            color: #0f172a;
        }

        .customers-tab-panel p {
            margin: 0;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.62);
        }

        .customers-tab-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            min-height: 34px;
            border-radius: 8px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            text-decoration: none;
            color: #0f172a;
            font-size: 12px;
            font-weight: 600;
            padding: 0 12px;
        }
    </style>

    <section class="customers-tab-panel" aria-label="Customer segments">
        <h2>Segments</h2>
        <p>Create and review saved customer groups.</p>
        <a class="customers-tab-link" href="{{ $embeddedUrl(route('shopify.app.customers.manage', [], false)) }}">Open all customers</a>
    </section>
</x-shopify.customers-layout>
