@props([
    'navigation' => [],
    'pageTitle' => null,
    'pageSubtitle' => null,
    'subnav' => [],
    'actions' => [],
    'storeLabel' => null,
    'showSidebar' => false,
])

<style>
    .app-shell {
        --app-shell-bg: #f5f6f4;
        --app-main-bg: #f5f6f4;
        --app-content-width: 1160px;

        display: block;
        min-height: 100vh;
        background: var(--app-shell-bg);
    }

    .app-shell--with-sidebar {
        display: grid;
        grid-template-columns: 260px minmax(0, 1fr);
    }

    .app-shell--no-sidebar {
        display: block;
    }

    .app-shell-main {
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
        border-right: 1px solid rgba(15, 23, 42, 0.06);
    }

    .app-shell-content {
        flex: 1;
        box-sizing: border-box;
        padding: 28px 28px 64px;
        width: min(100%, var(--app-content-width));
        margin: 0 auto;
    }

    @media (max-width: 900px) {
        .app-shell--with-sidebar,
        .app-shell--no-sidebar {
            display: block;
        }

        .app-shell-sidebar {
            position: relative;
            top: auto;
            height: auto;
            min-height: 0;
            overflow-y: visible;
        }

        .app-shell-content {
            padding: 24px 20px 48px;
        }
    }

    @media (max-width: 640px) {
        .app-shell-content {
            padding: 18px 16px 40px;
        }
    }
</style>

<div class="app-shell {{ $showSidebar ? 'app-shell--with-sidebar' : 'app-shell--no-sidebar' }}">
    @if($showSidebar)
        <aside class="app-shell-sidebar">
            <x-app-sidebar
                :items="$navigation['items'] ?? []"
                :active="$navigation['activeSection'] ?? null"
                :active-child="$navigation['activeChild'] ?? null"
            />
        </aside>
    @endif
    <div class="app-shell-main">
        <x-app-topbar
            :navigation="$navigation['items'] ?? []"
            :active="$navigation['activeSection'] ?? null"
            :title="$pageTitle"
            :subtitle="$pageSubtitle"
            :subnav="$subnav"
            :actions="$actions"
            :store-label="$storeLabel"
        />
        <div class="app-shell-content">
            {{ $slot }}
        </div>
    </div>
</div>
