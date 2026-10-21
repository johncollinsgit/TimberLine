@extends('pos.layout')
@section('title', 'Payments & register')
@section('content')
    <section class="hero" aria-labelledby="setup-title">
        <div class="hero-copy">
            <div class="eyebrow">Payments & register</div>
            <h1 id="setup-title">Prepare your checkout.</h1>
            <p>Practice merchandise sales, understand your setup, and see the next steps before a live pilot.</p>
            <div class="actions">
                @if($registers->isNotEmpty())<a class="button" href="{{ route('pos.register', ['register' => $registers->first()->id, 'tenant' => $tenant->slug]) }}">Open practice register <span aria-hidden="true" style="margin-left:.7rem">→</span></a>
                @else<a class="button secondary" href="#your-registers">See register setup</a>@endif
                <a class="button secondary" href="#setup-checklist">View next steps</a>
            </div>
            <p class="helper-copy" style="margin-bottom:0">Simulation is available here. Live checkout remains off.</p>
        </div>
        <figure>
            <img src="{{ asset('/images/pos/counter-illustration.webp') }}" alt="Illustration of a fictional shop counter with packaged merchandise and a generic checkout device" width="1600" height="1067" fetchpriority="high">
            <figcaption>AI-generated illustration — reader compatibility is pending review.</figcaption>
        </figure>
    </section>

    <div class="section-heading"><h2>Know what is ready</h2><p class="muted">Clear boundaries for this practice environment.</p></div>
    <div class="setup-grid">
        <section class="panel setup-card">
            <div class="card-icon"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 5h16v12H4zM8 21h8M12 17v4M8 9l2 2 5-4"/></svg></div>
            <span class="status succeeded">Available in simulation</span><h2>Practice checkout</h2>
            <p class="muted">Rehearse merchandise baskets, card and cash outcomes, exact SKU entry, receipts and manager refunds.</p>
            <p class="helper-copy">No card details or real payments are collected.</p>
        </section>
        <section class="panel setup-card">
            <div class="card-icon"><svg aria-hidden="true" viewBox="0 0 24 24"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M10 6h4M10 16h4M11 19h2"/></svg></div>
            <span class="status unknown">Acceptance pending</span><h2>Physical reader</h2>
            <p class="muted">The physical Terminal integration is unavailable in this build. Activation and the exact device still need verification.</p>
            <p class="helper-copy">A saved Stripe connection does not verify live POS.</p>
        </section>
        <section class="panel setup-card">
            <div class="card-icon"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M8 4H5v17h14V4h-3M8 2h8v5H8zM8 11h8M8 15h5"/></svg></div>
            <span class="status">Before a live pilot</span><h2>Operational review</h2>
            <p class="muted">Agree the merchandise catalog, tax policy, receipt needs and staff permissions. Then verify the physical setup.</p>
            <p class="helper-copy">Postage, carrier rates, shipping-label purchase and service workflows need separate acceptance.</p>
        </section>
    </div>

    <div class="grid" style="margin-top:1.4rem">
        <div class="stack">
            <section class="panel" aria-labelledby="sign-in-heading">
                @if($canManagePayments)
                    <div class="page-heading" style="margin-bottom:1rem"><div><div class="eyebrow">Business owner & admin</div><h2 id="sign-in-heading" style="margin-top:.5rem">Your Stripe setup</h2></div><span class="status">{{ $connection['label'] }}</span></div>
                    <p class="helper-copy">Use your business's own Stripe account. Continue through the existing secure payment setup page; Stripe handles its sign-in, identity and payout details.</p>
                    <div class="connection-row"><span>Business details</span><strong>{{ $connection['detailsSubmitted'] ? 'Submitted in saved status' : 'Not yet confirmed' }}</strong></div>
                    <div class="connection-row"><span>Card payments</span><strong>{{ $connection['cardsEnabled'] ? 'Enabled in saved status' : 'Not yet confirmed' }}</strong></div>
                    <div class="connection-row"><span>Payouts</span><strong>{{ $connection['payoutsEnabled'] ? 'Enabled in saved status' : 'Not yet confirmed' }}</strong></div>
                    <div class="connection-row"><span>Online-payment checks</span><strong>{{ $connection['onlineChecksReady'] ? 'Saved checks passed' : 'Not complete' }}</strong></div>
                    <p class="helper-copy">{{ $connection['checkedAt'] ? 'Last saved check: '.$connection['checkedAt'].'.' : 'No saved verification timestamp is available.' }} This page reads saved status only. It does not refresh Stripe or confirm in-person payments.</p>
                    @if($paymentSetupUrl)
                        <a class="button secondary" href="{{ $paymentSetupUrl }}">Open secure payment setup <span aria-hidden="true" style="margin-left:.7rem">↗</span></a>
                    @else
                        <p class="helper-copy"><strong>Payment setup is not enabled for this workspace.</strong> Coordinate that review with your Everbranch operator before a live pilot.</p>
                    @endif
                    <p class="helper-copy" style="margin-bottom:0">Your Stripe password belongs in Stripe's own sign-in. Everbranch does not collect it here.</p>
                @else
                    <div class="eyebrow">For staff</div><h2 id="sign-in-heading" style="margin-top:.5rem">Your Everbranch sign-in</h2>
                    <p class="helper-copy">Use your own Everbranch account to open an assigned practice register. Your business owner handles Stripe separately; you do not need their Stripe sign-in.</p>
                    <div class="login-name"><small>Signed in to Everbranch</small><strong>{{ auth()->user()?->name }}</strong></div>
                    <a class="button secondary" href="{{ route('profile.edit') }}">Your account & sign-in</a>
                    <p class="helper-copy" style="margin-bottom:0">If a register is missing, ask the workspace owner to review your assignment. Refunds require a manager.</p>
                @endif
            </section>
            <section aria-labelledby="your-registers-heading" id="your-registers">
                <div class="section-heading" style="margin-top:0"><h2 id="your-registers-heading">Your practice registers</h2><p class="muted">Up to 20 active, assigned registers.</p></div>
                @forelse($registers as $practiceRegister)
                    <article class="panel register-card"><div><h3>{{ $practiceRegister->name }}</h3><p class="muted">Merchandise checkout · Simulated smart reader</p></div><a class="button secondary" href="{{ route('pos.register', ['register' => $practiceRegister->id, 'tenant' => $tenant->slug]) }}">Open register <span aria-hidden="true" style="margin-left:.6rem">→</span></a></article>
                @empty
                    <div class="empty-state"><h3>No practice register is available to you yet.</h3><p class="muted">Ask your workspace owner to review the register, location, catalog and your assignment. Existing account access does not assign a register.</p></div>
                @endforelse
            </section>
        </div>
        <aside class="stack">
            <section class="panel" id="setup-checklist" aria-labelledby="checklist-heading"><h2 id="checklist-heading">Your next steps</h2>
                <ol class="step-list"><li><strong>Sign in as yourself</strong><p>Keep staff Everbranch access separate from the owner's Stripe account.</p></li><li><strong>Try the practice register</strong><p>Use merchandise, check the total, then rehearse approval, a lost response and a refund.</p></li><li><strong>Review the live pilot</strong><p>Confirm the merchant, exact reader, tax, receipts and any peripherals before collecting real payments.</p></li></ol>
            </section>
            <section class="panel" aria-labelledby="practice-tools-heading"><h2 id="practice-tools-heading">Practice tools</h2><div class="capabilities"><span>Card rehearsal</span><span>Cash rehearsal</span><span>Exact SKU entry</span><span>Printable receipts</span><span>Online only</span><span>Physical reader unavailable</span></div><p class="helper-copy" style="margin-bottom:0">Additional processors are planned and unavailable in this build.</p></section>
            <section class="panel" aria-labelledby="help-heading"><h2 id="help-heading">A little help at the counter</h2>
                <details class="help"><summary>Which sign-in do I use?</summary><p>Staff use their own Everbranch account. An authorized owner or admin manages the business's payment account through its separate provider sign-in.</p></details>
                <details class="help"><summary>What if the reader stops responding?</summary><p>Open the existing sale and choose Check existing payment. Keep its original request until an outcome is confirmed. Avoid a replacement sale while it is uncertain.</p></details>
                <details class="help"><summary>Can I use a phone or an existing reader?</summary><p>Device compatibility and Tap to Pay have not been verified for this workspace. The practice register uses a simulated reader and does not activate a phone or terminal.</p></details>
            </section>
        </aside>
    </div>
@endsection
