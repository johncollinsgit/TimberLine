<nav class="eb-guide-nav" aria-label="Workspace guide">
    <a href="{{ route('dashboard') }}" @if(($active ?? '') === 'today') aria-current="page" @endif>Today</a>
    <a href="{{ route('app.start') }}" @if(($active ?? '') === 'setup') aria-current="page" @endif>Setup</a>
    <a href="{{ route('workspace.guide') }}" @if(($active ?? '') === 'explore') aria-current="page" @endif>Explore</a>
    @if(isset($tenant) && data_get($tenant->accessProfile?->metadata, 'self_service_registration') === true && auth()->user()?->tenants()->where('tenants.id', $tenant->id)->wherePivot('role', 'owner')->wherePivot('membership_active', true)->exists())
        <a href="{{ route('self-service.purchase', ['tenant' => $tenant->slug]) }}">Your plan</a>
    @endif
</nav>
