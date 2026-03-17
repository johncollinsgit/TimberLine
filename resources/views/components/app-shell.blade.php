@props([
    'navigation' => [],
    'pageTitle' => null,
    'pageSubtitle' => null,
    'actions' => [],
    'storeLabel' => null,
])

<style>
    .app-shell {
        --app-shell-bg: #eef2ed;
        --app-surface: rgba(255, 255, 255, 0.88);
        --app-main-bg: rgba(255, 255, 255, 0.55);

        display: grid;
        grid-template-columns: 284px minmax(0, 1fr);
        min-height: 100vh;
        background: linear-gradient(180deg, #f7faf6 0%, var(--app-shell-bg) 100%);
    }

    .app-shell-main {
        padding: 0;
        min-width: 0;
        background: var(--app-main-bg);
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }

    .app-shell-sidebar {
        position: sticky;
        top: 0;
        align-self: start;
        min-height: 100vh;
        height: 100vh;
        overflow-y: auto;
    }

    .app-shell-content {
        flex: 1;
        box-sizing: border-box;
        padding: 32px 38px 52px;
        max-width: 1240px;
        width: 100%;
    }

    @media (max-width: 900px) {
        .app-shell {
            grid-template-columns: 1fr;
        }

        .app-shell-sidebar {
            position: relative;
            top: auto;
            height: auto;
            min-height: 0;
            overflow-y: visible;
        }

        .app-shell-content {
            padding: 24px;
        }
    }

    @media (max-width: 640px) {
        .app-shell-content {
            padding: 18px 16px 32px;
        }
    }
</style>

<div class="app-shell">
    <aside class="app-shell-sidebar">
        <x-app-sidebar
            :items="$navigation['items'] ?? []"
            :active="$navigation['activeSection'] ?? null"
            :active-child="$navigation['activeChild'] ?? null"
        />
    </aside>
    <div class="app-shell-main">
        <x-app-topbar
            :title="$pageTitle"
            :subtitle="$pageSubtitle"
            :actions="$actions"
            :store-label="$storeLabel"
        />
        <div class="app-shell-content">
            {{ $slot }}
        </div>
    </div>
</div>
