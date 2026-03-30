@props([
    'authorized' => false,
    'shopifyApiKey' => null,
    'shopDomain' => null,
    'host' => null,
    'storeLabel' => null,
    'headline' => null,
    'subheadline' => null,
    'appNavigation' => [],
    'customerSubnav' => [],
    'pageActions' => [],
    'merchantJourney' => [],
])

<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :store-label="$storeLabel"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :page-subnav="$customerSubnav"
    :page-actions="$pageActions"
>
    @php
        $moduleStates = is_array($appNavigation['moduleStates'] ?? null) ? $appNavigation['moduleStates'] : [];
        $activeSubnav = collect($customerSubnav ?? [])->first(
            fn (array $item): bool => ! empty($item['active'])
        );
        $activeModuleState = is_array($activeSubnav['module_state'] ?? null)
            ? $activeSubnav['module_state']
            : (is_array($moduleStates['customers'] ?? null) ? $moduleStates['customers'] : null);
        $activeModuleUi = \App\Support\Tenancy\TenantModuleUi::present($activeModuleState, 'Customers');
        $lockedModule = ($activeModuleUi['ui_state'] ?? '') === 'locked';
        $comingSoonModule = ($activeModuleUi['ui_state'] ?? '') === 'coming_soon';
        $journey = is_array($merchantJourney ?? null) ? $merchantJourney : [];
        $importSummary = is_array($journey['import_summary'] ?? null) ? $journey['import_summary'] : [];
        $importCta = is_array($importSummary['cta'] ?? null) ? $importSummary['cta'] : ['label' => 'Import Customers', 'href' => route('shopify.app.integrations', [], false)];
        $customerSummary = is_array($journey['customer_summary'] ?? null) ? $journey['customer_summary'] : ['total_profiles' => 0, 'reachable_profiles' => 0, 'linked_external_profiles' => 0];
        $embeddedContext = \App\Support\Shopify\ShopifyEmbeddedContextQuery::fromRequest(
            request(),
            filled($host ?? null) ? (string) $host : null
        );
        $embeddedUrl = static fn (string $url): string => \App\Support\Shopify\ShopifyEmbeddedContextQuery::appendToUrl($url, $embeddedContext);
    @endphp

    <section class="customers-stack">
        <article class="customers-surface customers-surface--journey" aria-label="Customer setup status">
            <h2>Customer setup status</h2>
            <p>{{ $importSummary['description'] ?? 'Import customers first so this workspace can power customer management actions.' }}</p>
            <div class="start-here-meta">
                <span class="start-here-pill">Import · {{ $importSummary['label'] ?? 'Not started' }}</span>
                <span class="start-here-pill">Profiles · {{ number_format((int) ($customerSummary['total_profiles'] ?? 0)) }}</span>
                <span class="start-here-pill">Reachable · {{ number_format((int) ($customerSummary['reachable_profiles'] ?? 0)) }}</span>
            </div>
            <p class="customers-muted-note">{{ $importSummary['progress_note'] ?? 'No import has run yet for this store context.' }}</p>
            <div class="plans-meta">
                <a class="start-here-action-link" href="{{ $embeddedUrl((string) ($importCta['href'] ?? route('shopify.app.integrations', [], false))) }}">{{ $importCta['label'] ?? 'Import Customers' }}</a>
                <a class="start-here-action-link" href="{{ $embeddedUrl(route('shopify.app.start', [], false)) }}">Open setup checklist</a>
            </div>
        </article>

        @if(is_array($activeModuleState))
            <x-tenancy.module-state-card
                :module-state="$activeModuleState"
                :title="$activeModuleUi['label']"
                description="Customer module visibility and readiness follow tenant entitlement state."
            />
        @endif

        @if($lockedModule || $comingSoonModule)
            <x-tenancy.module-upgrade-prompt
                :module-state="$activeModuleState"
                :cta-href="route('marketing.overview')"
                cta-label="Request customer module access"
                coming-soon-cta-label="Follow roadmap"
            />
        @endif

        @if(! $lockedModule)
            {{ $slot }}
        @endif
    </section>
</x-shopify-embedded-shell>
