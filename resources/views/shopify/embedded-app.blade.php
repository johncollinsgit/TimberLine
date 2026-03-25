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
    @endphp

    @if($moduleStates !== [])
        <style>
            .embedded-module-experience {
                display: grid;
                gap: 14px;
                margin-bottom: 16px;
            }

            .embedded-module-experience__grid {
                display: grid;
                gap: 10px;
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            @media (max-width: 1024px) {
                .embedded-module-experience__grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 640px) {
                .embedded-module-experience__grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>

        <section class="embedded-module-experience" data-module-experience="dashboard-home">
            <x-tenancy.module-setup-checklist
                :module-states="$moduleStates"
                :module-order="$moduleCardOrder"
                title="Module setup checklist"
                subtitle="This checklist is driven by tenant entitlements and setup state, so operators can see active, setup-needed, locked, and coming-soon modules in one place."
                :cta-href="route('marketing.overview')"
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
