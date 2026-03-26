@php
    $isLandlordMode = (bool) ($isLandlordMode ?? false);
    $hostTenantSlug = isset($hostTenant) && $hostTenant ? (string) ($hostTenant->slug ?? '') : (string) data_get($hostTenantContext ?? [], 'tenant.slug', '');
@endphp

<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main
        data-landlord-mode="{{ $isLandlordMode ? '1' : '0' }}"
        data-host-tenant="{{ $hostTenantSlug }}"
    >
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
