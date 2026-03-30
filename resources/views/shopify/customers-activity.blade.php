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
        $customerSummary = is_array($journey['customer_summary'] ?? null) ? $journey['customer_summary'] : ['total_profiles' => 0, 'reachable_profiles' => 0, 'linked_external_profiles' => 0];

        $embeddedContext = \App\Support\Shopify\ShopifyEmbeddedContextQuery::fromRequest(
            request(),
            filled($host ?? null) ? (string) $host : null
        );
        $embeddedUrl = static fn (string $url): string => \App\Support\Shopify\ShopifyEmbeddedContextQuery::appendToUrl($url, $embeddedContext);
    @endphp

    <section class="customers-surface">
        <h2>Customer Activity</h2>
        <p>Track the most important customer events so your team knows who to follow up with and what to do next.</p>
        <div class="start-here-meta">
            <span class="start-here-pill">Import · {{ $importSummary['label'] ?? 'Not started' }}</span>
            <span class="start-here-pill">Profiles · {{ number_format((int) ($customerSummary['total_profiles'] ?? 0)) }}</span>
            <span class="start-here-pill">Reachable · {{ number_format((int) ($customerSummary['reachable_profiles'] ?? 0)) }}</span>
        </div>
    </section>

    <section class="customers-activity-shell" aria-label="Customer activity feed">
        <table>
            <thead>
                <tr>
                    <th>When</th>
                    <th>Customer</th>
                    <th>Activity Type</th>
                    <th>Business Meaning</th>
                    <th>Recommended Action</th>
                </tr>
            </thead>
            <tbody>
                @if($importState !== 'imported')
                    <tr>
                        <td colspan="5" class="customers-activity-empty">
                            Import customers to start seeing timeline activity. Once import is complete, this view will surface lifecycle and engagement events.
                            <br />
                            <a class="start-here-action-link" href="{{ $embeddedUrl(route('shopify.app.integrations', [], false)) }}">Import customers</a>
                        </td>
                    </tr>
                @else
                    <tr>
                        <td colspan="5" class="customers-activity-empty">
                            Activity feed scaffolding is ready. Next step is wiring live event rows into this timeline while preserving tenant-safe filters and visibility.
                            <br />
                            <a class="start-here-action-link" href="{{ $embeddedUrl(route('shopify.app.customers.manage', [], false)) }}">Open customers workspace</a>
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>
    </section>
</x-shopify.customers-layout>
