@if(config('team_work.search_enabled'))
<link rel="stylesheet" href="{{ asset('css/everbranch-search.css') }}?v={{ filemtime(public_path('css/everbranch-search.css')) }}">
<header class="eb-workspace-header" aria-label="Workspace search">
    <span class="eb-header-start">{{ $teamAppName ?? 'Everbranch Teams' }}</span>
    <div id="eb-teams-search" data-search-url="{{ route('team.search', ['tenant' => $tenant->slug]) }}"></div>
    <span class="eb-header-end">{{ $tenant->name }}</span>
</header>
<script src="{{ asset('js/everbranch-search.js') }}?v={{ filemtime(public_path('js/everbranch-search.js')) }}" defer></script>
<script src="{{ asset('js/team-search.js') }}?v={{ filemtime(public_path('js/team-search.js')) }}" defer></script>
@endif
