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
        'title' => 'Instagram Messaging connection',
        'description' => 'Connect an Instagram professional account to an Everbranch workspace.',
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
            <p class="eg-kicker">Customer messaging</p>
            <h1>Connect Instagram to the right workspace.</h1>
            <p>Everbranch receives Instagram DMs in the response inbox. Replies are available only from active customer conversations, never as a bulk or cold-message tool.</p>
        </header>

        @if(session('status'))
            <p class="eg-alert eg-alert-success" role="status">{{ session('status') }}</p>
        @endif

        @unless($isConfigured)
            <section class="eg-legal-contact">
                <h2>Instagram setup is not enabled yet.</h2>
                <p>An Everbranch administrator needs to add the Meta app credentials and webhook verification token before an account can be connected.</p>
            </section>
        @endunless

        <section class="eg-integration-list" aria-label="Available workspaces">
            @forelse($tenants as $tenant)
                @php($isConnected = in_array((int) $tenant->id, $connectedTenantIds, true))
                <article class="eg-integration-row">
                    <div>
                        <span>{{ $isConnected ? 'Connected' : 'Available to connect' }}</span>
                        <h2>{{ $tenant->name }}</h2>
                        <p>{{ $isConnected ? 'Reconnect to change the authorized Instagram professional account.' : 'Authorize the Instagram professional account for this workspace.' }}</p>
                    </div>
                    @if($isConfigured)
                        <a class="eg-button eg-button-primary" href="{{ route('integrations.instagram.connect', ['tenant' => $tenant->slug]) }}">
                            {{ $isConnected ? 'Reconnect' : 'Connect' }}
                        </a>
                    @endif
                </article>
            @empty
                <article class="eg-integration-row">
                    <div>
                        <span>No workspace access</span>
                        <h2>A workspace administrator can add you.</h2>
                        <p>Your account does not currently have an Everbranch workspace available for Instagram setup.</p>
                    </div>
                </article>
            @endforelse
        </section>
    </main>
</body>
</html>
