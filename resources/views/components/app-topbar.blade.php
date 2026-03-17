@props([
    'title' => null,
    'subtitle' => null,
    'actions' => [],
    'storeLabel' => null,
])

<style>
    .app-topbar {
        position: sticky;
        top: 0;
        z-index: 5;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 24px;
        padding: 22px 34px 18px;
        background: rgba(248, 251, 247, 0.82);
        backdrop-filter: blur(10px);
        box-shadow: 0 6px 18px rgba(15, 23, 42, 0.04);
    }

    .app-topbar::after {
        content: "";
        position: absolute;
        left: 34px;
        right: 34px;
        bottom: 0;
        height: 1px;
        background: linear-gradient(
            90deg,
            rgba(15, 23, 42, 0) 0%,
            rgba(15, 23, 42, 0.18) 14%,
            rgba(15, 23, 42, 0.18) 86%,
            rgba(15, 23, 42, 0) 100%
        );
    }

    .app-topbar-text {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .app-topbar-caption {
        font-size: 11px;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: rgba(15, 23, 42, 0.48);
    }

    .app-topbar-title {
        margin: 0;
        font-family: "Manrope", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        font-size: clamp(1.75rem, 2.2vw, 2.35rem);
        font-weight: 640;
        letter-spacing: -0.02em;
        color: #0f172a;
    }

    .app-topbar-subtitle {
        margin: 0;
        font-size: 15px;
        color: rgba(15, 23, 42, 0.66);
        line-height: 1.6;
    }

    .app-topbar-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
    }

    .app-topbar-action {
        border-radius: 999px;
        padding: 9px 16px;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        border: 1px solid rgba(15, 23, 42, 0.14);
        background: rgba(255, 255, 255, 0.74);
        color: rgba(15, 23, 42, 0.8);
        transition: background 0.2s ease, border-color 0.2s ease;
    }

    .app-topbar-action:hover {
        background: rgba(255, 255, 255, 0.94);
        border-color: rgba(15, 23, 42, 0.24);
    }

    .app-topbar-action:focus-visible {
        outline: 2px solid rgba(15, 143, 97, 0.5);
        outline-offset: 2px;
    }

    @media (max-width: 768px) {
        .app-topbar {
            flex-direction: column;
            padding: 16px 16px 14px;
        }

        .app-topbar::after {
            left: 16px;
            right: 16px;
        }

        .app-topbar-actions {
            justify-content: flex-start;
        }
    }
</style>

<header class="app-topbar">
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
</header>
