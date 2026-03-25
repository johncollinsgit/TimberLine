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
    @endphp

    <style>
        .customers-stack {
            display: grid;
            gap: 16px;
        }

        .customers-surface {
            border-radius: 14px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.92);
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.08);
            padding: 18px 20px;
        }

        .customers-surface h2 {
            margin: 0;
            font-size: 1.03rem;
            font-weight: 650;
            color: #0f172a;
            letter-spacing: -0.01em;
        }

        .customers-surface p {
            margin: 10px 0 0;
            color: rgba(15, 23, 42, 0.68);
            font-size: 14px;
            line-height: 1.65;
        }

        .customers-muted-note {
            margin-top: 10px;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.52);
        }
    </style>

    <section class="customers-stack">
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
