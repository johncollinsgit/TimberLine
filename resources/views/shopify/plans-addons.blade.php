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
        $commercialContext = is_array($payload['commercial_context'] ?? null) ? $payload['commercial_context'] : [];
        $planCards = is_array($payload['plan_cards'] ?? null) ? $payload['plan_cards'] : [];
        $addonCards = is_array($payload['addon_cards'] ?? null) ? $payload['addon_cards'] : [];
        $currentPlanModules = is_array($payload['current_plan_modules'] ?? null) ? $payload['current_plan_modules'] : [];
        $lockedModules = is_array($payload['locked_modules'] ?? null) ? $payload['locked_modules'] : [];
        $addOnCapableModules = is_array($payload['add_on_capable_modules'] ?? null) ? $payload['add_on_capable_modules'] : [];
        $upgradeCtas = is_array($content['upgrade_ctas'] ?? null) ? $content['upgrade_ctas'] : [];
        $templateKey = is_string($commercialContext['template_key'] ?? null) ? $commercialContext['template_key'] : null;
        $labelSource = (string) ($commercialContext['label_source'] ?? 'entitlements_default');
        $labelSourceDisplay = match ($labelSource) {
            'tenant_override' => 'tenant override',
            'template_default' => 'template default',
            default => 'entitlements default',
        };
        $templateMissing = (bool) ($commercialContext['template_missing'] ?? false);
        $contextLabels = is_array($commercialContext['labels'] ?? null) ? $commercialContext['labels'] : [];
    @endphp

    <style>
        .plans-shell {
            display: grid;
            gap: 14px;
        }

        .plans-panel {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.06);
            padding: 16px;
            display: grid;
            gap: 10px;
        }

        .plans-title {
            margin: 0;
            font-size: 1.04rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.86);
            font-weight: 700;
        }

        .plans-copy {
            margin: 0;
            font-size: 13px;
            line-height: 1.58;
            color: rgba(15, 23, 42, 0.7);
        }

        .plans-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .plans-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            min-height: 28px;
            padding: 0 10px;
            font-size: 10px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.7);
            font-weight: 700;
            background: rgba(248, 250, 252, 0.95);
        }

        .plans-grid {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .plans-card {
            border-radius: 13px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(248, 250, 252, 0.95);
            padding: 12px;
            display: grid;
            gap: 8px;
        }

        .plans-card.is-current {
            border-color: rgba(15, 143, 97, 0.45);
            background: rgba(236, 253, 245, 0.92);
        }

        .plans-card h3 {
            margin: 0;
            font-size: 14px;
            color: rgba(15, 23, 42, 0.88);
            font-weight: 700;
        }

        .plans-card-price {
            margin: 0;
            font-family: "Fraunces", "Iowan Old Style", "Times New Roman", serif;
            color: #0f766e;
            font-size: 1.2rem;
        }

        .plans-card p {
            margin: 0;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.68);
            line-height: 1.5;
        }

        .plans-list {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 5px;
        }

        .plans-list li {
            font-size: 11px;
            color: rgba(15, 23, 42, 0.68);
            line-height: 1.45;
        }

        .plans-link {
            display: inline-flex;
            width: fit-content;
            color: #0f766e;
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
        }

        .plans-link:hover {
            text-decoration: underline;
        }

        .plans-addon-grid,
        .plans-state-grid,
        .plans-upgrade-grid {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .plans-addon-row {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        @media (max-width: 1100px) {
            .plans-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            .plans-grid,
            .plans-addon-grid,
            .plans-state-grid,
            .plans-upgrade-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <section class="plans-shell" data-plans-surface="true">
        <article class="plans-panel" aria-label="Current plan summary">
            <h2 class="plans-title">Current Access Profile</h2>
            <p class="plans-copy">{{ $content['subtitle'] ?? '' }}</p>
            <div class="plans-meta">
                <span class="plans-pill">Plan · {{ $currentPlan['label'] ?? 'Unknown' }}</span>
                <span class="plans-pill">Track · {{ strtoupper((string) ($currentPlan['track'] ?? 'shopify')) }}</span>
                <span class="plans-pill">Mode · {{ strtoupper((string) ($currentPlan['operating_mode'] ?? 'shopify')) }}</span>
                <span class="plans-pill">Template · {{ $templateKey ?: 'none' }}</span>
                <span class="plans-pill">Labels · {{ $labelSourceDisplay }}</span>
            </div>
            @if($templateMissing)
                <p class="plans-copy">Template defaults were not found for this assignment, so entitlement labels are being used as fallback.</p>
            @elseif($labelSource === 'entitlements_default')
                <p class="plans-copy">No label overrides are active for this tenant, so entitlement defaults are in effect.</p>
            @endif
            <p class="plans-copy">
                Effective labels:
                {{ $contextLabels['rewards'] ?? 'Rewards' }} / {{ $contextLabels['birthdays'] ?? 'Birthdays / Lifecycle' }}.
            </p>
            <p class="plans-copy">
                Commercial configuration is active, but billing lifecycle remains inactive in this phase (no checkout or subscription mutation).
            </p>
            <div class="plans-meta">
                @if(is_array($upgradeCtas['primary'] ?? null) && filled($upgradeCtas['primary']['href'] ?? null))
                    <a class="plans-link" href="{{ $upgradeCtas['primary']['href'] }}">{{ $upgradeCtas['primary']['label'] ?? 'Request upgrade' }}</a>
                @endif
                @if(is_array($upgradeCtas['secondary'] ?? null) && filled($upgradeCtas['secondary']['href'] ?? null))
                    <a class="plans-link" href="{{ $upgradeCtas['secondary']['href'] }}">{{ $upgradeCtas['secondary']['label'] ?? 'Book demo' }}</a>
                @endif
            </div>
        </article>

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

        @if($currentPlanModules !== [])
            <article class="plans-panel" aria-label="Included modules">
                <h2 class="plans-title">Included Modules</h2>
                <div class="plans-state-grid">
                    @foreach($currentPlanModules as $module)
                        <x-tenancy.module-state-card :module-state="$module" />
                    @endforeach
                </div>
            </article>
        @endif

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
                <h2 class="plans-title">Locked Modules</h2>
                <div class="plans-upgrade-grid">
                    @foreach(array_slice($lockedModules, 0, 4) as $module)
                        <x-tenancy.module-upgrade-prompt
                            :module-state="$module"
                            :cta-href="($upgradeCtas['primary']['href'] ?? route('platform.contact'))"
                            :cta-label="($upgradeCtas['primary']['label'] ?? 'Request upgrade')"
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
