@extends('terminal.layout')
@section('title', 'Workspace terminal')
@section('content')
<div class="page-heading register-heading"><div><div class="eyebrow">Everbranch terminal · Selected workspace</div><h1>{{ $workspace['name'] }}</h1><p class="intro">Your workspace access is confirmed. This shell does not collect payments.</p></div><form method="POST" action="{{ route('terminal.clear') }}">@csrf<button type="submit" class="button secondary">Switch workspace</button></form></div>
<div class="register-grid">
    <section class="panel access-panel" aria-labelledby="access-heading"><div class="panel-top"><span class="section-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6l-8-3Z"/><path d="m8 12 3 3 5-6"/></svg></span><span class="tag">Workspace selected</span></div><h2 id="access-heading">Your terminal session</h2>
        <div class="identity-inline"><div class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($actor->name, 0, 1)) }}</div><div><span class="overline">Signed in as</span><strong>{{ $actor->name }}</strong><span class="identity-email">{{ $actor->email }}</span></div></div>
        <dl class="details"><div><dt>Workspace</dt><dd>{{ $workspace['name'] }}</dd></div><div><dt>Your role</dt><dd>{{ $workspace['roleLabel'] }}</dd></div><div><dt>Workspace mode</dt><dd>{{ $workspace['modeLabel'] }}</dd></div><div><dt>Terminal sign-in</dt><dd>{{ $expiresInMinutes }} minute idle limit</dd></div></dl>
        <p class="helper">This terminal sign-in expires after {{ $expiresInMinutes }} minutes of inactivity or eight hours total and signs this Everbranch browser out. You must sign in again after expiry. Switching workspaces changes only the terminal selection while your sign-in remains valid.</p>
        <div class="shared-device"><strong>Using a shared counter or browser?</strong><p>Use “Sign out of Everbranch” when finished. That signs out this terminal and the shared first-party field-app session. Other Everbranch browser sessions may remain signed in.</p></div>
    </section>
    <section class="panel readiness-panel" aria-labelledby="terminal-ready-heading"><div class="panel-top"><span class="section-icon muted-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="3"/><path d="M8 7h8M8 11h8M9 17h6"/></svg></span><span class="status unavailable">Live payments unavailable</span></div><h2 id="terminal-ready-heading">The payment lane is not activated.</h2><p class="muted">There is no live cart, charge, cash recording or refund action in this shell.</p><ul class="readiness-list"><li><strong>Merchant setup</strong><span>Requires its own account and business review.</span></li><li><strong>Reader and device</strong><span>Terminal compatibility and actual provider acceptance are unverified.</span></li><li><strong>Counter operations</strong><span>Tax, staff permissions and physical peripherals need acceptance before rollout.</span></li></ul>
        @if($practiceUrl)<div class="practice-card"><strong>Local/testing simulation is available.</strong><p>Practice uses the separately authorized simulation register. No real money moves.</p><a class="button secondary" href="{{ $practiceUrl }}">Open local simulation register</a></div>@endif
    </section>
</div>
@if($canManagePayments)
    <section class="panel connection-panel" aria-labelledby="saved-connection-heading"><div class="section-heading"><div><div class="eyebrow">Owner / admin saved evidence</div><h2 id="saved-connection-heading">Saved Stripe connection</h2></div><span class="status">{{ $connection['label'] ?? 'No saved connection' }}</span></div>
        @if($connection && $connection['present'])
            <p class="muted">{{ $connection['identityLabel'] }}</p><dl class="connection-grid"><div><dt>Online card capability saved</dt><dd>{{ $connection['cardsEnabled'] ? 'Enabled in saved status' : 'Not enabled in saved status' }}</dd></div><div><dt>Payout capability saved</dt><dd>{{ $connection['payoutsEnabled'] ? 'Enabled in saved status' : 'Not enabled in saved status' }}</dd></div><div><dt>Online-payment checks</dt><dd>{{ $connection['onlineChecksReady'] ? 'Saved checks ready' : 'Saved checks incomplete' }}</dd></div><div><dt>Saved routing</dt><dd>{{ $connection['routingLabel'] }}</dd></div><div><dt>Last checked</dt><dd>{{ $connection['checkedAt'] ?? 'No saved check timestamp' }}</dd></div></dl>
        @else
            <p class="muted">No payment-account connection is saved for the selected workspace.</p>
        @endif
        <div class="connection-disclaimer"><strong>A saved Stripe connection does not verify Terminal or device readiness.</strong><p>These are saved online-payment facts. This page does not refresh Stripe, connect an account, start onboarding or activate a reader. The legal Stripe business name is not stored here. An owner must confirm the provider’s business identity before live use.</p></div>
    </section>
@else
    <section class="readiness-note"><div class="status-icon" aria-hidden="true">i</div><div><h2>Payment setup belongs with the workspace owner.</h2><p>Your existing role is shown above. Ask an owner or administrator to review merchant setup and payment readiness. This terminal shell does not grant payment-management permissions.</p></div></section>
@endif
@endsection
