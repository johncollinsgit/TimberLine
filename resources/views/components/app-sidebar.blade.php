@props([
    'items' => [],
    'active' => null,
    'activeChild' => null,
])

<style>
    .app-shell-sidebar {
        position: relative;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.95) 0%, rgba(248, 251, 248, 0.92) 100%);
        box-shadow: 10px 0 34px rgba(15, 23, 42, 0.08);
        padding: 26px 20px 24px 22px;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .app-shell-sidebar::after {
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
        flex-direction: column;
        gap: 4px;
        padding: 0 6px 8px;
    }

    .app-sidebar-brand strong {
        font-size: 1.03rem;
        letter-spacing: 0.18em;
        text-transform: uppercase;
        color: rgba(15, 23, 42, 0.72);
    }

    .app-sidebar-brand span {
        font-size: 13px;
        color: rgba(15, 23, 42, 0.5);
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
        padding: 10px 14px;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 600;
        color: rgba(15, 23, 42, 0.72);
        transition: background 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
    }

    .app-sidebar-link:hover {
        background: rgba(15, 23, 42, 0.05);
        color: rgba(15, 23, 42, 0.9);
    }

    .app-sidebar-link:focus-visible,
    .app-sidebar-child-link:focus-visible {
        outline: 2px solid rgba(15, 143, 97, 0.55);
        outline-offset: 2px;
    }

    .app-sidebar-link.is-active {
        background: linear-gradient(135deg, #157d61 0%, #1c9972 100%);
        color: #ffffff;
        box-shadow:
            inset 0 0 0 1px rgba(255, 255, 255, 0.26),
            0 10px 20px rgba(15, 143, 97, 0.2);
    }

    .app-sidebar-children {
        list-style: none;
        margin: 8px 0 10px 12px;
        padding: 4px 0 0 12px;
        border-left: 1px solid rgba(15, 23, 42, 0.12);
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .app-sidebar-child-link {
        display: block;
        padding: 7px 10px;
        border-radius: 10px;
        font-size: 0.92rem;
        font-weight: 550;
        text-decoration: none;
        color: rgba(15, 23, 42, 0.62);
        transition: background 0.2s ease, color 0.2s ease;
    }

    .app-sidebar-child-link:hover {
        background: rgba(15, 23, 42, 0.04);
        color: rgba(15, 23, 42, 0.9);
    }

    .app-sidebar-child-link.is-active {
        background: rgba(15, 143, 97, 0.14);
        color: #0f8f61;
        font-weight: 600;
    }

    @media (max-width: 900px) {
        .app-shell-sidebar {
            box-shadow: 0 8px 25px rgba(15, 23, 42, 0.08);
            padding: 14px 16px;
        }

        .app-shell-sidebar::after {
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

<nav class="app-sidebar" aria-label="App navigation">
    <div class="app-sidebar-brand">
        <strong>Backstage</strong>
        <span>Forestry APP</span>
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
                    {{ $item['label'] }}
                </a>

                @if($hasChildren && $isExpanded)
                    <ul class="app-sidebar-children">
                        @foreach($children as $child)
                            <li>
                                <a
                                    href="{{ $child['href'] }}"
                                    class="app-sidebar-child-link{{ $activeChild === $child['key'] ? ' is-active' : '' }}"
                                >
                                    {{ $child['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @endforeach
    </ul>
</nav>
