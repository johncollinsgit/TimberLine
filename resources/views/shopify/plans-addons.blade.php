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
        $payload = is_array($plansPayload ?? null) ? $plansPayload : [];
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        $currentPlan = is_array($payload['current_plan'] ?? null) ? $payload['current_plan'] : ['label' => 'Unknown', 'track' => 'shopify', 'operating_mode' => 'shopify'];
        $planCards = is_array($payload['plan_cards'] ?? null) ? $payload['plan_cards'] : [];
        $addonCards = is_array($payload['addon_cards'] ?? null) ? $payload['addon_cards'] : [];
        $currentPlanModules = is_array($payload['current_plan_modules'] ?? null) ? $payload['current_plan_modules'] : [];
        $lockedModules = is_array($payload['locked_modules'] ?? null) ? $payload['locked_modules'] : [];
        $addOnCapableModules = is_array($payload['add_on_capable_modules'] ?? null) ? $payload['add_on_capable_modules'] : [];
        $upgradeCtas = is_array($content['upgrade_ctas'] ?? null) ? $content['upgrade_ctas'] : [];

        $journey = is_array($merchantJourney ?? null) ? $merchantJourney : [];
        $importSummary = is_array($journey['import_summary'] ?? null) ? $journey['import_summary'] : [];
        $activeNow = is_array($journey['active_now'] ?? null) ? $journey['active_now'] : [];
        $availableNext = is_array($journey['available_next'] ?? null) ? $journey['available_next'] : [];
        $purchasable = is_array($journey['purchasable'] ?? null) ? $journey['purchasable'] : [];

        $embeddedContext = \App\Support\Shopify\ShopifyEmbeddedContextQuery::fromRequest(
            request(),
            filled($host ?? null) ? (string) $host : null
        );
        $embeddedUrl = static fn (string $url): string => \App\Support\Shopify\ShopifyEmbeddedContextQuery::appendToUrl($url, $embeddedContext);

        $importCta = is_array($importSummary['cta'] ?? null) ? $importSummary['cta'] : ['label' => 'Import Customers', 'href' => route('shopify.app.integrations', [], false)];
        $activeNowCount = count($activeNow);
        $setupNextCount = count($availableNext);
        $unlockNextCount = count($purchasable);
    @endphp

    <section class="plans-shell" data-plans-surface="true">
        <article class="plans-panel" aria-label="Current plan summary">
            <h2 class="plans-title">Current Plan</h2>
            <p class="plans-copy">{{ $content['subtitle'] ?? 'Review what is included now, what needs setup, and what you can unlock next.' }}</p>
            <div class="plans-meta">
                <span class="plans-pill">Plan · {{ $currentPlan['label'] ?? 'Unknown' }}</span>
                <span class="plans-pill">Active now · {{ $activeNowCount }}</span>
                <span class="plans-pill">Setup next · {{ $setupNextCount }}</span>
                <span class="plans-pill">Unlock next · {{ $unlockNextCount }}</span>
            </div>
            <p class="plans-copy">Customer import status: {{ $importSummary['label'] ?? 'Not started' }}. {{ $importSummary['progress_note'] ?? 'Import customers to unlock full customer workflows.' }}</p>
            <div class="plans-meta">
                <a class="plans-link" href="{{ $embeddedUrl((string) ($importCta['href'] ?? route('shopify.app.integrations', [], false))) }}">{{ $importCta['label'] ?? 'Import Customers' }}</a>
                <a class="plans-link" href="{{ $embeddedUrl(route('shopify.app.start', [], false)) }}">Open Setup Checklist</a>
                @if(is_array($upgradeCtas['primary'] ?? null) && filled($upgradeCtas['primary']['href'] ?? null))
                    <a class="plans-link" href="{{ $upgradeCtas['primary']['href'] }}">{{ $upgradeCtas['primary']['label'] ?? 'Request upgrade' }}</a>
                @endif
            </div>
        </article>

        <div class="plans-state-grid" aria-label="Capability visibility">
            <article class="plans-panel">
                <h2 class="plans-title">Available Now</h2>
                @forelse(array_slice($activeNow, 0, 6) as $module)
                    <div class="start-here-action">
                        <p class="start-here-action-title">
                            <span>{{ $module['label'] ?? 'Module' }}</span>
                            <x-tenancy.module-state-badge :module-state="$module" size="sm" compact />
                        </p>
                    </div>
                @empty
                    <p class="plans-copy">No active modules are visible yet.</p>
                @endforelse
            </article>

            <article class="plans-panel">
                <h2 class="plans-title">Setup Next</h2>
                @forelse(array_slice($availableNext, 0, 6) as $module)
                    <div class="start-here-action">
                        <p class="start-here-action-title">
                            <span>{{ $module['label'] ?? 'Module' }}</span>
                            <x-tenancy.module-state-badge :module-state="$module" size="sm" compact />
                        </p>
                    </div>
                @empty
                    <p class="plans-copy">Included modules are already configured.</p>
                @endforelse
            </article>

            <article class="plans-panel">
                <h2 class="plans-title">Unlock Next</h2>
                @forelse(array_slice($purchasable, 0, 6) as $module)
                    <div class="start-here-action">
                        <p class="start-here-action-title">
                            <span>{{ $module['label'] ?? 'Module' }}</span>
                            <x-tenancy.module-state-badge :module-state="$module" size="sm" compact />
                        </p>
                    </div>
                @empty
                    <p class="plans-copy">No upgrade candidates are currently highlighted.</p>
                @endforelse
                <a class="plans-link" href="{{ $embeddedUrl(route('shopify.app.plans', [], false)) }}">Review all plans</a>
            </article>
        </div>

        @if($currentPlanModules !== [])
            <article class="plans-panel" aria-label="Included modules">
                <h2 class="plans-title">Included in {{ $currentPlan['label'] ?? 'Current plan' }}</h2>
                <div class="plans-state-grid">
                    @foreach($currentPlanModules as $module)
                        <x-tenancy.module-state-card :module-state="$module" />
                    @endforeach
                </div>
            </article>
        @endif

        <article class="plans-panel" aria-label="Plan catalog">
            <h2 class="plans-title">Plan Catalog</h2>
            <div class="plans-grid">
                @foreach($planCards as $card)
                    <section class="plans-card {{ ! empty($card['is_current']) ? 'is-current' : '' }}" data-plan-key="{{ $card['plan_key'] }}">
                        <h3>{{ $card['label'] }}</h3>
                        <p class="plans-card-price">{{ $card['price_display'] }}</p>
                        <p>{{ $card['summary'] }}</p>
                        @if(($card['highlights'] ?? []) !== [])
                            <ul class="plans-list">
                                @foreach($card['highlights'] as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if(is_array($card['cta'] ?? null) && filled($card['cta']['href'] ?? null))
                            <a class="plans-link" href="{{ $card['cta']['href'] }}">{{ $card['cta']['label'] ?? 'Learn more' }}</a>
                        @endif
                    </section>
                @endforeach
            </div>
        </article>

        @if($addonCards !== [])
            <article class="plans-panel" aria-label="Add-ons">
                <h2 class="plans-title">Add-ons</h2>
                <div class="plans-addon-grid">
                    @foreach($addonCards as $addon)
                        <section class="plans-card" data-addon-key="{{ $addon['addon_key'] }}">
                            <h3>{{ $addon['label'] }}</h3>
                            <p class="plans-card-price">{{ $addon['price_display'] }}</p>
                            <p>{{ $addon['summary'] }}</p>
                            <div class="plans-addon-row">
                                <span class="plans-pill">{{ $addon['enabled'] ? 'Enabled' : 'Available' }}</span>
                            </div>
                            <div class="plans-addon-row">
                                @foreach((array) ($addon['modules'] ?? []) as $module)
                                    @if(is_array($module['state'] ?? null))
                                        <x-tenancy.module-state-badge :module-state="$module['state']" size="sm" compact />
                                    @endif
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
            </article>
        @endif

        @if($lockedModules !== [])
            <article class="plans-panel" aria-label="Locked modules">
                <h2 class="plans-title">Upgrade Opportunities</h2>
                <div class="plans-upgrade-grid">
                    @foreach(array_slice($lockedModules, 0, 4) as $module)
                        <x-tenancy.module-upgrade-prompt
                            :module-state="$module"
                            store-route="shopify.app.store"
                            plans-route="shopify.app.plans"
                            contact-route="platform.contact"
                        />
                    @endforeach
                </div>
            </article>
        @endif

        @if($addOnCapableModules !== [])
            <article class="plans-panel" aria-label="Add-on capable modules">
                <h2 class="plans-title">Add-on Capable Modules</h2>
                <div class="plans-state-grid">
                    @foreach($addOnCapableModules as $module)
                        <x-tenancy.module-state-card :module-state="$module" />
                    @endforeach
                </div>
            </article>
        @endif
    </section>
</x-shopify-embedded-shell>
