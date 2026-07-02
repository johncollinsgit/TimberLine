@php
    $brandAssets = (array) config('evergrove.brand_assets', []);
    $assetVersion = (string) ($brandAssets['cache_tag'] ?? 'eg3');
    $lockup = asset((string) ($brandAssets['lockup'] ?? 'brand/evergrove-logo.png')).'?v='.$assetVersion;
    $appBaseUrl = rtrim((string) config('app.url', url('/')), '/');
    $loginUrl = $appBaseUrl.'/login';
    $registerUrl = $appBaseUrl.'/register';
@endphp

<header class="eg-site-header">
    <nav class="eg-site-nav" aria-label="Evergrove navigation">
        <a href="/" class="eg-site-logo" aria-label="Evergrove Software home">
            <img src="{{ $lockup }}" alt="Evergrove Software" />
        </a>

        <div class="eg-site-links eg-site-links--tabs" aria-label="Public sections">
            <a href="/#problem">Problem</a>
            <a href="/#services">What We Build</a>
            <a href="/#how-it-works">How It Works</a>
            <a href="/#examples">Examples</a>
            <a href="/#contact">Contact</a>
        </div>

        <div class="eg-site-actions">
            <a href="{{ $loginUrl }}" class="eg-nav-link">Login</a>
            @if (Route::has('register'))
                <a href="{{ $registerUrl }}" class="eg-nav-button">Sign Up</a>
            @endif
        </div>
    </nav>
</header>
