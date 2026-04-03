@props([
    'title' => null,
    'subtitle' => null,
    'subnav' => [],
    'actions' => [],
    'storeLabel' => null,
    'host' => null,
    'navigation' => [],
    'active' => null,
    'activeChild' => null,
    'workspaceLabel' => 'Unified workspace',
    'commandSearchEnabled' => false,
    'commandSearchEndpoint' => null,
    'commandSearchPlaceholder' => 'Search actions, pages, and Shopify tools',
])

@php
    /** @var \App\Services\Shopify\ShopifyEmbeddedUrlGenerator $embeddedUrls */
    $embeddedUrls = app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class);
    $embeddedContext = $embeddedUrls->contextQuery(
        request(),
        filled($host) ? (string) $host : null
    );

    $appendEmbeddedContext = static function (string $url) use ($embeddedContext, $embeddedUrls): string {
        return $embeddedUrls->append($url, $embeddedContext);
    };

    $activeSectionItem = collect($navigation)->first(
        fn (array $item): bool => ($item['key'] ?? null) === $active
    );
    $activeChildren = is_array($activeSectionItem) && is_array($activeSectionItem['children'] ?? null)
        ? $activeSectionItem['children']
        : [];
    $activeChildItem = collect($activeChildren)->first(
        fn (array $item): bool => ($item['key'] ?? null) === $activeChild
    );
    $activeModuleState = is_array($activeSectionItem) && is_array($activeSectionItem['module_state'] ?? null)
        ? $activeSectionItem['module_state']
        : null;
    if (is_array($activeChildItem['module_state'] ?? null)) {
        $activeModuleState = $activeChildItem['module_state'];
    }
    $activeSubnavItem = collect($subnav)->first(
        fn (array $item): bool => ! empty($item['active'])
    );
    if (is_array($activeSubnavItem) && is_array($activeSubnavItem['module_state'] ?? null)) {
        $activeModuleState = $activeSubnavItem['module_state'];
    }
@endphp

<header class="app-topbar">
    <div class="app-topbar-bar">
        <div class="app-topbar-shell">
            <div class="app-topbar-brand">
                <img
                    src="{{ asset('brand/forestry-backstage-mark.svg') }}?v=fb2"
                    alt="Forestry Backstage"
                    class="app-topbar-brand-mark"
                    loading="eager"
                    decoding="async"
                />
                <div class="app-topbar-brand-copy">
                    <strong>Forestry Backstage</strong>
                    <span>{{ $workspaceLabel }}</span>
                </div>
            </div>
            <nav class="app-topbar-nav" aria-label="Primary navigation">
                @foreach($navigation as $item)
                    <a
                        href="{{ $appendEmbeddedContext($item['href']) }}"
                        class="app-topbar-nav-link{{ ($active ?? null) === ($item['key'] ?? null) ? ' is-active' : '' }}"
                        data-embedded-prefetch-link="1"
                        data-prefetch-priority="{{ $item['prefetch_priority'] ?? 'normal' }}"
                    >
                        <span>{{ $item['label'] }}</span>
                        @if(is_array($item['module_state'] ?? null))
                            <x-tenancy.module-state-badge
                                :module-state="$item['module_state']"
                                size="sm"
                                compact
                                :hide-active="true"
                            />
                        @endif
                    </a>
                @endforeach
            </nav>
            <div class="app-topbar-right">
                @if($commandSearchEnabled)
                    <form
                        class="app-topbar-search"
                        role="search"
                        action="{{ $commandSearchEndpoint }}"
                        method="get"
                        data-command-form
                    >
                        <label class="sr-only" for="app-topbar-command-search">Search Backstage</label>
                        <input
                            id="app-topbar-command-search"
                            type="search"
                            class="app-topbar-search-input"
                            placeholder="{{ $commandSearchPlaceholder }}"
                            autocomplete="off"
                            enterkeyhint="search"
                            aria-haspopup="dialog"
                            aria-expanded="false"
                            aria-controls="shopify-global-command-menu-panel"
                            data-command-field
                        />
                        <button
                            id="app-topbar-command-search-trigger"
                            type="submit"
                            class="app-topbar-search-button"
                            aria-haspopup="dialog"
                            aria-expanded="false"
                            aria-controls="shopify-global-command-menu-panel"
                            data-command-trigger
                        >
                            Search
                        </button>
                    </form>
                @else
                    <button
                        type="button"
                        class="app-topbar-action"
                        data-command-trigger
                    >
                        Search
                    </button>
                @endif
            </div>
        </div>
    </div>

    <div class="app-topbar-page">
        <div class="app-topbar-shell">
            <div class="app-topbar-main">
                <div class="app-topbar-text">
                    @if(filled($title))
                        <div class="app-topbar-title-row">
                            <h1 class="app-topbar-title">{{ $title }}</h1>
                            @if(is_array($activeModuleState))
                                <x-tenancy.module-state-badge :module-state="$activeModuleState" size="sm" />
                            @endif
                        </div>
                    @endif
                    @if(filled($subtitle))
                        <p class="app-topbar-subtitle">{{ $subtitle }}</p>
                    @endif
                </div>

                @if(! empty($actions))
                    <div class="app-topbar-actions">
                        @foreach($actions as $action)
                            <a
                                href="{{ $appendEmbeddedContext($action['href']) }}"
                                class="app-topbar-action"
                                target="{{ str_starts_with($action['href'] ?? '', 'http') ? '_blank' : '_self' }}"
                                rel="{{ str_starts_with($action['href'] ?? '', 'http') ? 'noreferrer noopener' : 'noopener' }}"
                                data-embedded-prefetch-link="1"
                                data-prefetch-priority="{{ $action['prefetch_priority'] ?? 'normal' }}"
                                data-search-action="1"
                                data-search-id="current-view:action:{{ \Illuminate\Support\Str::slug((string) ($action['label'] ?? 'action')) }}"
                                data-search-title="{{ $action['label'] ?? '' }}"
                                data-search-subtitle="Run this action in the current view"
                                data-search-keywords="{{ $action['label'] ?? '' }},action,current view"
                                data-search-intent="open,view,manage"
                            >
                                {{ $action['label'] }}
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            @if(! empty($subnav))
                <nav class="app-topbar-subnav" aria-label="Section navigation">
                    @foreach($subnav as $item)
                        <a
                            href="{{ $appendEmbeddedContext($item['href']) }}"
                            class="app-topbar-subnav-link{{ ! empty($item['active']) ? ' is-active' : '' }}"
                            data-embedded-prefetch-link="1"
                            data-prefetch-priority="{{ $item['prefetch_priority'] ?? 'normal' }}"
                            data-search-action="1"
                            data-search-id="current-view:subnav:{{ \Illuminate\Support\Str::slug((string) ($item['key'] ?? $item['label'] ?? 'section')) }}"
                            data-search-title="{{ $item['label'] ?? '' }}"
                            data-search-subtitle="Open this section in the current view"
                            data-search-keywords="{{ $item['label'] ?? '' }},section,current view"
                            data-search-intent="open,go to,view"
                        >
                            <span>{{ $item['label'] }}</span>
                            @if(is_array($item['module_state'] ?? null))
                                <x-tenancy.module-state-badge
                                    :module-state="$item['module_state']"
                                    size="sm"
                                    compact
                                    :hide-active="true"
                                />
                            @endif
                        </a>
                    @endforeach
                </nav>
            @endif
        </div>
    </div>
</header>
