<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="fb-auth-body antialiased">
        @php
            $authTenantPresentation = $authTenantPresentation ?? [];
            $tenantLabel = $authTenantPresentation['tenant_label'] ?? 'Your workspace';
            $heroTitle = $authTenantPresentation['hero_title'] ?? 'A clearer day starts here.';
            $heroSubtitle = $authTenantPresentation['hero_subtitle'] ?? 'Your team, your work, and your next step. Everything you need to keep business moving, together in Everbranch.';
            $heroTagline = $authTenantPresentation['hero_tagline'] ?? 'Everbranch by Evergrove Software';
            $productName = config('everbranch.product_name', 'Everbranch');
            $brandAssets = (array) config('everbranch.brand_assets', []);
            $brandAssetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
            $authLogoPath = (string) ($brandAssets['auth'] ?? 'brand/everbranch-auth.svg');
            $isLandlordMode = (bool) ($isLandlordMode ?? false);
            $hostTenantSlug = isset($hostTenant) && $hostTenant ? (string) ($hostTenant->slug ?? '') : (string) data_get($hostTenantContext ?? [], 'tenant.slug', '');
        @endphp

        <div
            class="fb-auth-shell"
            data-landlord-mode="{{ $isLandlordMode ? '1' : '0' }}"
            data-host-tenant="{{ $hostTenantSlug }}"
        >
            <section class="fb-auth-brand-panel" aria-label="Brand and context">
                <a href="{{ route('home') }}" class="fb-auth-brand fb-auth-brand--lockup" wire:navigate>
                    <img
                        src="{{ asset($authLogoPath) }}?v={{ $brandAssetVersion }}"
                        alt="{{ $productName }}"
                        class="fb-auth-brand-lockup"
                        loading="eager"
                        decoding="async"
                    />
                </a>

                <div class="fb-auth-brand-copy">
                    <p class="fb-auth-eyebrow">{{ $tenantLabel }}</p>
                    <h1>{{ $heroTitle }}</h1>
                    <p>{{ $heroSubtitle }}</p>
                </div>

                <p class="fb-auth-brand-foot">{{ $heroTagline }}</p>
            </section>

            <section class="fb-auth-card-wrap" aria-label="Authentication form">
                <div class="fb-auth-card">
                    {{ $slot }}
                    <p class="fb-auth-support">Need a hand? <a href="mailto:{{ config('everbranch.support_email') }}">Contact our team</a></p>
                </div>
            </section>
        </div>

        @fluxScripts
    </body>
</html>
