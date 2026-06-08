@props([
    'decorative' => true,
    'title' => 'Leaf',
])

@php
    $brandAssets = (array) config('everbranch.brand_assets', []);
    $brandAssetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
    $brandMarkPath = (string) ($brandAssets['mark'] ?? 'brand/everbranch-mark.png');
    $brandMarkUrl = asset($brandMarkPath).'?v='.$brandAssetVersion;
@endphp

<span
    {{ $attributes->class('mf-leaf-icon') }}
    style="--mf-leaf-icon-url: url('{{ $brandMarkUrl }}');"
    @if($decorative)
        aria-hidden="true"
    @else
        role="img"
        aria-label="{{ $title }}"
    @endif
></span>
