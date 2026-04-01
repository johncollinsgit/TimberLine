@props([
    'authorized' => false,
    'shopifyApiKey' => null,
    'shopDomain' => null,
    'host' => null,
    'headline' => null,
    'subheadline' => null,
    'appNavigation' => [],
    'pageActions' => [],
])

<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :page-actions="$pageActions"
>
    @php
        $moduleStates = is_array($appNavigation['moduleStates'] ?? null) ? $appNavigation['moduleStates'] : [];
        $displayLabels = is_array($appNavigation['displayLabels'] ?? null) ? $appNavigation['displayLabels'] : [];
        $resolvedRewardsLabel = trim((string) ($rewardsLabel ?? data_get($displayLabels, 'rewards_label', data_get($displayLabels, 'rewards', 'Rewards'))));
        if ($resolvedRewardsLabel === '') {
            $resolvedRewardsLabel = 'Rewards';
        }
        $activeChild = strtolower(trim((string) ($appNavigation['activeChild'] ?? 'overview')));
        $activeSection = strtolower(trim((string) ($appNavigation['activeSection'] ?? 'rewards')));
        $moduleMap = [
            'overview' => 'rewards',
            'earn' => 'rewards',
            'redeem' => 'rewards',
            'referrals' => 'referrals',
            'birthdays' => 'birthdays',
            'vip' => 'vip',
            'notifications' => 'rewards',
        ];
        $activeModuleKey = $moduleMap[$activeChild] ?? $activeSection;
        $activeModuleState = is_array($moduleStates[$activeModuleKey] ?? null) ? $moduleStates[$activeModuleKey] : null;
        $activeModuleFallbackLabel = $activeModuleKey === 'rewards'
            ? $resolvedRewardsLabel
            : ucfirst(str_replace('_', ' ', $activeModuleKey));
        $activeModuleUi = \App\Support\Tenancy\TenantModuleUi::present($activeModuleState, $activeModuleFallbackLabel);
        $lockedModule = ($activeModuleUi['ui_state'] ?? '') === 'locked';
        $comingSoonModule = ($activeModuleUi['ui_state'] ?? '') === 'coming_soon';
    @endphp

    <style>
        .rewards-root {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-top: 0;
        }

        .rewards-note {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #ffffff;
            padding: 18px 20px;
            font-size: 14px;
            line-height: 1.6;
            color: rgba(15, 23, 42, 0.8);
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.04);
        }
    </style>

    <section class="rewards-root">
        @if(is_array($activeModuleState))
            <x-tenancy.module-state-card
                :module-state="$activeModuleState"
                :title="$activeModuleUi['label']"
                description="Module access and setup state is sourced from tenant entitlements."
            />
        @endif

        @if($lockedModule || $comingSoonModule)
            <x-tenancy.module-upgrade-prompt
                :module-state="$activeModuleState"
                store-route="shopify.app.store"
                plans-route="shopify.app.plans"
                contact-route="platform.contact"
                coming-soon-cta-label="Follow roadmap"
            />
        @endif

        @if(filled($setupNote))
            <div class="rewards-note">{{ $setupNote }}</div>
        @endif

        @if(! $lockedModule)
            @yield('rewards-content')
        @endif
    </section>
</x-shopify-embedded-shell>
