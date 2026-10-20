<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $tabs[$tab] }} — Markets & Makers preview</title>
    @vite('resources/css/marketplace.css')
    <style>
        .preview-header{display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap;padding:22px 5%;border-bottom:1px solid var(--line)}
        .preview-header p{font-size:12px;margin:0}.preview-name{font:28px Georgia,serif;letter-spacing:-.6px}.preview-sample{display:grid;grid-template-columns:1fr 1fr;gap:24px}.preview-sample img{width:100%;height:250px;border-radius:11px}.preview-sample h3{margin:20px 0 10px}.preview-label{display:block;font-size:12px;font-weight:600;margin-bottom:8px}.preview-note{font-size:13px;max-width:740px}.preview-checks{display:grid;gap:12px;list-style:none;padding:0;font-size:14px}.preview-checks li{padding:14px;background:var(--paper);border-radius:8px}.preview-footer{font-size:12px;padding:25px 5%;border-top:1px solid var(--line)}.preview-header a,.section-tabs a{min-height:44px;display:inline-flex;align-items:center}.section-tabs a[aria-current=page]{background:var(--leaf);color:var(--white)}
        @media(max-width:700px){.preview-sample{grid-template-columns:1fr}.preview-header{align-items:flex-start}.preview-header .button{width:100%}.section-tabs{gap:5px}.section-tabs a{padding:10px 12px}.page-shell{padding-top:28px}}
    </style>
</head>
<body class="workspace-screen">
<a class="skip-link" href="#main">Skip to content</a>
<div class="announcement">Private account preview · Example data · Payments and public storefront off</div>
<header class="preview-header">
    <div><a class="preview-name" href="{{ route('marketplace.preview') }}">markets & makers</a><p>Connected by Everbranch</p></div>
    <a class="button secondary compact" href="{{ route('dashboard') }}">Back to Everbranch</a>
</header>
<main id="main" class="page-shell">
    <span class="eyebrow">YOUR PRIVATE PREVIEW</span>
    <h1>Your marketplace, taking shape.</h1>
    <p class="preview-note">Browse the workspace with fictional examples. Nothing here changes a business, connects a provider, or collects money. Your existing Everbranch workspaces stay separate.</p>
    <nav class="section-tabs" aria-label="Marketplace preview sections">
        @foreach($tabs as $key => $label)
            <a href="{{ route('marketplace.preview', ['tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <section aria-labelledby="section-title">
        <span class="preview-label">Example workspace · Read only</span>
        <h2 id="section-title">{{ $tabs[$tab] }}</h2>
        @if($tab === 'overview')
            <div class="metrics">
                <div class="metric"><span>Example makers</span><strong>2</strong><small>Fictional businesses</small></div>
                <div class="metric"><span>Public storefront</span><strong>Off</strong><small>Private to your account</small></div>
                <div class="metric"><span>Payment collection</span><strong>Off</strong><small>No purchases or payouts</small></div>
            </div>
            <div class="panel"><h3>Explore the whole workspace</h3><p>Meet example makers, browse products, review sales and pickup flows, and see the separate launch checks. Use the sections above to look around.</p><a class="button compact" href="{{ route('marketplace.preview', ['tab' => 'makers']) }}">Meet the example makers</a></div>
        @elseif(in_array($tab, ['makers', 'listings']))
            <div class="preview-sample">
                <article class="panel"><img src="{{ asset('images/marketplace/studio.jpg') }}" alt="A light-filled creative studio" width="600" height="400"><h3>{{ $tab === 'makers' ? 'Cedar & Clay Studio' : 'Handmade ceramic collection' }}</h3><p>Fictional example · Ceramics and everyday goods</p><span class="badge">Preview only</span></article>
                <article class="panel"><img src="{{ asset('images/marketplace/retail.jpg') }}" alt="A thoughtfully arranged independent shop" width="600" height="400"><h3>{{ $tab === 'makers' ? 'Willow Workshop' : 'Small-batch home collection' }}</h3><p>Fictional example · Gifts and homewares</p><span class="badge">Preview only</span></article>
            </div>
        @elseif($tab === 'markets')
            <article class="panel"><span class="badge">Example draft</span><h3>Neighborhood Makers Market</h3><p>Preview a market lineup, maker participation and collection details. No event is published and no location or date is reserved.</p><ul class="preview-checks"><li>Cedar & Clay Studio · Example maker</li><li>Willow Workshop · Example maker</li></ul></article>
        @elseif($tab === 'pickups')
            <article class="panel"><h3>Keep each pickup clear</h3><p>The merchant workspace tracks market locations, approved makers, collection windows and order handoff. There are no real pickup orders in this preview.</p><span class="badge">No collection scheduled</span></article>
        @elseif($tab === 'rates')
            <article class="panel"><h3>Clear maker agreements</h3><p>Each maker accepts versioned terms before selling. The marketplace's default fee is 15%; actual merchant and maker terms still need review and acceptance. This preview creates no agreement or charge.</p><span class="badge">No agreement accepted</span></article>
        @elseif($tab === 'payments')
            <article class="panel"><h3>Payment collection is off</h3><p>This preview cannot connect merchant accounts, take a payment, issue a refund, or send a payout. Cards, wallets and PayPal each require their own verified merchant and acceptance checks.</p><ul class="preview-checks"><li>Cards and device wallets · Not connected</li><li>Shop Pay Wallet · Not connected</li><li>PayPal · Not connected</li></ul></article>
        @elseif($tab === 'connections')
            <article class="panel"><h3>Your businesses stay separate</h3><p>No other workspace's products, customers, provider accounts or credentials are loaded here. Shared store links do not mean catalog publishing or synchronization.</p><span class="badge">No connection made</span></article>
        @elseif($tab === 'activity')
            <article class="panel"><h3>Look around without changing records</h3><p>Switching preview sections does not create merchant activity, orders, inventory changes, messages or provider requests.</p><span class="badge">Read-only preview</span></article>
        @else
            <article class="panel"><h3>{{ match($tab) { 'orders' => 'See how sales will come together', 'customers' => 'Keep customer details private', 'discounts' => 'Plan promotions with clear funding', 'statements' => 'Review maker earnings before payment', default => 'Explore your workspace' } }}</h3><p>{{ match($tab) { 'orders' => 'The merchant workspace brings orders, fulfillment and refunds into one view. No real sale or customer order is loaded in this preview.', 'customers' => 'Only authorized merchant operations can show actual buyers and their purchases. This account preview contains no customer records.', 'discounts' => 'Marketplace-funded promotions preserve accepted maker terms. This preview does not create discounts or change prices.', 'statements' => 'Accepted rates, order evidence and reviewed supplier records support statements. This preview creates no balance, bank instruction or payout.', default => 'This section is available as a read-only example.' } }}</p><span class="badge">Example data only</span></article>
        @endif
    </section>
    <aside class="panel" aria-label="Before real trading"><h3>Before real trading</h3><p>A dedicated merchant workspace, domain, policies, tax and pickup setup, approved makers, and actual provider checks are separate next steps. Opening this preview does not enable them.</p></aside>
</main>
<footer class="preview-footer">Markets & Makers · Private account preview · No public storefront or payment collection</footer>
</body>
</html>
