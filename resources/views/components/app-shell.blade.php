@props([
    'navigation' => [],
    'pageTitle' => null,
    'pageSubtitle' => null,
    'actions' => [],
    'storeLabel' => null,
])

<style>
    .app-shell {
        display: grid;
        grid-template-columns: 280px 1fr;
        min-height: 100vh;
        background: transparent;
    }

    .app-shell-main {
        padding: 0;
        background: transparent;
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }

    .app-shell-content {
        flex: 1;
        padding: 32px 34px 48px;
        max-width: 1200px;
        width: 100%;
    }

    @media (max-width: 1024px) {
        .app-shell {
            grid-template-columns: 1fr;
        }

        .app-shell-content {
            padding: 24px;
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
