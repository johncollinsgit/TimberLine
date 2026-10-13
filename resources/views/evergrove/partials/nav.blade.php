@php
    $brandAssets = (array) config('evergrove.brand_assets', []);
    $assetVersion = (string) ($brandAssets['cache_tag'] ?? 'eg3');
    $lockup = asset((string) ($brandAssets['lockup'] ?? 'brand/evergrove-logo.png')).'?v='.$assetVersion;
    $navigationLinks = ['/#services' => 'What We Build', '/#everbranch' => 'Everbranch', '/book' => 'Book'];
@endphp

<header class="eg-site-header">
    <nav class="eg-site-nav" aria-label="Evergrove navigation">
        <a href="/" class="eg-site-logo" aria-label="Evergrove Software home">
            <img src="{{ $lockup }}" alt="Evergrove Software" />
        </a>

        <div class="eg-site-links eg-site-links--tabs" aria-label="Public sections">
            @foreach($navigationLinks as $href => $label)
                <a href="{{ $href }}" @if($href === '/book' && request()->routeIs('evergrove.book')) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </div>

        <div class="eg-site-actions">
            <a href="/#contact" class="eg-nav-button">Start a Project</a>
        </div>

        <details class="eg-mobile-nav" data-evergrove-mobile-nav>
            <summary aria-label="Navigation menu">
                <span class="eg-hamburger" aria-hidden="true"><span></span><span></span><span></span></span>
            </summary>
            <div class="eg-mobile-nav-panel">
                @foreach($navigationLinks as $href => $label)
                    <a href="{{ $href }}" @if($href === '/book' && request()->routeIs('evergrove.book')) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
                <a href="/#contact" class="eg-nav-button">Start a Project</a>
            </div>
        </details>
    </nav>
</header>
