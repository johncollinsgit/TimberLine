<x-layouts::app title="Settings">
<div class="ws-settings">
    @include('workspace-settings.header', ['heading' => 'Settings', 'description' => 'Find the right setting. Know who and what it affects.'])
    <div class="ws-layout">
        <nav class="ws-nav" aria-label="Settings categories">
            <a href="{{ route('workspace-settings.index', ['tenant' => $tenant->slug]) }}" @if($category === '') aria-current="page" @endif>All settings</a>
            @foreach($categories as $key => $label)
                <a href="{{ route('workspace-settings.index', ['tenant' => $tenant->slug, 'category' => $key]) }}" @if($category === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
            <a href="{{ route('workspace-settings.setup', ['tenant' => $tenant->slug]) }}">Setup guide →</a>
        </nav>
        <main>
            <form class="ws-search" method="get" action="{{ route('workspace-settings.index') }}" role="search">
                <input type="hidden" name="tenant" value="{{ $tenant->slug }}">
                <label class="sr-only" for="settings-search">Search settings</label>
                <input id="settings-search" type="search" name="q" value="{{ $query }}" maxlength="100" placeholder="Try “password”, “logo” or “store connection”" autocomplete="off">
                <button class="ws-button" type="submit">Search</button>
                @if($query !== '')<a class="ws-button secondary" href="{{ route('workspace-settings.index', ['tenant' => $tenant->slug]) }}">Clear</a>@endif
            </form>
            <div id="settings-results" tabindex="-1" aria-label="Settings results" @if($query !== '') data-ws-results @endif>
                @if($query !== '')<p class="ws-muted" role="status">{{ count($results) }} {{ Str::plural('result', count($results)) }} for “{{ $query }}”</p>@endif
                @forelse($results as $result)
                    <a class="ws-card ws-result" href="{{ $result['url'] }}">
                        <div><span class="ws-scope">{{ $categories[$result['category']] }}@if($result['category'] !== 'organization') · {{ $result['scope'] }}@endif</span><h2>{{ $result['label'] }}</h2><p>{{ $result['description'] }}</p></div><span class="ws-arrow" aria-hidden="true">↗</span>
                    </a>
                @empty
                    <div class="ws-card"><h2>No matching settings</h2><p class="ws-muted">Try a simpler term, or choose another category. You’ll only see settings available to you in this workspace.</p><a href="{{ route('workspace-settings.index', ['tenant' => $tenant->slug]) }}">Browse all settings</a></div>
                @endforelse
            </div>
            <p class="ws-muted">Personal settings follow your account. Organization and branch settings belong to {{ $tenant->name }}.</p>
        </main>
    </div>
</div>
@vite('resources/js/workspace-settings.js')
</x-layouts::app>
