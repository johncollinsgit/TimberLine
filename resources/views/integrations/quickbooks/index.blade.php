@php
    $brandAssets = (array) config('everbranch.brand_assets', []);
    $assetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
    $lockup = asset((string) ($brandAssets['lockup'] ?? 'brand/everbranch-lockup.svg')).'?v='.$assetVersion;
    $connectedTenantIds = array_map('intval', $connectedTenantIds ?? []);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'app_name' => 'Everbranch',
        'title' => 'QuickBooks connection',
        'description' => 'Choose an Everbranch workspace to connect or reconnect to QuickBooks Online.',
        'brand_assets' => $brandAssets,
    ])
</head>
<body class="eg-public-body eg-public-body--launch eg-legal-body">
    <header class="eg-legal-header">
        <a href="https://theeverbranch.com" class="eg-legal-brand" aria-label="Everbranch home">
            <img src="{{ $lockup }}" alt="Everbranch" />
        </a>
        <nav aria-label="Account navigation">
            <a href="{{ route('legal.privacy') }}">Privacy</a>
            <a href="{{ route('legal.terms') }}">Terms</a>
        </nav>
    </header>

    <main class="eg-legal-main eg-integration-main">
        <header class="eg-legal-intro">
            <p class="eg-kicker">Accounting connection</p>
            <h1>Connect QuickBooks to the right workspace.</h1>
            <p>Everbranch imports authorized customers, estimates, invoices, and items into the workspace you choose. The current connection is read/import only.</p>
        </header>

        <section class="eg-integration-list" aria-label="Available workspaces">
            @forelse($tenants as $tenant)
                @php($isConnected = in_array((int) $tenant->id, $connectedTenantIds, true))
                <article class="eg-integration-row">
                    <div>
                        <span>{{ $isConnected ? 'Connected' : 'Available to connect' }}</span>
                        <h2>{{ $tenant->name }}</h2>
                        <p>{{ $isConnected ? 'Reconnect to renew or change the authorized QuickBooks company.' : 'Authorize a QuickBooks company for this workspace.' }}</p>
                    </div>
                    <a class="eg-button eg-button-primary" href="{{ route('integrations.quickbooks.connect', ['tenant' => $tenant->slug]) }}">
                        {{ $isConnected ? 'Reconnect' : 'Connect' }}
                    </a>
                </article>
            @empty
                <article class="eg-integration-row">
                    <div>
                        <span>No workspace access</span>
                        <h2>A workspace administrator can add you.</h2>
                        <p>Your account does not currently have an Everbranch workspace available for QuickBooks setup.</p>
                    </div>
                </article>
            @endforelse
        </section>

        <section class="eg-legal-contact">
            <h2>What Everbranch can access</h2>
            <p>The accounting scope is used for tenant-scoped import and analysis. Everbranch does not process payments or write transactions back to QuickBooks in the current integration.</p>
        </section>
    </main>
</body>
</html>
