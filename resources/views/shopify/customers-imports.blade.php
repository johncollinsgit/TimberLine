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
        $journey = is_array($merchantJourney ?? null) ? $merchantJourney : [];
        $importSummary = is_array($journey['import_summary'] ?? null) ? $journey['import_summary'] : [];
        $importState = (string) ($importSummary['state'] ?? 'not_started');
        $syncIsStale = (bool) ($importSummary['is_stale'] ?? false);
        $syncActionLabel = match ($importState) {
            'imported' => $syncIsStale ? 'Retry sync' : 'View sync status',
            'attention' => 'Retry sync',
            'in_progress' => 'View sync status',
            default => 'Sync customers',
        };
        /** @var \App\Services\Shopify\ShopifyEmbeddedUrlGenerator $embeddedUrls */
        $embeddedUrls = app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class);
        $embeddedContext = $embeddedUrls->contextQuery(
            request(),
            filled($host ?? null) ? (string) $host : null
        );
        $embeddedUrl = static fn (string $url): string => $embeddedUrls->append($url, $embeddedContext);
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

        .customers-tab-meta {
            font-size: 12px;
            color: rgba(15, 23, 42, 0.56);
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

    <section class="customers-tab-panel" aria-label="Customer imports">
        <h2>Imports</h2>
        <p>Review sync status and run sync when needed.</p>
        <p class="customers-tab-meta">Current sync status: {{ (string) ($importSummary['label'] ?? 'Not synced') }}</p>
        <a class="customers-tab-link" href="{{ $embeddedUrl(route('shopify.app.integrations', [], false)) }}">{{ $syncActionLabel }}</a>
    </section>
</x-shopify.customers-layout>
