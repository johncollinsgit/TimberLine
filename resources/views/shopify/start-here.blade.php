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
        $lockedModules = array_values((array) ($checklist['locked'] ?? []));
        $comingSoonModules = array_values((array) ($checklist['coming_soon'] ?? []));
    @endphp

    <style>
        .start-here-shell {
            display: grid;
            gap: 16px;
        }

        .start-here-panel {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.06);
            padding: 16px;
            display: grid;
            gap: 10px;
        }

        .start-here-headline {
            margin: 0;
            font-size: 1.18rem;
            color: rgba(15, 23, 42, 0.94);
            font-weight: 700;
        }

        .start-here-copy {
            margin: 0;
            font-size: 13px;
            line-height: 1.58;
            color: rgba(15, 23, 42, 0.72);
        }

        .start-here-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .start-here-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(248, 250, 252, 0.95);
            min-height: 28px;
            padding: 0 10px;
            font-size: 10px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.68);
            font-weight: 700;
        }

        .start-here-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: 1.4fr 1fr;
            align-items: start;
        }

        .start-here-list {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 8px;
        }

        .start-here-list li {
            border-radius: 11px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(248, 250, 252, 0.92);
            padding: 9px 10px;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.74);
            line-height: 1.55;
        }

        .start-here-actions {
            display: grid;
            gap: 8px;
        }

        .start-here-action {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(248, 250, 252, 0.95);
            padding: 10px;
            display: grid;
            gap: 6px;
        }

        .start-here-action-title {
            margin: 0;
            font-size: 12px;
            font-weight: 700;
            color: rgba(15, 23, 42, 0.86);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .start-here-action-copy {
            margin: 0;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.66);
            line-height: 1.5;
        }

        .start-here-action-link {
            display: inline-flex;
            width: fit-content;
            text-decoration: none;
            color: #0f766e;
            font-size: 12px;
            font-weight: 700;
        }

        .start-here-action-link:hover {
            text-decoration: underline;
        }

        .start-here-upgrade-grid,
        .start-here-card-grid {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        @media (max-width: 980px) {
            .start-here-grid,
            .start-here-upgrade-grid,
            .start-here-card-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <section class="start-here-shell" data-onboarding-surface="true">
        <article class="start-here-panel" aria-label="Onboarding orientation">
            <h2 class="start-here-headline">{{ $content['welcome_title'] ?? 'Start Here' }}</h2>
            <p class="start-here-copy">{{ $content['welcome_body'] ?? '' }}</p>

            <div class="start-here-meta" aria-label="Current access profile">
                <span class="start-here-pill">Plan · {{ $plan['label'] ?? 'Unknown' }}</span>
                <span class="start-here-pill">Track · {{ strtoupper((string) ($plan['track'] ?? 'shopify')) }}</span>
                <span class="start-here-pill">Mode · {{ strtoupper((string) ($plan['operating_mode'] ?? 'shopify')) }}</span>
            </div>

            @if((array) ($content['orientation_points'] ?? []) !== [])
                <ul class="start-here-list" aria-label="Orientation notes">
                    @foreach((array) ($content['orientation_points'] ?? []) as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            @endif
        </article>

        <div class="start-here-grid">
            <x-tenancy.module-setup-checklist
                :module-states="$moduleStates"
                :module-order="$moduleOrder"
                title="Setup Checklist"
                subtitle="Use this checklist to separate setup-needed work from locked and coming-soon modules."
                :cta-href="route('shopify.app.plans', [], false)"
            />

            <article class="start-here-panel" aria-label="Recommended actions">
                <h3 class="start-here-headline" style="font-size: 1rem;">Recommended Next Actions</h3>
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
                                <a class="start-here-action-link" href="{{ $action['href'] }}">Open</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </article>
        </div>

        @if($lockedModules !== [])
            <article class="start-here-panel" aria-label="Locked modules">
                <h3 class="start-here-headline" style="font-size: 1rem;">Locked Modules</h3>
                <p class="start-here-copy">These modules are unavailable under the current access profile. Upgrade paths remain informational in this phase.</p>
                <div class="start-here-upgrade-grid">
                    @foreach(array_slice($lockedModules, 0, 4) as $module)
                        <x-tenancy.module-upgrade-prompt
                            :module-state="$module"
                            :cta-href="route('shopify.app.plans', [], false)"
                            cta-label="Review plans"
                        />
                    @endforeach
                </div>
            </article>
        @endif

        @if($comingSoonModules !== [])
            <article class="start-here-panel" aria-label="Coming soon modules">
                <h3 class="start-here-headline" style="font-size: 1rem;">Coming Soon Modules</h3>
                <div class="start-here-card-grid">
                    @foreach(array_slice($comingSoonModules, 0, 6) as $module)
                        <x-tenancy.module-state-card :module-state="$module" />
                    @endforeach
                </div>
            </article>
        @endif

        @if((array) ($content['future_prompts'] ?? []) !== [])
            <article class="start-here-panel" aria-label="Future prompts">
                <h3 class="start-here-headline" style="font-size: 1rem;">Future Expansion Prompts</h3>
                <ul class="start-here-list">
                    @foreach((array) ($content['future_prompts'] ?? []) as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </article>
        @endif
    </section>
</x-shopify-embedded-shell>
