@props([
    'sidebar' => false,
    'logoSrc' => null,
    'logoAlt' => 'Modern Forestry Backstage',
])

@php
    $brandLogoSrc = $logoSrc ?: asset('favicon.svg');
@endphp

@if($sidebar)
    <flux:sidebar.brand name="Modern Forestry Backstage" {{ $attributes }}>
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
    <flux:brand name="Modern Forestry Backstage" {{ $attributes }}>
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
