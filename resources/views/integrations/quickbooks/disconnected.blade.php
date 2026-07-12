@php
    $brandAssets = (array) config('everbranch.brand_assets', []);
    $assetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
    $lockup = asset((string) ($brandAssets['lockup'] ?? 'brand/everbranch-lockup.svg')).'?v='.$assetVersion;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'app_name' => 'Everbranch',
        'title' => 'QuickBooks disconnected',
        'description' => 'QuickBooks access has been disconnected from Everbranch.',
        'brand_assets' => $brandAssets,
    ])
</head>
<body class="eg-public-body eg-public-body--launch eg-legal-body">
    <header class="eg-legal-header">
        <a href="https://theeverbranch.com" class="eg-legal-brand" aria-label="Everbranch home">
            <img src="{{ $lockup }}" alt="Everbranch" />
        </a>
        <nav aria-label="Legal navigation">
            <a href="{{ route('legal.privacy') }}">Privacy</a>
            <a href="{{ route('legal.terms') }}">Terms</a>
        </nav>
    </header>

    <main class="eg-legal-main eg-integration-main">
        <header class="eg-legal-intro">
            <p class="eg-kicker">Connection status</p>
            <h1>QuickBooks is disconnected.</h1>
            <p>Everbranch will not make additional QuickBooks API requests through the disconnected authorization. Previously imported workspace records remain available until an administrator requests deletion.</p>
        </header>

        <section class="eg-legal-contact">
            <h2>Reconnect or request deletion</h2>
            <p>Sign in to reconnect an authorized workspace. For deletion of stored connection tokens or imported QuickBooks records, contact <a href="mailto:{{ config('evergrove.contact_email', 'hello@evergrovesoftware.com') }}">{{ config('evergrove.contact_email', 'hello@evergrovesoftware.com') }}</a>.</p>
            <p><a class="eg-button eg-button-primary" href="{{ route('integrations.quickbooks.index') }}">Sign in to Everbranch</a></p>
        </section>
    </main>
</body>
</html>
