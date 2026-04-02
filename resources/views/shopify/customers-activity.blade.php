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

        .customers-tab-panel table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }

        .customers-tab-panel th,
        .customers-tab-panel td {
            text-align: left;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            padding: 10px 8px;
            font-size: 13px;
        }

        .customers-tab-panel th {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: rgba(15, 23, 42, 0.56);
        }

        .customers-tab-empty {
            text-align: center;
            padding: 14px 10px;
            color: rgba(15, 23, 42, 0.58);
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

    <section class="customers-tab-panel" aria-label="Customer activity">
        <h2>Activity</h2>
        <p>Review recent customer events.</p>

        <table>
            <thead>
                <tr>
                    <th>When</th>
                    <th>Customer</th>
                    <th>Event</th>
                    <th>Next action</th>
                </tr>
            </thead>
            <tbody>
                @if($importState !== 'imported')
                    <tr>
                        <td colspan="4" class="customers-tab-empty">
                            No activity yet. Sync customers to load recent events.
                            <br />
                            <a class="customers-tab-link" href="{{ $embeddedUrl(route('shopify.app.integrations', [], false)) }}">Sync customers</a>
                        </td>
                    </tr>
                @else
                    <tr>
                        <td colspan="4" class="customers-tab-empty">
                            No recent activity yet.
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>
    </section>
</x-shopify.customers-layout>
