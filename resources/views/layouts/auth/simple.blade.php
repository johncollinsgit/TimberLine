<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="fb-auth-body antialiased">
        @php
            $authTenantPresentation = $authTenantPresentation ?? [];
            $tenantLabel = $authTenantPresentation['tenant_label'] ?? 'Modern Forestry';
            $portalName = $authTenantPresentation['portal_name'] ?? 'Forestry Backstage';
            $heroTitle = $authTenantPresentation['hero_title'] ?? 'Production, shipping, and wholesale in one place.';
            $heroSubtitle = $authTenantPresentation['hero_subtitle'] ?? 'Track orders, inventory, fulfillment, and customer growth from one place built for real operations.';
            $heroTagline = $authTenantPresentation['hero_tagline'] ?? 'Operations Console';
            $isLandlordMode = (bool) ($isLandlordMode ?? false);
            $hostTenantSlug = isset($hostTenant) && $hostTenant ? (string) ($hostTenant->slug ?? '') : (string) data_get($hostTenantContext ?? [], 'tenant.slug', '');
        @endphp

        <div
            class="fb-auth-shell"
            data-landlord-mode="{{ $isLandlordMode ? '1' : '0' }}"
            data-host-tenant="{{ $hostTenantSlug }}"
        >
            <section class="fb-auth-brand-panel" aria-label="Brand and context">
                <a href="{{ route('home') }}" class="fb-auth-brand" wire:navigate>
                    <img src="{{ asset('brand/forestry-backstage-mark.svg') }}" alt="Forestry Backstage" loading="eager" decoding="async" />
                    <strong>{{ $portalName }}</strong>
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
                </div>
            </section>
        </div>

        @fluxScripts
    </body>
</html>
