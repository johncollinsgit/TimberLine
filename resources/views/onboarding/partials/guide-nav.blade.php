<nav class="eb-guide-nav" aria-label="Workspace guide">
    <a href="{{ route('dashboard') }}" @if(($active ?? '') === 'today') aria-current="page" @endif>Today</a>
    <a href="{{ route('app.start') }}" @if(($active ?? '') === 'setup') aria-current="page" @endif>Setup</a>
    <a href="{{ route('workspace.guide') }}" @if(($active ?? '') === 'explore') aria-current="page" @endif>Explore</a>
</nav>
