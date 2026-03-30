@props([
    'items' => [],
    'active' => null,
    'activeChild' => null,
])

<nav class="app-sidebar app-sidebar-panel" aria-label="App navigation">
    <div class="app-sidebar-brand">
        <img
            src="{{ asset('brand/forestry-backstage-mark.svg') }}"
            alt="Forestry Backstage"
            class="app-sidebar-brand-mark"
            loading="eager"
            decoding="async"
        />
        <div class="app-sidebar-brand-copy">
            <strong>Forestry Backstage</strong>
            <span>Shopify workspace</span>
        </div>
    </div>
    <ul class="app-sidebar-list">
        @foreach($items as $item)
            @php
                $children = $item['children'] ?? [];
                $hasChildren = ! empty($children);
                $isParentActive = $active === ($item['key'] ?? null);
                $isChildActive = $hasChildren
                    && collect($children)->contains(fn ($child) => ($child['key'] ?? null) === $activeChild);
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
                            <li>
                                <a
                                    href="{{ $child['href'] }}"
                                    class="app-sidebar-child-link{{ $activeChild === $child['key'] ? ' is-active' : '' }}"
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
