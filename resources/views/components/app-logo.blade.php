@props([
    'sidebar' => false,
    'logoSrc' => null,
    'logoAlt' => config('everbranch.product_name', 'Everbranch'),
])

@php
    $brandAssets = (array) config('everbranch.brand_assets', []);
    $brandAssetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
    $brandLogoPath = (string) ($brandAssets['mark'] ?? 'brand/everbranch-mark.svg');
    $brandLogoSrc = $logoSrc ?: asset($brandLogoPath).'?v='.$brandAssetVersion;
    $productName = config('everbranch.product_name', 'Everbranch');
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
