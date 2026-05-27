@php
    $brandAssets = (array) config('evergrove.brand_assets', []);
    $assetVersion = (string) ($brandAssets['cache_tag'] ?? 'eg1');
    $lockup = asset((string) ($brandAssets['lockup'] ?? 'brand/evergrove-lockup.svg')).'?v='.$assetVersion;
    $appBaseUrl = rtrim((string) config('app.url', url('/')), '/');
    $loginUrl = $appBaseUrl.'/login';
    $registerUrl = $appBaseUrl.'/register';
@endphp

<header class="eg-site-header">
    <nav class="eg-site-nav" aria-label="Evergrove navigation">
        <a href="/" class="eg-site-logo" aria-label="Evergrove Software home">
            <img src="{{ $lockup }}" alt="Evergrove Software" />
        </a>

        <div class="eg-site-links" aria-label="Public sections">
            <a href="/#services">Services</a>
            <a href="/#work">Work</a>
            <a href="/#tools">Tools</a>
            <a href="/#pricing">Pricing</a>
            <a href="/#everbranch">Everbranch</a>
        </div>

        <div class="eg-site-actions">
            <a href="{{ $loginUrl }}" class="eg-nav-link">Login</a>
            @if (Route::has('register'))
                <a href="{{ $registerUrl }}" class="eg-nav-button">Sign Up</a>
            @endif
        </div>
    </nav>
</header>
