@php
    $brandAssets = (array) config('everbranch.brand_assets', []);
    $brandAssetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
    $brandMarkPath = (string) ($brandAssets['mark'] ?? 'brand/everbranch-mark.svg');
@endphp

<div id="intro-logo" aria-hidden="true">
    <div class="intro-logo__inner">
        <img src="{{ asset($brandMarkPath) }}?v={{ $brandAssetVersion }}" alt="" class="intro-logo__mark" />
    </div>
</div>

<div id="site-ambient" aria-hidden="true">
    <div class="ambient ambient--primary"></div>
    <div class="ambient ambient--secondary"></div>
</div>
