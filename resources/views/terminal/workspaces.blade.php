@extends('terminal.layout')
@section('title', 'Select your workspace')
@section('content')
<div class="page-heading"><div><div class="eyebrow">Everbranch terminal · Workspace selection</div><h1>Select your workspace</h1><p class="intro">Choose where you want to open the terminal shell. Your existing workspace role determines access.</p></div></div>
<section class="identity-card" aria-labelledby="signed-in-heading"><div class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($actor->name, 0, 1)) }}</div><div><span id="signed-in-heading" class="overline">Signed in as</span><strong>{{ $actor->name }}</strong><span class="identity-email">{{ $actor->email }}</span></div><span class="tag">Everbranch account</span></section>
<section aria-labelledby="workspace-list-heading"><div class="section-heading"><h2 id="workspace-list-heading">Available workspaces</h2><span class="muted">{{ $workspaces->count() }} {{ $workspaces->count() === 1 ? 'workspace' : 'workspaces' }}</span></div>
    <div class="workspace-list">
        @forelse($workspaces as $workspace)
            <article class="panel workspace-card"><div class="workspace-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 21V4h16v17M2 21h20M8 8h2m4 0h2M8 12h2m4 0h2M10 21v-5h4v5"/></svg></div><div class="workspace-details"><h3>{{ $workspace['name'] }}</h3><p><span>{{ $workspace['roleLabel'] }}</span><span class="separator" aria-hidden="true">·</span><span>{{ $workspace['modeLabel'] }}</span></p><span class="helper">Live payments unavailable</span></div><form method="POST" action="{{ route('terminal.select') }}">@csrf<input type="hidden" name="tenant_id" value="{{ $workspace['id'] }}"><button class="button primary" type="submit">Open workspace <span class="visually-hidden">{{ $workspace['name'] }}</span><span aria-hidden="true">→</span></button></form></article>
        @empty
            <div class="panel empty-state"><div class="empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 21V4h16v17M2 21h20M8 8h2m4 0h2M8 12h2m4 0h2M10 21v-5h4v5"/></svg></div><h3>No workspace is available to this account.</h3><p>Ask the workspace owner to review your active membership and account access. Signing in here does not grant a membership or change permissions.</p><p class="helper">If you signed in with the wrong account, use “Sign out of Everbranch” above, then sign in as the account that already has access.</p></div>
        @endforelse
    </div>
</section>
<div class="readiness-note"><div class="status-icon" aria-hidden="true">i</div><div><h2>Choose the workspace name you recognize.</h2><p>The terminal uses the current saved workspace name. A payment account’s legal business name can differ; payment setup and reader acceptance must be reviewed separately.</p></div></div>
@endsection
