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
    @php
        $moduleStates = is_array($appNavigation['moduleStates'] ?? null) ? $appNavigation['moduleStates'] : [];
        $moduleCardOrder = [
            'dashboard',
            'customers',
            'rewards',
            'birthdays',
            'activity',
            'questions',
            'referrals',
            'vip',
            'notifications',
            'settings',
        ];

        $journey = is_array($merchantJourney ?? null) ? $merchantJourney : [];
        $plan = is_array($journey['plan'] ?? null) ? $journey['plan'] : ['label' => 'Starter'];
        $customerSummary = is_array($journey['customer_summary'] ?? null) ? $journey['customer_summary'] : ['total_profiles' => 0, 'reachable_profiles' => 0, 'linked_external_profiles' => 0];
        $importSummary = is_array($journey['import_summary'] ?? null) ? $journey['import_summary'] : [];
        $importState = (string) ($importSummary['state'] ?? 'not_started');
        $activeNow = is_array($journey['active_now'] ?? null) ? $journey['active_now'] : [];
        $availableNext = is_array($journey['available_next'] ?? null) ? $journey['available_next'] : [];
        $purchasable = is_array($journey['purchasable'] ?? null) ? $journey['purchasable'] : [];
        $recommendedActions = is_array($journey['recommended_actions'] ?? null) ? $journey['recommended_actions'] : [];
        $latestRun = is_array($importSummary['latest_run'] ?? null) ? $importSummary['latest_run'] : null;
        $checklist = is_array($journey['checklist'] ?? null) ? $journey['checklist'] : [];
        $checklistCounts = is_array($checklist['counts'] ?? null) ? $checklist['counts'] : ['active' => 0, 'setup' => 0, 'locked' => 0, 'coming_soon' => 0];

        $nextStepTitle = 'Import your customers';
        $nextStepDescription = 'Import customer data first so customer management, segmentation, and lifecycle actions become useful immediately.';
        $nextStepCta = ['label' => 'Import Customers', 'href' => route('shopify.app.integrations', [], false)];
        $nextStepSecondary = ['label' => 'Open Start Here', 'href' => route('shopify.app.start', [], false)];

        if ($importState === 'in_progress') {
            $nextStepTitle = 'Monitor your import';
            $nextStepDescription = 'Your customer import is running. As soon as it completes, move into customer management and activation workflows.';
            $nextStepCta = ['label' => 'View Import Status', 'href' => route('shopify.app.integrations', [], false)];
            $nextStepSecondary = ['label' => 'Review Setup Checklist', 'href' => route('shopify.app.start', [], false)];
        } elseif ($importState === 'attention') {
            $nextStepTitle = 'Fix import and retry';
            $nextStepDescription = 'The latest import needs attention. Resolve it first so your customer workspace is reliable.';
            $nextStepCta = ['label' => 'Open Import Options', 'href' => route('shopify.app.integrations', [], false)];
            $nextStepSecondary = ['label' => 'Open Start Here', 'href' => route('shopify.app.start', [], false)];
        } elseif ($importState === 'imported' && $availableNext !== []) {
            $nextStepTitle = 'Complete setup for included modules';
            $nextStepDescription = 'Customers are imported. Finish setup for modules that are already included in your plan to unlock more value.';
            $nextStepCta = ['label' => 'Review Setup Checklist', 'href' => route('shopify.app.start', [], false)];
            $nextStepSecondary = ['label' => 'Open Customers', 'href' => route('shopify.app.customers.manage', [], false)];
        } elseif ($importState === 'imported') {
            $nextStepTitle = 'Activate customer workflows';
            $nextStepDescription = 'Your customer data is ready. Start using customer views, lifecycle actions, and campaign-ready segments.';
            $nextStepCta = ['label' => 'Open Customers', 'href' => route('shopify.app.customers.manage', [], false)];
            $nextStepSecondary = ['label' => 'Review Plans & Add-ons', 'href' => route('shopify.app.plans', [], false)];
        }

        $embeddedContext = \App\Support\Shopify\ShopifyEmbeddedContextQuery::fromRequest(
            request(),
            filled($host ?? null) ? (string) $host : null
        );
        $embeddedUrl = static fn (string $url): string => \App\Support\Shopify\ShopifyEmbeddedContextQuery::appendToUrl($url, $embeddedContext);
    @endphp

    <section class="merchant-landing" data-merchant-landing="true" data-import-state="{{ $importState }}">
        <article class="merchant-landing-hero" aria-label="Merchant orientation">
            <div class="merchant-landing-hero__content">
                <p class="merchant-landing-kicker">Merchant Home</p>
                <h2 class="merchant-landing-title">Run customer growth from one organized workspace</h2>
                <p class="merchant-landing-copy">
                    Forestry Backstage helps you import customers, manage customer profiles, and activate growth modules with a clear setup path.
                </p>
                <ul class="merchant-landing-list" aria-label="What this app does">
                    <li>Import customers and see setup status instantly.</li>
                    <li>Manage customer records, balances, and lifecycle actions in one place.</li>
                    <li>Understand what is active now, what needs setup, and what can be unlocked next.</li>
                </ul>
                <div class="merchant-landing-actions">
                    <a class="fb-btn fb-btn-primary" href="{{ $embeddedUrl($nextStepCta['href']) }}">
                        {{ $nextStepCta['label'] }}
                    </a>
                    <a class="fb-btn fb-btn-secondary" href="{{ $embeddedUrl($nextStepSecondary['href']) }}">{{ $nextStepSecondary['label'] }}</a>
                </div>
            </div>

            <div class="merchant-landing-hero__status">
                <p class="merchant-landing-kicker">Next Step</p>
                <h3 class="merchant-landing-panel__title">{{ $nextStepTitle }}</h3>
                <p class="merchant-landing-copy">{{ $nextStepDescription }}</p>

                <div class="merchant-landing-status-chip merchant-landing-status-chip--{{ $importState }}">
                    Import Status: {{ $importSummary['label'] ?? 'Not started' }}
                </div>
                <p class="merchant-landing-note">{{ $importSummary['progress_note'] ?? 'No import has run yet for this store context.' }}</p>

                @if($latestRun)
                    <p class="merchant-landing-note">
                        Latest run: {{ $latestRun['source_label'] ?? 'Import' }} · {{ $latestRun['status_label'] ?? 'Unknown' }}
                        @if(filled($latestRun['finished_at_display'] ?? null))
                            · {{ $latestRun['finished_at_display'] }}
                        @elseif(filled($latestRun['started_at_display'] ?? null))
                            · {{ $latestRun['started_at_display'] }}
                        @endif
                    </p>
                @endif

                <div class="merchant-landing-status-list" aria-label="Setup snapshot">
                    <p><strong>{{ (int) ($checklistCounts['active'] ?? 0) }}</strong> active now</p>
                    <p><strong>{{ (int) ($checklistCounts['setup'] ?? 0) }}</strong> need setup</p>
                    <p><strong>{{ (int) ($checklistCounts['locked'] ?? 0) }}</strong> available to unlock</p>
                </div>
            </div>
        </article>

        <div class="merchant-landing-metrics" aria-label="Customer and setup snapshot">
            <article class="merchant-landing-metric">
                <p class="merchant-landing-metric__label">Customer Profiles</p>
                <p class="merchant-landing-metric__value">{{ number_format((int) ($customerSummary['total_profiles'] ?? 0)) }}</p>
                <p class="merchant-landing-metric__detail">Profiles currently available to manage in this store context.</p>
            </article>
            <article class="merchant-landing-metric">
                <p class="merchant-landing-metric__label">Reachable Profiles</p>
                <p class="merchant-landing-metric__value">{{ number_format((int) ($customerSummary['reachable_profiles'] ?? 0)) }}</p>
                <p class="merchant-landing-metric__detail">Profiles with an email or phone ready for customer messaging.</p>
            </article>
            <article class="merchant-landing-metric">
                <p class="merchant-landing-metric__label">Linked Source Records</p>
                <p class="merchant-landing-metric__value">{{ number_format((int) ($customerSummary['linked_external_profiles'] ?? 0)) }}</p>
                <p class="merchant-landing-metric__detail">Connected source records attached to canonical customer profiles.</p>
            </article>
            <article class="merchant-landing-metric">
                <p class="merchant-landing-metric__label">Current Plan</p>
                <p class="merchant-landing-metric__value">{{ $plan['label'] ?? 'Starter' }}</p>
                <p class="merchant-landing-metric__detail">Use plan and module state to prioritize what to activate next.</p>
            </article>
        </div>

        <article class="merchant-landing-panel" aria-label="After import value flow">
            <h3 class="merchant-landing-panel__title">What Happens After Import</h3>
            <div class="merchant-landing-steps">
                <div class="merchant-landing-step">
                    <p class="merchant-landing-step__title">1. Confirm customer data quality</p>
                    <p class="merchant-landing-panel__copy">Review customer records, identity links, and reachable channels.</p>
                </div>
                <div class="merchant-landing-step">
                    <p class="merchant-landing-step__title">2. Use customer management tools</p>
                    <p class="merchant-landing-panel__copy">Segment customers, review status signals, and run lifecycle actions.</p>
                </div>
                <div class="merchant-landing-step">
                    <p class="merchant-landing-step__title">3. Expand with add-ons</p>
                    <p class="merchant-landing-panel__copy">Unlock premium modules when your current setup is producing clear value.</p>
                </div>
            </div>
        </article>

        <div class="merchant-landing-columns">
            <article class="merchant-landing-panel" aria-label="Available now">
                <h3 class="merchant-landing-panel__title">Available Now</h3>
                <p class="merchant-landing-panel__copy">Modules currently active for your store.</p>
                <div class="merchant-landing-panel__list">
                    @forelse(array_slice($activeNow, 0, 5) as $module)
                        <div class="merchant-landing-panel__row">
                            <span>{{ $module['label'] ?? 'Module' }}</span>
                            <x-tenancy.module-state-badge :module-state="$module" size="sm" compact />
                        </div>
                    @empty
                        <p class="merchant-landing-note">No active modules are visible yet.</p>
                    @endforelse
                </div>
                <a class="start-here-action-link" href="{{ $embeddedUrl(route('shopify.app.customers.manage', [], false)) }}">Open Customers</a>
            </article>

            <article class="merchant-landing-panel" aria-label="Setup next">
                <h3 class="merchant-landing-panel__title">Setup Next</h3>
                <p class="merchant-landing-panel__copy">Included modules that still need setup work.</p>
                <div class="merchant-landing-panel__list">
                    @forelse(array_slice($availableNext, 0, 5) as $module)
                        <div class="merchant-landing-panel__row">
                            <span>{{ $module['label'] ?? 'Module' }}</span>
                            <x-tenancy.module-state-badge :module-state="$module" size="sm" compact />
                        </div>
                    @empty
                        <p class="merchant-landing-note">Everything included in your current plan is configured.</p>
                    @endforelse
                </div>
                <a class="start-here-action-link" href="{{ $embeddedUrl(route('shopify.app.start', [], false)) }}">View Setup Checklist</a>
            </article>

            <article class="merchant-landing-panel" aria-label="Unlock next">
                <h3 class="merchant-landing-panel__title">Unlock Next</h3>
                <p class="merchant-landing-panel__copy">Purchasable capabilities you can add when ready.</p>
                <div class="merchant-landing-panel__list">
                    @forelse(array_slice($purchasable, 0, 5) as $module)
                        <div class="merchant-landing-panel__row">
                            <span>{{ $module['label'] ?? 'Module' }}</span>
                            <x-tenancy.module-state-badge :module-state="$module" size="sm" compact />
                        </div>
                    @empty
                        <p class="merchant-landing-note">No purchasable modules are currently highlighted.</p>
                    @endforelse
                </div>
                <a class="start-here-action-link" href="{{ $embeddedUrl(route('shopify.app.plans', [], false)) }}">Review Plans & Add-ons</a>
            </article>
        </div>

        @if($recommendedActions !== [])
            <article class="merchant-landing-panel" aria-label="Recommended actions">
                <h3 class="merchant-landing-panel__title">Recommended Actions</h3>
                <div class="merchant-landing-panel__list">
                    @foreach(array_slice($recommendedActions, 0, 4) as $action)
                        <div class="merchant-landing-panel__action">
                            <div>
                                <p class="merchant-landing-panel__action-title">{{ $action['title'] ?? 'Next step' }}</p>
                                <p class="merchant-landing-panel__copy">{{ $action['description'] ?? '' }}</p>
                            </div>
                            <a class="start-here-action-link" href="{{ $embeddedUrl((string) ($action['href'] ?? route('shopify.app.start', [], false))) }}">Open</a>
                        </div>
                    @endforeach
                </div>
            </article>
        @endif
    </section>

    @if($moduleStates !== [])
        <section class="embedded-module-experience" data-module-experience="dashboard-home">
            <x-tenancy.module-setup-checklist
                :module-states="$moduleStates"
                :module-order="$moduleCardOrder"
                title="Module setup checklist"
                subtitle="Track what is active now, what needs setup next, and what is available to unlock."
                :cta-href="route('shopify.app.plans', [], false)"
            />

            <div class="embedded-module-experience__grid" aria-label="Module state cards">
                @foreach($moduleCardOrder as $moduleKey)
                    @continue(! isset($moduleStates[$moduleKey]) || ! is_array($moduleStates[$moduleKey]))
                    <x-tenancy.module-state-card :module-state="$moduleStates[$moduleKey]" />
                @endforeach
            </div>
        </section>
    @endif

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
