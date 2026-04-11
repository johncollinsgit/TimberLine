@props([
    'items' => [],
    'active' => null,
    'activeChild' => null,
    'workspaceLabel' => 'Unified workspace',
])

@php
    $brandHref = '#';
    $homeItem = collect($items)->first(
        fn (array $item): bool => ($item['key'] ?? null) === 'home' && filled($item['href'] ?? null)
    );
    if (is_array($homeItem)) {
        $brandHref = (string) ($homeItem['href'] ?? '#');
    }
@endphp

<nav class="app-sidebar app-sidebar-panel" aria-label="App navigation">
    <a href="{{ $brandHref }}" class="app-sidebar-brand app-sidebar-brand-link">
        <img
            src="{{ asset('brand/forestry-backstage-mark.svg') }}?v=fb2"
            alt="Forestry Backstage"
            class="app-sidebar-brand-mark"
            loading="eager"
            decoding="async"
        />
        <div class="app-sidebar-brand-copy">
            <strong>Forestry Backstage</strong>
        </div>
    </a>
    <ul class="app-sidebar-list">
        @foreach($items as $item)
            @php
                $children = collect((array) ($item['children'] ?? []))
                    ->filter(fn (mixed $child): bool => is_array($child))
                    ->map(function (array $child): array {
                        unset($child['children']);

                        return $child;
                    })
                    ->values()
                    ->all();
                $hasChildren = ! empty($children);
                $isParentActive = $active === ($item['key'] ?? null) || ! empty($item['current']);
                $isChildActive = $hasChildren
                    && collect($children)->contains(
                        fn ($child) => ($child['key'] ?? null) === $activeChild || ! empty($child['current'])
                    );
                $isExpanded = $isParentActive || $isChildActive;
            @endphp
            <li>
                <a
                    href="{{ $item['href'] }}"
                    class="app-sidebar-link{{ $isParentActive ? ' is-active' : '' }}"
                >
                    <span class="app-sidebar-link-row">
                        <span>{{ $item['label'] }}</span>
                        @if(is_array($item['module_state'] ?? null))
                            <x-tenancy.module-state-badge
                                :module-state="$item['module_state']"
                                size="sm"
                                compact
                                :hide-active="true"
                            />
                        @endif
                    </span>
                </a>

                @if($hasChildren && $isExpanded)
                    <ul class="app-sidebar-children">
                        @foreach($children as $child)
                            @php($isCurrentChild = $activeChild === ($child['key'] ?? null) || ! empty($child['current']))
                            <li>
                                <a
                                    href="{{ $child['href'] }}"
                                    class="app-sidebar-child-link{{ $isCurrentChild ? ' is-active' : '' }}"
                                >
                                    <span class="app-sidebar-child-link-row">
                                        <span>{{ $child['label'] }}</span>
                                        @if(is_array($child['module_state'] ?? null))
                                            <x-tenancy.module-state-badge
                                                :module-state="$child['module_state']"
                                                size="sm"
                                                compact
                                                :hide-active="true"
                                            />
                                        @endif
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @endforeach
    </ul>
</nav>
