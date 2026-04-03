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
    'commandSearchPlaceholder' => 'Search actions, pages, and Shopify tools',
    'commandSearchDocuments' => [],
    'commandSearchContext' => [],
    'commandPaletteVariant' => 'legacy',
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
            :command-search-enabled="filled($commandSearchEndpoint)"
            :command-search-placeholder="$commandSearchPlaceholder"
        />
        <div class="app-shell-content">
            {{ $slot }}
        </div>
    </div>
</div>

@if(filled($commandSearchEndpoint))
    @if($commandPaletteVariant === 'shopify-actions')
        <x-shopify-global-command-menu
            :search-endpoint="$commandSearchEndpoint"
            :placeholder="$commandSearchPlaceholder"
            :context-label="$workspaceLabel"
            :documents="$commandSearchDocuments"
            :context="$commandSearchContext"
        />
    @else
        <x-app-command-palette
            :search-endpoint="$commandSearchEndpoint"
            :placeholder="$commandSearchPlaceholder"
            :context-label="$workspaceLabel"
        />
    @endif
@endif
