@php
  $brandAssets = (array) config('everbranch.brand_assets', []);
  $brandAssetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
  $brandLogoPath = (string) ($brandAssets['mark'] ?? 'brand/everbranch-mark.svg');
@endphp

<img
  src="{{ asset($brandLogoPath) }}?v={{ $brandAssetVersion }}"
  alt="{{ config('everbranch.product_name', 'Everbranch') }}"
  {{ $attributes->merge(['class' => 'object-contain']) }}
/>
