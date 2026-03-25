@props([
    'items' => [],
    'active' => null,
    'activeChild' => null,
])

<style>
    .app-sidebar-panel {
        position: relative;
        min-height: 100%;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.94) 0%, rgba(247, 250, 246, 0.92) 100%);
        box-shadow: 8px 0 26px rgba(15, 23, 42, 0.05);
        padding: 24px 16px 20px;
        display: flex;
        flex-direction: column;
        gap: 18px;
    }

    .app-sidebar-panel::after {
        content: "";
        position: absolute;
        top: 18px;
        right: 0;
        width: 1px;
        height: calc(100% - 36px);
        background: linear-gradient(
            180deg,
            rgba(15, 23, 42, 0) 0%,
            rgba(15, 23, 42, 0.16) 18%,
            rgba(15, 23, 42, 0.16) 82%,
            rgba(15, 23, 42, 0) 100%
        );
    }

    .app-sidebar-brand {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 0 8px 10px;
    }

    .app-sidebar-brand-mark {
        width: 38px;
        height: 38px;
        flex: none;
        display: block;
        object-fit: contain;
        border-radius: 12px;
    }

    .app-sidebar-brand-copy {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .app-sidebar-brand-copy strong {
        font-size: 1rem;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: rgba(15, 23, 42, 0.68);
    }

    .app-sidebar-brand-copy span {
        font-size: 12px;
        color: rgba(15, 23, 42, 0.48);
    }

    .app-sidebar-list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .app-sidebar-link {
        display: block;
        padding: 9px 11px;
        border-radius: 9px;
        border: 1px solid transparent;
        text-decoration: none;
        font-size: 14px;
        font-weight: 590;
        color: rgba(15, 23, 42, 0.72);
        transition: background 0.18s ease, color 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
    }

    .app-sidebar-link:hover {
        background: rgba(15, 23, 42, 0.045);
        color: rgba(15, 23, 42, 0.9);
        border-color: rgba(15, 23, 42, 0.08);
    }

    .app-sidebar-link:focus-visible,
    .app-sidebar-child-link:focus-visible {
        outline: 2px solid rgba(15, 143, 97, 0.55);
        outline-offset: 2px;
    }

    .app-sidebar-link.is-active {
        background: rgba(15, 23, 42, 0.05);
        border-color: rgba(15, 23, 42, 0.1);
        color: rgba(15, 23, 42, 0.94);
        box-shadow:
            inset 2px 0 0 #0f8f61;
    }

    .app-sidebar-link-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    .app-sidebar-children {
        list-style: none;
        margin: 6px 0 8px 10px;
        padding: 2px 0 0 10px;
        border-left: 1px solid rgba(15, 23, 42, 0.12);
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .app-sidebar-child-link {
        display: block;
        padding: 6px 9px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 540;
        text-decoration: none;
        color: rgba(15, 23, 42, 0.6);
        transition: background 0.2s ease, color 0.2s ease;
    }

    .app-sidebar-child-link:hover {
        background: rgba(15, 23, 42, 0.035);
        color: rgba(15, 23, 42, 0.9);
    }

    .app-sidebar-child-link.is-active {
        background: rgba(15, 143, 97, 0.1);
        color: #0d6f4d;
        font-weight: 620;
    }

    .app-sidebar-child-link-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    @media (max-width: 900px) {
        .app-sidebar-panel {
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.08);
            padding: 13px 14px;
        }

        .app-sidebar-panel::after {
            top: auto;
            bottom: 0;
            width: calc(100% - 32px);
            height: 1px;
            left: 16px;
            right: 16px;
            background: linear-gradient(
                90deg,
                rgba(15, 23, 42, 0) 0%,
                rgba(15, 23, 42, 0.16) 20%,
                rgba(15, 23, 42, 0.16) 80%,
                rgba(15, 23, 42, 0) 100%
            );
        }
    }
</style>

<nav class="app-sidebar app-sidebar-panel" aria-label="App navigation">
    <div class="app-sidebar-brand">
        <img
            src="{{ asset('favicon.svg') }}?v=bs4"
            alt="Backstage"
            class="app-sidebar-brand-mark"
            loading="eager"
            decoding="async"
        />
        <div class="app-sidebar-brand-copy">
            <strong>Backstage</strong>
            <span>Forestry APP</span>
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
