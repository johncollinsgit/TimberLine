@props([
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
        padding: 16px 30px 10px;
        background: rgba(248, 251, 247, 0.9);
        backdrop-filter: blur(8px);
        box-shadow: 0 5px 16px rgba(15, 23, 42, 0.035);
    }

    .app-topbar::after {
        content: "";
        position: absolute;
        left: 30px;
        right: 30px;
        bottom: 1px;
        height: 1px;
        background: linear-gradient(
            90deg,
            rgba(15, 23, 42, 0) 0%,
            rgba(15, 23, 42, 0.18) 14%,
            rgba(15, 23, 42, 0.18) 86%,
            rgba(15, 23, 42, 0) 100%
        );
    }

    .app-topbar-main {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 20px;
        padding-bottom: 8px;
    }

    .app-topbar-text {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .app-topbar-caption {
        font-size: 10px;
        letter-spacing: 0.18em;
        text-transform: uppercase;
        color: rgba(15, 23, 42, 0.48);
    }

    .app-topbar-title {
        margin: 0;
        font-family: "Manrope", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        font-size: clamp(1.35rem, 1.85vw, 1.95rem);
        font-weight: 640;
        letter-spacing: -0.02em;
        color: #0f172a;
    }

    .app-topbar-subtitle {
        margin: 0;
        font-size: 14px;
        color: rgba(15, 23, 42, 0.66);
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
        background: rgba(255, 255, 255, 0.68);
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
        padding: 0 0 5px;
    }

    .app-topbar-subnav-link {
        display: inline-flex;
        align-items: center;
        text-decoration: none;
        color: rgba(15, 23, 42, 0.58);
        font-size: 12.5px;
        font-weight: 560;
        letter-spacing: 0.01em;
        padding: 7px 1px 6px;
        border-bottom: 2px solid transparent;
        transition: color 0.18s ease, border-color 0.18s ease;
    }

    .app-topbar-subnav-link:hover {
        color: rgba(15, 23, 42, 0.84);
    }

    .app-topbar-subnav-link.is-active {
        color: rgba(15, 23, 42, 0.95);
        border-color: rgba(15, 143, 97, 0.75);
        font-weight: 620;
    }

    .app-topbar-subnav-link:focus-visible {
        outline: 2px solid rgba(15, 143, 97, 0.5);
        outline-offset: 3px;
        border-radius: 4px;
    }

    @media (max-width: 768px) {
        .app-topbar {
            padding: 14px 16px 9px;
        }

        .app-topbar::after {
            left: 16px;
            right: 16px;
        }

        .app-topbar-main {
            flex-direction: column;
            padding-bottom: 8px;
        }

        .app-topbar-actions {
            justify-content: flex-start;
        }
    }
</style>

<header class="app-topbar">
    <div class="app-topbar-main">
        <div class="app-topbar-text">
            @if(filled($storeLabel))
                <p class="app-topbar-caption">{{ $storeLabel }}</p>
            @endif
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
</header>
