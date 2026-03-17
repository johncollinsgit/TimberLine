@props([
    'items' => [],
    'active' => null,
    'activeChild' => null,
])

<style>
    .app-shell-sidebar {
        background: rgba(255, 255, 255, 0.92);
        border-right: 1px solid rgba(15, 23, 42, 0.08);
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.09);
        padding: 32px 24px;
        display: flex;
        flex-direction: column;
        gap: 24px;
    }

    .app-sidebar-brand {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .app-sidebar-brand strong {
        font-size: 1rem;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: rgba(15, 23, 42, 0.65);
    }

    .app-sidebar-brand span {
        font-size: 12px;
        color: rgba(15, 23, 42, 0.45);
    }

    .app-sidebar-list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .app-sidebar-link {
        display: block;
        padding: 12px 18px;
        border-radius: 14px;
        text-decoration: none;
        font-weight: 600;
        color: rgba(15, 23, 42, 0.7);
        transition: background 0.2s ease, color 0.2s ease;
    }

    .app-sidebar-link:hover {
        background: rgba(15, 23, 42, 0.04);
        color: rgba(15, 23, 42, 0.9);
    }

    .app-sidebar-link.is-active {
        background: #0f8f61;
        color: #ffffff;
        box-shadow: 0 10px 30px rgba(15, 143, 97, 0.25);
    }

    .app-sidebar-children {
        list-style: none;
        margin: 6px 0 0;
        padding-left: 12px;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .app-sidebar-child-link {
        display: block;
        padding: 8px 16px;
        border-radius: 12px;
        font-size: 0.95rem;
        font-weight: 500;
        text-decoration: none;
        color: rgba(15, 23, 42, 0.6);
        transition: background 0.2s ease, color 0.2s ease;
    }

    .app-sidebar-child-link:hover {
        background: rgba(15, 23, 42, 0.04);
        color: rgba(15, 23, 42, 0.9);
    }

    .app-sidebar-child-link.is-active {
        background: rgba(15, 143, 97, 0.12);
        color: #0f8f61;
        font-weight: 600;
    }

    @media (max-width: 1024px) {
        .app-shell-sidebar {
            position: sticky;
            top: 0;
            z-index: 10;
            border-right: none;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 8px 25px rgba(15, 23, 42, 0.08);
            padding: 18px 20px;
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
            <li>
                <a
                    href="{{ $item['href'] }}"
                    class="app-sidebar-link{{ $active === $item['key'] ? ' is-active' : '' }}"
                >
                    {{ $item['label'] }}
                </a>

                @if(! empty($item['children']))
                    <ul class="app-sidebar-children">
                        @foreach($item['children'] as $child)
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
