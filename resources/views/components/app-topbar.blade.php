@props([
    'title' => null,
    'subtitle' => null,
    'actions' => [],
    'storeLabel' => null,
])

<style>
    .app-topbar {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 24px;
        padding: 24px 32px;
        border-bottom: 1px solid rgba(15, 23, 42, 0.08);
        background: rgba(255, 255, 255, 0.92);
        position: sticky;
        top: 0;
        z-index: 5;
        backdrop-filter: blur(12px);
        box-shadow: 0 8px 30px rgba(15, 23, 42, 0.06);
    }

    .app-topbar-text {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .app-topbar-caption {
        font-size: 11px;
        letter-spacing: 0.24em;
        text-transform: uppercase;
        color: rgba(15, 23, 42, 0.45);
    }

    .app-topbar-title {
        margin: 0;
        font-family: "Fraunces", ui-serif, Georgia, serif;
        font-size: clamp(1.7rem, 2.2vw, 2.2rem);
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
        padding: 10px 18px;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        border: 1px solid rgba(15, 143, 97, 0.2);
        background: rgba(15, 143, 97, 0.1);
        color: #0f8f61;
        transition: background 0.2s ease, border-color 0.2s ease;
    }

    .app-topbar-action:hover {
        background: rgba(15, 143, 97, 0.16);
        border-color: rgba(15, 143, 97, 0.4);
    }

    @media (max-width: 768px) {
        .app-topbar {
            flex-direction: column;
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
