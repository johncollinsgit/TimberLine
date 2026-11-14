@php
    $studioBrandAssets = (array) config('everbranch.brand_assets', []);
    $studioBrandAssetVersion = (string) ($studioBrandAssets['cache_tag'] ?? 'eb1');
    $studioBrandLockupPath = (string) ($studioBrandAssets['lockup'] ?? 'brand/everbranch-lockup.svg');
@endphp
<header class="eb-studio-support-nav-wrap">
    <nav class="eb-studio-support-nav" aria-label="Everbranch public navigation">
        <a href="{{ route('platform.promo') }}" aria-label="Everbranch home">
            <img src="{{ asset($studioBrandLockupPath) }}?v={{ $studioBrandAssetVersion }}" alt="Everbranch" />
        </a>
        <div>
            <a href="{{ route('platform.promo') }}#explore">Explore</a>
            <a href="{{ route('platform.modules.explore') }}">Branches</a>
            <a href="{{ route('platform.promo') }}#guide-tab-websites">Websites</a>
            <a href="{{ route('platform.plans') }}">Plans</a>
            <a href="{{ route('login') }}">Log in</a>
            <a class="eb-studio-support-nav__cta" href="{{ route('platform.plans') }}"><x-everbranch.action-leaf />Pricing</a>
        </div>
    </nav>
</header>
@include('platform.partials.ask-everbranch')
