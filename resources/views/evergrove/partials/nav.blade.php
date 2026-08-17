@php
    $brandAssets = (array) config('evergrove.brand_assets', []);
    $assetVersion = (string) ($brandAssets['cache_tag'] ?? 'eg3');
    $lockup = asset((string) ($brandAssets['lockup'] ?? 'brand/evergrove-logo.png')).'?v='.$assetVersion;
    $appBaseUrl = rtrim((string) config('app.url', url('/')), '/');
    $loginUrl = $appBaseUrl.'/login';
@endphp

<header class="eg-site-header">
    <nav class="eg-site-nav" aria-label="Evergrove navigation">
        <a href="/" class="eg-site-logo" aria-label="Evergrove Software home">
            <img src="{{ $lockup }}" alt="Evergrove Software" />
        </a>

        <div class="eg-site-links eg-site-links--tabs" aria-label="Public sections">
            <a href="/#services">What We Build</a>
            <a href="/#everbranch">Everbranch</a>
            <a href="/#examples">Use Cases</a>
            <a href="/#work">Our Work</a>
            <a href="/#contact">Contact</a>
            <a href="/book" @if(request()->routeIs('evergrove.book')) aria-current="page" @endif>Book</a>
        </div>

        <div class="eg-site-actions">
            <a href="{{ $loginUrl }}" class="eg-nav-link">Login</a>
            <a href="/#contact" class="eg-nav-button">Start a Project</a>
        </div>
    </nav>
</header>
