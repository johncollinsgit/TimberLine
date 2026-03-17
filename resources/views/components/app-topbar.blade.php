@props([
    'navigation' => [],
    'active' => null,
    'title' => null,
    'subtitle' => null,
    'subnav' => [],
    'actions' => [],
    'storeLabel' => null,
])

<style>
    .app-topbar {
        position: sticky;
        top: 0;
        z-index: 5;
        background: rgba(255, 255, 255, 0.92);
        backdrop-filter: blur(10px);
        border-bottom: 1px solid rgba(15, 23, 42, 0.08);
    }

    .app-topbar-inner {
        width: min(100%, var(--app-content-width, 1160px));
        margin: 0 auto;
        padding: 0 28px;
        box-sizing: border-box;
    }

    .app-topbar-bar {
        height: 60px;
        display: flex;
        align-items: center;
    }

    .app-topbar-bar .app-topbar-inner {
        display: flex;
        align-items: center;
        gap: 18px;
    }

    .app-topbar-brand {
        display: flex;
        flex-direction: column;
        gap: 2px;
        min-width: 140px;
    }

    .app-topbar-brand strong {
        font-size: 12px;
        letter-spacing: 0.22em;
        text-transform: uppercase;
        color: rgba(15, 23, 42, 0.62);
    }

    .app-topbar-brand span {
        font-size: 12px;
        color: rgba(15, 23, 42, 0.5);
    }

    .app-topbar-nav {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 26px;
        flex: 1;
    }

    .app-topbar-link {
        text-decoration: none;
        color: rgba(15, 23, 42, 0.58);
        font-size: 13px;
        font-weight: 560;
        padding: 4px 2px 8px;
        border-bottom: 1px solid transparent;
        transition: color 0.18s ease, border-color 0.18s ease;
    }

    .app-topbar-link:hover {
        color: rgba(15, 23, 42, 0.9);
    }

    .app-topbar-link.is-active {
        color: rgba(15, 23, 42, 0.95);
        border-color: rgba(15, 143, 97, 0.8);
        font-weight: 620;
    }

    .app-topbar-right {
        min-width: 140px;
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 10px;
    }

    .app-topbar-store {
        font-size: 11px;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: rgba(15, 23, 42, 0.45);
    }

    .app-topbar-page {
        padding: 16px 0 12px;
    }

    .app-topbar-main {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 20px;
    }

    .app-topbar-text {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .app-topbar-title {
        margin: 0;
        font-size: clamp(1.4rem, 1.9vw, 2rem);
        font-weight: 650;
        letter-spacing: -0.02em;
        color: rgba(15, 23, 42, 0.98);
    }

    .app-topbar-subtitle {
        margin: 0;
        font-size: 14px;
        color: rgba(15, 23, 42, 0.6);
        line-height: 1.55;
    }

    .app-topbar-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
    }

    .app-topbar-action {
        border-radius: 8px;
        padding: 7px 11px;
        font-size: 11px;
        font-weight: 600;
        text-decoration: none;
        border: 1px solid rgba(15, 23, 42, 0.14);
        background: rgba(255, 255, 255, 0.82);
        color: rgba(15, 23, 42, 0.8);
        transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease;
    }

    .app-topbar-action:hover {
        background: rgba(255, 255, 255, 0.94);
        border-color: rgba(15, 23, 42, 0.24);
        color: rgba(15, 23, 42, 0.92);
    }

    .app-topbar-action:focus-visible {
        outline: 2px solid rgba(15, 143, 97, 0.5);
        outline-offset: 2px;
    }

    .app-topbar-subnav {
        display: flex;
        flex-wrap: wrap;
        gap: 14px;
        align-items: center;
        padding: 10px 0 0;
    }

    .app-topbar-subnav-link {
        display: inline-flex;
        align-items: center;
        text-decoration: none;
        color: rgba(15, 23, 42, 0.58);
        font-size: 12.5px;
        font-weight: 560;
        letter-spacing: 0.01em;
        padding: 6px 0 8px;
        border-bottom: 1px solid transparent;
        transition: color 0.18s ease, border-color 0.18s ease;
    }

    .app-topbar-subnav-link:hover {
        color: rgba(15, 23, 42, 0.84);
    }

    .app-topbar-subnav-link.is-active {
        color: rgba(15, 23, 42, 0.95);
        border-color: rgba(15, 143, 97, 0.8);
        font-weight: 620;
    }

    .app-topbar-subnav-link:focus-visible {
        outline: 2px solid rgba(15, 143, 97, 0.5);
        outline-offset: 3px;
        border-radius: 4px;
    }

    @media (max-width: 768px) {
        .app-topbar-inner {
            padding: 0 18px;
        }

        .app-topbar-main {
            flex-direction: column;
            align-items: flex-start;
        }

        .app-topbar-actions {
            justify-content: flex-start;
        }

        .app-topbar-nav {
            gap: 16px;
            justify-content: flex-start;
            flex-wrap: wrap;
        }

        .app-topbar-bar {
            height: auto;
            padding: 12px 0;
        }
    }
</style>

<header class="app-topbar">
    <div class="app-topbar-bar">
        <div class="app-topbar-inner">
            <div class="app-topbar-brand">
                <strong>Backstage</strong>
                <span>Forestry APP</span>
            </div>
            <nav class="app-topbar-nav" aria-label="Primary navigation">
                @foreach($navigation as $item)
                    <a
                        href="{{ $item['href'] }}"
                        class="app-topbar-link{{ ($active ?? null) === ($item['key'] ?? null) ? ' is-active' : '' }}"
                    >
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
            <div class="app-topbar-right">
                @if(filled($storeLabel))
                    <span class="app-topbar-store">{{ $storeLabel }}</span>
                @endif
            </div>
        </div>
    </div>

    <div class="app-topbar-page">
        <div class="app-topbar-inner">
            <div class="app-topbar-main">
                <div class="app-topbar-text">
                    @if(filled($title))
                        <h1 class="app-topbar-title">{{ $title }}</h1>
                    @endif
                    @if(filled($subtitle))
                        <p class="app-topbar-subtitle">{{ $subtitle }}</p>
                    @endif
                </div>

                @if(! empty($actions))
                    <div class="app-topbar-actions">
                        @foreach($actions as $action)
                            <a
                                href="{{ $action['href'] }}"
                                class="app-topbar-action"
                                target="{{ str_starts_with($action['href'] ?? '', 'http') ? '_blank' : '_self' }}"
                                rel="{{ str_starts_with($action['href'] ?? '', 'http') ? 'noreferrer noopener' : 'noopener' }}"
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
                            href="{{ $item['href'] }}"
                            class="app-topbar-subnav-link{{ ! empty($item['active']) ? ' is-active' : '' }}"
                        >
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>
            @endif
        </div>
    </div>
</header>
