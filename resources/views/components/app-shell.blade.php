@props([
    'navigation' => [],
    'pageTitle' => null,
    'pageSubtitle' => null,
    'subnav' => [],
    'actions' => [],
    'storeLabel' => null,
    'host' => null,
    'showSidebar' => true,
    'commandSearchEndpoint' => null,
    'commandSearchPlaceholder' => 'Search the workspace',
    'workspaceLabel' => 'Unified workspace',
])

<div class="app-shell {{ $showSidebar ? 'app-shell--with-sidebar' : 'app-shell--no-sidebar' }}">
    @if($showSidebar)
        <aside class="app-shell-sidebar">
            <x-app-sidebar
                :items="$navigation['items'] ?? []"
                :active="$navigation['activeSection'] ?? null"
                :active-child="$navigation['activeChild'] ?? null"
                :workspace-label="$workspaceLabel"
            />
        </aside>
    @endif
    <div class="app-shell-main">
        <x-app-topbar
            :navigation="$navigation['items'] ?? []"
            :active="$navigation['activeSection'] ?? null"
            :active-child="$navigation['activeChild'] ?? null"
            :host="$host"
            :title="$pageTitle"
            :subtitle="$pageSubtitle"
            :subnav="$subnav"
            :actions="$actions"
            :store-label="$storeLabel"
            :workspace-label="$workspaceLabel"
        />
        <div class="app-shell-content">
            {{ $slot }}
        </div>
    </div>
</div>

@if(filled($commandSearchEndpoint))
    <x-app-command-palette
        :search-endpoint="$commandSearchEndpoint"
        :placeholder="$commandSearchPlaceholder"
        :context-label="$workspaceLabel"
    />
@endif
