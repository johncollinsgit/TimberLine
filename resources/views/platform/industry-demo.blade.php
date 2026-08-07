@php
    $brandAssets = (array) config('everbranch.brand_assets', []);
    $brandAssetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
    $brandLockupPath = (string) ($brandAssets['lockup'] ?? 'brand/everbranch-lockup.svg');
    $productName = (string) config('everbranch.product_name', 'Everbranch');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'title' => $disciplines[$discipline].' example | '.$productName,
        'description' => 'Explore a fictional website and Everbranch operations workspace for '.$disciplines[$discipline].'.',
    ])
</head>
<body class="fb-public-body eb-industry-page-body" data-premium-motion="public" data-industry-page data-industry-key="{{ $discipline }}">
    <a class="eb-skip-link" href="#example-system">Skip to the example system</a>

    <header class="eb-industry-page-header">
        <a class="eb-industry-page-back" href="{{ route('platform.promo') }}#industries"><span aria-hidden="true">←</span> Back to Everbranch</a>
        <a class="eb-industry-page-brand" href="{{ route('platform.promo') }}" aria-label="Everbranch home"><img src="{{ asset($brandLockupPath) }}?v={{ $brandAssetVersion }}" alt="Everbranch" /></a>
        <a class="eb-industry-page-cta" href="{{ route('platform.start') }}">Request launch-partner access <span aria-hidden="true">↗</span></a>
        <section class="eb-industry-page-controls" aria-label="Example controls">
            <div class="eb-industry-page-control-group">
                <span>Example business type</span>
                <div class="eb-industry-page-disciplines" aria-label="Choose a business type">
                    @foreach ($disciplines as $key => $label)
                        <a href="{{ route('platform.industry-demo', ['discipline' => $key]) }}" @if ($key === $discipline) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </div>
            </div>
            <div class="eb-industry-page-control-group">
                <span>Example view</span>
                <div class="eb-industry-page-views" role="tablist" aria-label="Example system view">
                    <button type="button" role="tab" id="industry-page-website-tab" aria-controls="industry-page-website" aria-selected="true" data-industry-page-view="website">Website</button>
                    <button type="button" role="tab" id="industry-page-workspace-tab" aria-controls="industry-page-workspace" aria-selected="false" data-industry-page-view="workspace">Operations workspace</button>
                </div>
            </div>
        </section>
    </header>

    <main id="example-system" class="eb-industry-page-main">
        <section class="eb-industry-page-intro" aria-labelledby="industry-page-title">
            <p class="eb-studio-eyebrow">A connected example</p>
            <h1 id="industry-page-title" data-industry-page-title>{{ $disciplines[$discipline] }} in motion.</h1>
            <p>This is a fictional Everbranch demonstration, not a live customer website or workspace. Explore the customer-facing website and the shared operating system behind it using demo-only content.</p>
        </section>

        <p class="eb-industry-page-status" aria-live="polite" data-industry-page-status>Showing a fictional website example. No live customer data is shown.</p>

        <section class="eb-industry-page-frame" data-industry-page-frame aria-label="{{ $disciplines[$discipline] }} fictional Everbranch example">
            <section id="industry-page-website" class="eb-industry-page-pane is-active" role="tabpanel" aria-labelledby="industry-page-website-tab" data-industry-page-pane="website">
                <div class="eb-industry-page-site">
                    <header><strong data-industry-page-site-brand>Everbranch example</strong><nav aria-label="Fictional website navigation"><button type="button" data-industry-page-site-nav>Services</button><button type="button" data-industry-page-site-nav>Our approach</button><button type="button" data-industry-page-site-nav>Contact</button></nav><button type="button" data-industry-page-admin>Open operations workspace <span aria-hidden="true">↗</span></button></header>
                    <div class="eb-industry-page-site-hero"><img data-industry-page-site-image alt="" /><div><p data-industry-page-site-kicker>Made for real work</p><h2 data-industry-page-site-title>Your website, built around your business.</h2><p data-industry-page-site-copy>Show the right next step without losing the relationship behind it.</p><div><button type="button" data-industry-page-site-action>Start a request</button><button type="button" data-industry-page-site-nav>See how it works</button></div></div></div>
                    <footer><span data-industry-page-site-proof>A thoughtful public front door, connected to the work behind it.</span><b data-industry-page-site-result>Fictional website example</b></footer>
                </div>
            </section>
            <section id="industry-page-workspace" class="eb-industry-page-pane" role="tabpanel" aria-labelledby="industry-page-workspace-tab" data-industry-page-pane="workspace" hidden>
                <div class="eb-industry-page-workspace">
                    <header><span aria-hidden="true">◒</span><strong data-industry-page-workspace-brand>Everbranch workspace</strong><small>Fictional workspace</small></header>
                    <div class="eb-industry-page-workspace-body">
                        <nav aria-label="Fictional workspace navigation"><button type="button" data-industry-page-nav="inbox" aria-pressed="true">Inbox</button><button type="button" data-industry-page-nav="customers" aria-pressed="false">Customers</button><button type="button" data-industry-page-nav="work" aria-pressed="false">Work</button><button type="button" data-industry-page-nav="messages" aria-pressed="false">Messages</button><button type="button" data-industry-page-nav="marketing" aria-pressed="false">Marketing</button><button type="button" data-industry-page-nav="followup" data-industry-page-fifth aria-pressed="false">Follow-up</button></nav>
                        <div class="eb-industry-page-workspace-canvas" aria-live="polite"><p data-industry-page-workspace-label>Inbox</p><h2 data-industry-page-workspace-title>A customer question is already in context.</h2><div class="eb-industry-page-workspace-cards"><article><small data-industry-page-card-one-label>REQUEST</small><strong data-industry-page-card-one>New question from a customer</strong><span data-industry-page-card-one-meta>Ready for the team</span></article><article><small data-industry-page-card-two-label>NEXT STEP</small><strong data-industry-page-card-two>Reply with the right detail</strong><span data-industry-page-card-two-meta>Assigned to the owner</span></article></div><div class="eb-industry-page-message" data-industry-page-message><span aria-hidden="true"></span><p data-industry-page-message-copy>Message activity appears here.</p><button type="button" data-industry-page-message-action>Open conversation</button></div></div>
                    </div>
                </div>
            </section>
        </section>

        <aside class="eb-industry-page-note"><strong>Ready to see this for your business?</strong><span>This is a fictional, public-only example. A launch partner gets a system shaped around the way their team actually works.</span><a href="{{ route('platform.start') }}">Request launch-partner access <span aria-hidden="true">↗</span></a></aside>
    </main>
</body>
</html>
