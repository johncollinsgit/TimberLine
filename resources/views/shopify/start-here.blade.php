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
    :page-actions="$pageActions ?? []"
>
    @php
        $payload = is_array($onboardingPayload ?? null) ? $onboardingPayload : [];
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        $moduleStates = is_array($payload['module_states'] ?? null) ? $payload['module_states'] : [];
        $moduleOrder = is_array($payload['module_order'] ?? null) ? $payload['module_order'] : [];
        $checklist = is_array($payload['checklist'] ?? null) ? $payload['checklist'] : \App\Support\Tenancy\TenantModuleUi::checklist($moduleStates, $moduleOrder);
        $plan = is_array($payload['plan'] ?? null) ? $payload['plan'] : ['label' => 'Unknown plan', 'track' => 'shopify', 'operating_mode' => 'shopify'];
        $recommendedActions = is_array($payload['recommended_actions'] ?? null) ? $payload['recommended_actions'] : [];
        $comingSoonModules = array_values((array) ($checklist['coming_soon'] ?? []));

        $journey = is_array($merchantJourney ?? null) ? $merchantJourney : [];
        $importSummary = is_array($journey['import_summary'] ?? null) ? $journey['import_summary'] : [];
        $importCta = is_array($importSummary['cta'] ?? null) ? $importSummary['cta'] : ['label' => 'Import Customers', 'href' => route('shopify.app.integrations', [], false)];
        $activeNow = is_array($journey['active_now'] ?? null) ? $journey['active_now'] : [];
        $availableNext = is_array($journey['available_next'] ?? null) ? $journey['available_next'] : [];
        $purchasable = is_array($journey['purchasable'] ?? null) ? $journey['purchasable'] : [];

        $checklistCounts = is_array($checklist['counts'] ?? null) ? $checklist['counts'] : ['active' => 0, 'setup' => 0, 'locked' => 0, 'coming_soon' => 0];

        /** @var \App\Services\Shopify\ShopifyEmbeddedUrlGenerator $embeddedUrls */
        $embeddedUrls = app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class);
        $embeddedContext = $embeddedUrls->contextQuery(
            request(),
            filled($host ?? null) ? (string) $host : null
        );
        $embeddedUrl = static fn (string $url): string => $embeddedUrls->append($url, $embeddedContext);
    @endphp

    <section class="start-here-shell" data-onboarding-surface="true">
        <article class="start-here-panel" aria-label="Onboarding orientation">
            <h2 class="start-here-headline">{{ $content['welcome_title'] ?? 'Start Here' }}</h2>
            <p class="start-here-copy">{{ $content['welcome_body'] ?? 'Use this page to complete setup quickly and move into customer value workflows.' }}</p>

            <div class="start-here-meta" aria-label="Current setup profile">
                <span class="start-here-pill">Plan · {{ $plan['label'] ?? 'Unknown' }}</span>
                <span class="start-here-pill">Active now · {{ (int) ($checklistCounts['active'] ?? 0) }}</span>
                <span class="start-here-pill">Setup next · {{ (int) ($checklistCounts['setup'] ?? 0) }}</span>
                <span class="start-here-pill">Unlock next · {{ (int) ($checklistCounts['locked'] ?? 0) }}</span>
            </div>

            <div class="start-here-action is-journey">
                <p class="start-here-action-title">Customer import status: {{ $importSummary['label'] ?? 'Not started' }}</p>
                <p class="start-here-action-copy">{{ $importSummary['description'] ?? 'Import customers first to unlock customer management capabilities.' }}</p>
                <p class="start-here-action-copy">{{ $importSummary['progress_note'] ?? 'No import has run yet for this store context.' }}</p>
                <a class="start-here-action-link" href="{{ $embeddedUrl((string) ($importCta['href'] ?? route('shopify.app.integrations', [], false))) }}">
                    {{ $importCta['label'] ?? 'Import Customers' }}
                </a>
            </div>

            <ul class="start-here-list" aria-label="Orientation notes">
                <li>Complete customer import first so the rest of this workspace becomes actionable.</li>
                <li>Finish setup for included modules before evaluating upgrades.</li>
                <li>Use plans and add-ons when you are ready to unlock additional capabilities.</li>
            </ul>
        </article>

        <div class="start-here-grid">
            <x-tenancy.module-setup-checklist
                :module-states="$moduleStates"
                :module-order="$moduleOrder"
                title="Setup Checklist"
                subtitle="Use this checklist to complete included setup items first, then evaluate upgrades."
                :cta-href="route('shopify.app.plans', [], false)"
            />

            <article class="start-here-panel" aria-label="Recommended actions">
                <h3 class="start-here-headline">Recommended Next Actions</h3>
                <div class="start-here-actions">
                    @foreach($recommendedActions as $action)
                        <div class="start-here-action">
                            <p class="start-here-action-title">
                                <span>{{ $action['title'] ?? 'Next step' }}</span>
                                @if(is_array($action['module_state'] ?? null))
                                    <x-tenancy.module-state-badge :module-state="$action['module_state']" size="sm" compact />
                                @endif
                            </p>
                            <p class="start-here-action-copy">{{ $action['description'] ?? '' }}</p>
                            @if(filled($action['href'] ?? null))
                                <a class="start-here-action-link" href="{{ $embeddedUrl((string) $action['href']) }}">Open</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </article>
        </div>

        <div class="start-here-card-grid" aria-label="Capability state groups">
            <article class="start-here-panel">
                <h3 class="start-here-headline">Available Now</h3>
                @forelse(array_slice($activeNow, 0, 5) as $module)
                    <div class="start-here-action">
                        <p class="start-here-action-title">
                            <span>{{ $module['label'] ?? 'Module' }}</span>
                            <x-tenancy.module-state-badge :module-state="$module" size="sm" compact />
                        </p>
                    </div>
                @empty
                    <p class="start-here-copy">No active modules are visible yet.</p>
                @endforelse
            </article>

            <article class="start-here-panel">
                <h3 class="start-here-headline">Setup Next</h3>
                @forelse(array_slice($availableNext, 0, 5) as $module)
                    <div class="start-here-action">
                        <p class="start-here-action-title">
                            <span>{{ $module['label'] ?? 'Module' }}</span>
                            <x-tenancy.module-state-badge :module-state="$module" size="sm" compact />
                        </p>
                    </div>
                @empty
                    <p class="start-here-copy">Included modules are already configured.</p>
                @endforelse
            </article>

            <article class="start-here-panel">
                <h3 class="start-here-headline">Unlock Next</h3>
                @forelse(array_slice($purchasable, 0, 5) as $module)
                    <div class="start-here-action">
                        <p class="start-here-action-title">
                            <span>{{ $module['label'] ?? 'Module' }}</span>
                            <x-tenancy.module-state-badge :module-state="$module" size="sm" compact />
                        </p>
                    </div>
                @empty
                    <p class="start-here-copy">No upgrade candidates are highlighted right now.</p>
                @endforelse
                <a class="start-here-action-link" href="{{ $embeddedUrl(route('shopify.app.plans', [], false)) }}">Review Plans & Add-ons</a>
            </article>
        </div>

        @if($comingSoonModules !== [])
            <article class="start-here-panel" aria-label="Coming soon modules">
                <h3 class="start-here-headline">Coming Soon</h3>
                <div class="start-here-card-grid">
                    @foreach(array_slice($comingSoonModules, 0, 6) as $module)
                        <x-tenancy.module-state-card :module-state="$module" />
                    @endforeach
                </div>
            </article>
        @endif

        <article class="start-here-panel" aria-label="Future prompts">
            <h3 class="start-here-headline">Looking Ahead</h3>
            <ul class="start-here-list">
                <li>Keep import reliability high before expanding into more workflows.</li>
                <li>Treat premium modules as a clear next step after your core setup is stable.</li>
                <li>Use this page as your source of truth for setup progress and readiness.</li>
            </ul>
        </article>
    </section>
</x-shopify-embedded-shell>
