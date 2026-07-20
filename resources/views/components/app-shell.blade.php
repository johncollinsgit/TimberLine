@props([
    'navigation' => [],
    'pageTitle' => null,
    'pageSubtitle' => null,
    'subnav' => [],
    'actions' => [],
    'storeLabel' => null,
    'host' => null,
    'showSidebar' => true,
    'commandSearchEndpoint' => null,
    'commandSearchPlaceholder' => 'Search or ask what you want to do...',
    'commandSearchDocuments' => [],
    'commandSearchContext' => [],
    'commandPaletteVariant' => 'legacy',
    'workspaceLabel' => 'Unified workspace',
])

@php
    $shellTenant = request()->attributes->get('current_tenant');
    $neutralTenantSurface = request()->routeIs('agreements.*', 'proposals.*', 'billing.*', 'payments.*', 'invoices.*')
        || request()->is('agreements*', 'proposals*', 'billing*', 'payments*', 'invoices*');
    $tenantPresentation = app(\App\Services\Tenancy\TenantBrandProfileService::class)->presentationFor(
        ! $neutralTenantSurface && $shellTenant instanceof \App\Models\Tenant ? $shellTenant : null
    );
    $tenantThemeStyle = ! $neutralTenantSurface && $shellTenant instanceof \App\Models\Tenant
        ? '--tenant-primary: '.$tenantPresentation['primary_color'].';--tenant-accent: '.$tenantPresentation['accent_color'].';--tenant-surface: '.$tenantPresentation['surface_color'].';--tenant-text: '.$tenantPresentation['text_color'].';'
        : '';
@endphp
<div
    class="app-shell {{ $showSidebar ? 'app-shell--with-sidebar' : 'app-shell--no-sidebar' }} {{ ! $neutralTenantSurface && $shellTenant instanceof \App\Models\Tenant ? 'mf-tenant-themed' : '' }}"
    data-tenant-theme="{{ $tenantPresentation['theme_key'] }}"
    data-tenant-decor="{{ $tenantPresentation['decor_preset'] }}"
    data-tenant-display="{{ $tenantPresentation['display_style'] }}"
    data-tenant-corners="{{ $tenantPresentation['corner_style'] }}"
    style="{{ $tenantThemeStyle }}"
>
    @if($showSidebar)
        <aside class="app-shell-sidebar">
            <x-app-sidebar
                :items="$navigation['items'] ?? []"
                :active="$navigation['activeSection'] ?? null"
                :active-child="$navigation['activeChild'] ?? null"
                :workspace-label="$workspaceLabel"
            />
        </aside>
    @endif
    <div class="app-shell-main">
        <x-app-topbar
            :navigation="$navigation['items'] ?? []"
            :active="$navigation['activeSection'] ?? null"
            :active-child="$navigation['activeChild'] ?? null"
            :host="$host"
            :title="$pageTitle"
            :subtitle="$pageSubtitle"
            :subnav="$subnav"
            :actions="$actions"
            :store-label="$storeLabel"
            :workspace-label="$workspaceLabel"
            :command-search-enabled="filled($commandSearchEndpoint)"
            :command-search-placeholder="$commandSearchPlaceholder"
        />
        <div class="app-shell-content">
            {{ $slot }}
        </div>
    </div>
</div>

@if(filled($commandSearchEndpoint))
    @if($commandPaletteVariant === 'shopify-actions')
        <x-shopify-global-command-menu
            :search-endpoint="$commandSearchEndpoint"
            :placeholder="$commandSearchPlaceholder"
            :context-label="$workspaceLabel"
            :documents="$commandSearchDocuments"
            :context="$commandSearchContext"
        />
    @else
        <x-app-command-palette
            :search-endpoint="$commandSearchEndpoint"
            :placeholder="$commandSearchPlaceholder"
            :context-label="$workspaceLabel"
        />
    @endif
@endif
