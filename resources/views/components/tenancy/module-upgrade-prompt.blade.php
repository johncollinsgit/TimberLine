@props([
    'moduleState' => null,
    'moduleName' => null,
    'ctaHref' => null,
    'ctaLabel' => null,
    'comingSoonCtaLabel' => 'Learn More',
    'showWhenComingSoon' => true,
    'force' => false,
    'storeRoute' => null,
    'plansRoute' => null,
    'contactRoute' => null,
])

@php
    $presented = \App\Support\Tenancy\TenantModuleActionPresenter::present(
        is_array($moduleState) ? $moduleState : null,
        is_string($moduleName) ? $moduleName : null,
        [
            'store_route' => is_string($storeRoute) ? $storeRoute : null,
            'plans_route' => is_string($plansRoute) ? $plansRoute : null,
            'contact_route' => is_string($contactRoute) ? $contactRoute : null,
        ]
    );

    $isLocked = ($presented['ui_state'] ?? '') === 'locked';
    $isComingSoon = ($presented['ui_state'] ?? '') === 'coming_soon';
    $canShowLocked = $isLocked && ((bool) ($presented['upgrade_prompt_eligible'] ?? false) || (bool) $force);
    $canShowComingSoon = $isComingSoon && ((bool) $showWhenComingSoon || (bool) $force);
    $show = $canShowLocked || $canShowComingSoon;
    $resolvedCtaHref = is_string($ctaHref) && trim($ctaHref) !== '' ? $ctaHref : ($presented['cta_href'] ?? null);
    $resolvedLockedCtaLabel = is_string($ctaLabel) && trim($ctaLabel) !== '' ? $ctaLabel : ($presented['cta_label'] ?? 'Request access');
@endphp

@if($show)
    @once
        <style>
            .tenant-module-upgrade {
                border-radius: 14px;
                border: 1px solid rgba(15, 23, 42, 0.12);
                background: rgba(248, 250, 252, 0.95);
                padding: 14px;
                display: grid;
                gap: 10px;
            }

            .tenant-module-upgrade[data-module-state="locked"] {
                border-color: rgba(190, 24, 93, 0.24);
                background: rgba(190, 24, 93, 0.07);
            }

            .tenant-module-upgrade[data-module-state="coming_soon"] {
                border-color: rgba(3, 105, 161, 0.24);
                background: rgba(3, 105, 161, 0.07);
            }

            .tenant-module-upgrade__head {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
            }

            .tenant-module-upgrade__title {
                margin: 0;
                font-size: 14px;
                font-weight: 700;
                color: rgba(15, 23, 42, 0.92);
            }

            .tenant-module-upgrade__body {
                margin: 0;
                font-size: 13px;
                line-height: 1.55;
                color: rgba(15, 23, 42, 0.74);
            }

            .tenant-module-upgrade__cta {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: fit-content;
                border-radius: 999px;
                border: 1px solid rgba(15, 23, 42, 0.2);
                background: rgba(255, 255, 255, 0.96);
                min-height: 34px;
                padding: 0 12px;
                font-size: 12px;
                font-weight: 700;
                text-decoration: none;
                color: rgba(15, 23, 42, 0.86);
            }

            .tenant-module-upgrade__cta:hover {
                border-color: rgba(15, 23, 42, 0.34);
                color: rgba(15, 23, 42, 0.96);
            }
        </style>
    @endonce

    <section
        class="tenant-module-upgrade"
        data-module-key="{{ $presented['module_key'] }}"
        data-module-state="{{ $presented['ui_state'] }}"
    >
        <header class="tenant-module-upgrade__head">
            <h3 class="tenant-module-upgrade__title">
                @if($canShowLocked)
                    {{ $presented['label'] }} is locked
                @else
                    {{ $presented['label'] }} is coming soon
                @endif
            </h3>
            <x-tenancy.module-state-badge :module-state="$presented" size="sm" />
        </header>

        <p class="tenant-module-upgrade__body">
            @if($canShowLocked)
                {{ $presented['reason_description'] ?? 'This module is not currently available for the tenant.' }}
            @else
                {{ $presented['reason_description'] ?? 'This module is visible for roadmap alignment but is not available for setup yet.' }}
            @endif
        </p>

        @if(is_string($resolvedCtaHref) && trim($resolvedCtaHref) !== '')
            <a href="{{ $resolvedCtaHref }}" class="tenant-module-upgrade__cta">
                {{ $canShowLocked ? $resolvedLockedCtaLabel : $comingSoonCtaLabel }}
            </a>
        @endif
    </section>
@endif
