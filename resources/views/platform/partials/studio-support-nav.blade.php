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
            <a class="eb-studio-support-nav__demo" href="{{ route('platform.industry-demo', ['discipline' => 'field']) }}">Explore a system</a>
            <a href="{{ route('platform.modules.explore') }}">Modules</a>
            <a href="{{ route('platform.plans') }}">Plans</a>
            <a href="{{ route('login') }}">Log in</a>
            <a class="eb-studio-support-nav__cta" href="{{ route('platform.start') }}">Become a launch partner</a>
        </div>
    </nav>
</header>
