@props([
    'sidebar' => false,
    'logoSrc' => null,
    'logoAlt' => config('everbranch.product_name', 'Everbranch'),
])

@php
    $tenant = request()->attributes->get('current_tenant');
    $brandPresentation = app(\App\Services\Tenancy\TenantBrandProfileService::class)->presentationFor(
        $tenant instanceof \App\Models\Tenant ? $tenant : null
    );
    $brandLogoSrc = $logoSrc ?: (string) $brandPresentation['icon_url'];
    $productName = (string) $brandPresentation['display_name'];
@endphp

@if($sidebar)
    <flux:sidebar.brand name="{{ $productName }}" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md overflow-hidden">
            <img
                src="{{ $brandLogoSrc }}"
                alt="{{ $logoAlt }}"
                class="block size-8 object-contain"
                loading="eager"
                decoding="async"
            />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="{{ $productName }}" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md overflow-hidden">
            <img
                src="{{ $brandLogoSrc }}"
                alt="{{ $logoAlt }}"
                class="block size-8 object-contain"
                loading="eager"
                decoding="async"
            />
        </x-slot>
    </flux:brand>
@endif
