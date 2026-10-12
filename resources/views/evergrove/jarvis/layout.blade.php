@php
    $brandAssets = (array) config('evergrove.brand_assets', []);
    $brandAssets['favicon_svg'] = 'brand/jarvis-mark.svg';
    $brandAssets['og_image'] = 'brand/jarvis-logo.png';
    $titles = [
        'home' => 'Jarvis | A personal assistant for the work ahead',
        'privacy' => 'Jarvis Privacy | Evergrove Software',
        'terms' => 'Jarvis Terms of Use | Evergrove Software',
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'app_name' => 'Jarvis',
        'title' => $titles[$page],
        'exact_title' => true,
        'description' => 'Meet Jarvis, a personal desktop assistant, and understand its Google connections, data handling, and terms of use.',
        'brand_assets' => $brandAssets,
    ])
    <link rel="stylesheet" href="{{ asset('brand/jarvis-pages.css') }}">
</head>
<body class="eg-public-body eg-public-body--launch jarvis-public">
    <a class="jarvis-skip" href="#main-content">Skip to content</a>
    @include('evergrove.partials.nav')
    <div class="jarvis-subnav-wrap">
        <nav class="jarvis-subnav" aria-label="Jarvis navigation">
            <a class="jarvis-brand" href="/jarvis">
                <img src="{{ asset('brand/jarvis-mark.svg') }}" alt="" width="36" height="36">
                <span>Jarvis</span>
            </a>
            <div class="jarvis-subnav-links">
                <a href="/jarvis" @if($page === 'home') aria-current="page" @endif>Overview</a>
                <a href="/jarvis/privacy" @if($page === 'privacy') aria-current="page" @endif>Privacy</a>
                <a href="/jarvis/terms" @if($page === 'terms') aria-current="page" @endif>Terms</a>
            </div>
        </nav>
    </div>
    <main id="main-content" class="jarvis-main">
        @yield('jarvis-content')
    </main>
    <footer class="jarvis-footer">
        <div>
            <a class="jarvis-brand" href="/jarvis"><img src="{{ asset('brand/jarvis-mark.svg') }}" alt="" width="32" height="32"><span>Jarvis</span></a>
            <p>A personal assistant from Evergrove Software.</p>
        </div>
        <nav aria-label="Jarvis footer navigation">
            <a href="/">Evergrove</a>
            <a href="/jarvis/privacy">Privacy</a>
            <a href="/jarvis/terms">Terms</a>
            <a href="mailto:johncollinsemail@gmail.com">Jarvis support</a>
        </nav>
    </footer>
</body>
</html>
