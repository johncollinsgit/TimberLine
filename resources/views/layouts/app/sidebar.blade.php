<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  @include('partials.head')
</head>

@php
  $prefs = is_array(auth()->user()?->ui_preferences ?? null) ? auth()->user()->ui_preferences : [];
  $wideLayout = !empty($prefs['wide_layout']);
  $compactTables = !empty($prefs['compact_tables']);
@endphp
<body data-mf-theme="forestry-backstage" class="min-h-screen antialiased mf-app-shell {{ $wideLayout ? 'mf-wide' : '' }} {{ $compactTables ? 'mf-compact' : '' }}">
@php
  $user = auth()->user();
  $isAdmin = $user?->isAdmin() ?? true;
  $isManager = $user?->isManager() ?? false;
  $isPouring = $user?->isPouring() ?? false;
  $canAccessOps = $isAdmin || $isManager;
  $canAccessMarketing = $user?->canAccessMarketing() ?? false;
  $navigationShell = app(\App\Services\Navigation\UnifiedAppNavigationService::class)->build(request(), $user);
  $experienceProfile = is_array($navigationShell['experience_profile'] ?? null) ? $navigationShell['experience_profile'] : [];
  $workspace = is_array($experienceProfile['workspace'] ?? null) ? $experienceProfile['workspace'] : [];
  $orderedSidebarItems = collect((array) ($navigationShell['items'] ?? []));
  $adminSubItems = (array) ($navigationShell['admin_sub_items'] ?? []);
  $marketingSubGroups = (array) ($navigationShell['marketing_sub_groups'] ?? []);
  $birthdaySubGroups = (array) ($navigationShell['birthday_sub_groups'] ?? []);
  $flattenNestedItems = static function (array $groups): array {
      return collect($groups)
          ->flatMap(function (array $group): array {
              return collect((array) ($group['items'] ?? []))
                  ->filter(fn (mixed $item): bool => is_array($item))
                  ->values()
                  ->all();
          })
          ->values()
          ->all();
  };
  $marketingSubItems = $flattenNestedItems($marketingSubGroups);
  $birthdaySubItems = $flattenNestedItems($birthdaySubGroups);
  $childMenuItems = [
      'administration' => $adminSubItems,
      'marketing' => $marketingSubItems,
      'birthdays' => $birthdaySubItems,
  ];
  $wikiSectionItems = (array) ($navigationShell['wiki_sections'] ?? []);
  $wikiSectionsActive = collect($wikiSectionItems)->contains(fn (array $item): bool => (bool) ($item['current'] ?? false));
  $quickActions = (array) ($navigationShell['quick_actions'] ?? []);
  $opsAttention = is_array($navigationShell['ops_attention'] ?? null) ? $navigationShell['ops_attention'] : [];
  $unresolvedExceptions = (int) ($opsAttention['unresolved_exceptions'] ?? 0);
  $latestRun = $opsAttention['latest_run'] ?? null;
  $hrefDashboard = route('dashboard');
  $workspaceLabel = (string) ($workspace['label'] ?? 'Unified workspace');
  $workspaceSubtitle = (string) ($workspace['subtitle'] ?? 'One product surface that adapts to the tenant in front of it.');
  $commandPlaceholder = (string) ($workspace['command_placeholder'] ?? 'Search the workspace');
  $marketingActive = request()->routeIs('marketing.*') || request()->is('marketing*');
  $birthdaysActive = request()->routeIs('birthdays.*') || request()->is('birthdays*');
  $adminActive = request()->routeIs('admin.*') || request()->is('admin*');
  $childMenuOpenState = [
      'administration' => $adminActive,
      'marketing' => $marketingActive,
      'birthdays' => $birthdaysActive,
  ];
@endphp

<div class="min-h-screen flex">

  {{-- Sidebar --}}
  <flux:sidebar
    id="app-sidebar"
    sticky
    :collapsible="true"
    class="relative overflow-hidden mf-transition border-e mf-sidebar-theme-shell"
  >
    <div class="mf-sidebar-glow absolute inset-0"></div>

    <div class="relative mf-fade-in">
      <flux:sidebar.header class="mf-transition mf-sidebar-header">
        <div class="mf-sidebar-brand-row">
          <x-app-logo :sidebar="true" href="{{ $hrefDashboard }}" wire:navigate class="mf-transition mf-home-pill" />
          <button
            type="button"
            id="mf-sidebar-collapse-toggle"
            class="hidden lg:inline-flex mf-sidebar-pin-btn"
            aria-pressed="false"
            aria-label="Collapse sidebar"
            title="Collapse sidebar"
          >
            <span class="mf-sidebar-pin-icon" aria-hidden="true">‹</span>
          </button>
        </div>
        <flux:sidebar.collapse class="lg:hidden mf-transition" />
      </flux:sidebar.header>

      <flux:sidebar.nav class="mf-sidebar-nav">
        <flux:sidebar.group heading="Navigation" class="grid mf-sidebar-group-balanced">
          <div class="space-y-1 mf-sidebar-main-list" data-sidebar-sortable data-sidebar-save-url="{{ route('ui.preferences.sidebar-order') }}" data-sidebar-csrf="{{ csrf_token() }}">
            @foreach($orderedSidebarItems as $item)
              <div
                class="mf-sidebar-sort-item"
                data-sidebar-item
                data-sidebar-key="{{ $item['key'] }}"
              >
                @php
                  $itemKey = (string) ($item['key'] ?? '');
                  $children = collect((array) ($item['children'] ?? []))
                    ->filter(fn (mixed $child): bool => is_array($child))
                    ->map(function (array $child): array {
                        unset($child['children']);

                        return $child;
                    })
                    ->values()
                    ->all();
                  if ($children === [] && is_array($childMenuItems[$itemKey] ?? null)) {
                      $children = (array) $childMenuItems[$itemKey];
                  }
                  $hasChildren = $children !== [];
                  $hasActiveChild = collect($children)->contains(
                      fn (array $child): bool => (bool) ($child['current'] ?? false)
                  );
                  $isGroupOpen = (bool) ($childMenuOpenState[$itemKey] ?? false)
                      || (bool) ($item['current'] ?? false)
                      || $hasActiveChild;
                @endphp

                @if($hasChildren)
                  <details class="mf-admin-group" {{ $isGroupOpen ? 'open' : '' }}>
                    <summary class="mf-admin-group-summary {{ $item['current'] || $hasActiveChild ? 'mf-active-pill' : '' }}">
                      <span class="mf-admin-group-main">
                        @switch($itemKey)
                          @case('production')
                            <flux:icon.beaker class="size-4" />
                            @break
                          @case('administration')
                            <flux:icon.wrench-screwdriver class="size-4" />
                            @break
                          @case('marketing')
                            <flux:icon.megaphone class="size-4" />
                            @break
                          @case('birthdays')
                            <flux:icon.gift class="size-4" />
                            @break
                        @endswitch
                        <span class="mf-nav-label">{{ $item['label'] }}</span>
                      </span>
                      <flux:icon.chevron-right class="mf-admin-group-chevron size-3" />
                    </summary>
                    <div class="mf-admin-subnav">
                      @foreach($children as $subItem)
                        <a
                          href="{{ $subItem['href'] ?? '#' }}"
                          wire:navigate
                          class="mf-admin-subnav-link {{ ! empty($subItem['current']) ? 'mf-admin-subnav-link-active' : '' }}"
                        >
                          <span>{{ $subItem['label'] ?? 'Section' }}</span>
                        </a>
                      @endforeach
                    </div>
                  </details>
                @else
                  <flux:sidebar.item icon="{{ $item['icon'] }}" href="{{ $item['href'] }}" :current="$item['current']" wire:navigate class="mf-transition mf-nav-item {{ $item['current'] ? 'mf-active-pill' : '' }}">
                    <span class="mf-nav-label">{{ $item['label'] }}</span>
                  </flux:sidebar.item>
                @endif
              </div>
            @endforeach
            <div class="mf-sidebar-sort-item">
              <details class="mf-admin-group" {{ $wikiSectionsActive ? 'open' : '' }}>
                <summary class="mf-admin-group-summary {{ $wikiSectionsActive ? 'mf-active-pill' : '' }}">
                  <span class="mf-admin-group-main">
                    <flux:icon.book-open class="size-4" />
                    <span class="mf-nav-label">Wiki Sections</span>
                  </span>
                  <flux:icon.chevron-right class="mf-admin-group-chevron size-3" />
                </summary>
                <div class="mf-admin-subnav">
                  @foreach($wikiSectionItems as $wikiSection)
                    <a
                      href="{{ $wikiSection['href'] }}"
                      wire:navigate
                      class="mf-admin-subnav-link {{ $wikiSection['current'] ? 'mf-admin-subnav-link-active' : '' }}"
                    >
                      <span>{{ $wikiSection['label'] }}</span>
                    </a>
                  @endforeach
                </div>
              </details>
            </div>
          </div>
        </flux:sidebar.group>

        @if($quickActions !== [] || $canAccessOps)
        <flux:sidebar.group heading="Quick Actions" class="grid mt-3 mf-sidebar-group-balanced">
          <button
            type="button"
            class="fb-surface-inset w-full px-3 py-2 text-left text-sm font-semibold text-zinc-950 transition hover:bg-white"
            data-command-trigger
          >
            Search everything
            <span class="mt-1 block text-xs font-normal text-zinc-500">Press Cmd/Ctrl + K</span>
          </button>

          @foreach($quickActions as $action)
            @continue(($action['intent'] ?? null) === 'open-command')
            <a
              href="{{ $action['href'] ?? '#' }}"
              wire:navigate
              class="fb-surface-inset block px-3 py-2 text-left text-sm font-semibold text-zinc-950 transition hover:bg-white"
            >
              <span class="mf-nav-label">{{ $action['label'] ?? 'Action' }}</span>
              @if(! empty($action['description']))
                <span class="mt-1 block text-xs font-normal text-zinc-500">{{ $action['description'] }}</span>
              @endif
            </a>
          @endforeach

          @if($canAccessOps)
            <details class="mt-2 rounded-2xl border p-3 group mf-sidebar-panel">
              <summary class="cursor-pointer list-none flex items-center justify-between fb-kpi-label text-zinc-600">
                <span>Import Tools</span>
                <span class="text-[10px] text-zinc-500 transition-transform group-open:rotate-90">▸</span>
              </summary>
              <div class="mt-3 space-y-2">
                <form method="POST" action="{{ route('admin.tools.clear-orders') }}">
                  @csrf
                  <button type="submit" class="w-full rounded-xl border px-3 py-2 text-xs mf-sidebar-action-btn">
                    Clear Orders
                  </button>
                </form>
                <form method="POST" action="{{ route('admin.tools.import-retail') }}">
                  @csrf
                  <button type="submit" class="w-full rounded-xl border px-3 py-2 text-xs mf-sidebar-action-btn">
                    Import Retail Orders
                  </button>
                </form>
                <form method="POST" action="{{ route('admin.tools.import-wholesale') }}">
                  @csrf
                  <button type="submit" class="w-full rounded-xl border px-3 py-2 text-xs mf-sidebar-action-btn">
                    Import Wholesale Orders
                  </button>
                </form>
                <form method="POST" action="{{ route('admin.tools.import-market-boxes') }}">
                  @csrf
                  <button type="submit" class="w-full rounded-xl border px-3 py-2 text-xs mf-sidebar-action-btn">
                    Reimport Market Boxes
                  </button>
                </form>
              </div>
            </details>
          @endif
        </flux:sidebar.group>
        @endif
      </flux:sidebar.nav>

      <flux:spacer />

      @auth
        <div class="mf-transition mf-sidebar-footer">
          <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </div>
      @endauth
    </div>
  </flux:sidebar>

  {{-- Right side --}}
  <div class="flex-1 min-w-0 flex flex-col">

    {{-- Mobile Header --}}
    <flux:header class="lg:hidden mf-fade-in">
      <flux:sidebar.toggle class="lg:hidden mf-transition" icon="bars-2" inset="left" />
      <flux:spacer />
      {{-- keep your auth dropdown --}}
    </flux:header>

    {{-- Main content --}}
	    <main id="app-main" class="flex-1 min-w-0 overflow-y-auto p-6">
	      <div class="mb-4 flex items-center justify-between gap-3">
	        <button
	          type="button"
	          data-command-trigger
	          class="fb-btn-soft rounded-full"
	        >
	          <span>Search everything</span>
	          <span class="fb-chip fb-chip--quiet">Cmd/Ctrl + K</span>
	        </button>
	
	        @if(! empty($experienceProfile['tenant_name'] ?? null))
	          <div class="fb-chip fb-chip--quiet">
	            {{ $experienceProfile['tenant_name'] }}
	            · {{ strtoupper((string) ($experienceProfile['channel_type'] ?? 'direct')) }}
	            · {{ strtoupper((string) ($experienceProfile['use_case_profile'] ?? 'ops')) }}
	          </div>
	        @endif
	      </div>

      @if($canAccessOps && $unresolvedExceptions > 0)
        <div class="mf-announcement mb-4 rounded-2xl border px-4 py-3 text-sm">
          <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
            <div>
              <span class="font-semibold">Import Attention:</span>
              {{ $unresolvedExceptions }} unmapped line item{{ $unresolvedExceptions === 1 ? '' : 's' }} need review.
              @if($latestRun)
                <span class="mf-announcement-subtle ml-2">Last run: #{{ $latestRun->id }} ({{ $latestRun->store_key ?? 'store' }})</span>
              @endif
            </div>
            <div class="flex items-center gap-2">
              <a href="{{ route('admin.mapping-exceptions') }}"
                 class="mf-announcement-btn inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-semibold">
                Fix Exceptions
              </a>
              <a href="{{ route('admin.import-runs') }}"
                 class="mf-announcement-btn mf-announcement-btn-muted inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-semibold">
                Import Runs
              </a>
            </div>
          </div>
        </div>
      @endif
      <div class="rounded-3xl mf-app-card mf-app-glow p-6 md:p-7 text-[var(--fb-text)]">
        {{ $slot }}
      </div>
    </main>

  </div>
</div>

<div id="mf-toast" role="status" aria-live="polite" class="pointer-events-none fixed left-1/2 top-5 z-50 hidden w-[min(92vw,48rem)] -translate-x-1/2 rounded-2xl border border-zinc-200 bg-white/95 px-5 py-4 text-base font-semibold text-zinc-950 shadow-2xl"></div>
<x-app-command-palette
  :search-endpoint="route('app.search')"
  :placeholder="$commandPlaceholder"
  :context-label="$workspaceLabel"
/>
<script>
  (function () {
    let timeoutId;
    function showToast(detail) {
      const el = document.getElementById('mf-toast');
      if (!el) return;
      const payload = detail && typeof detail === 'object' && detail[0] && typeof detail[0] === 'object'
        ? detail[0]
        : detail;
      const message = payload && payload.message ? payload.message : 'Saved.';
      const style = payload && (payload.style || payload.type) ? (payload.style || payload.type) : 'success';
      el.classList.remove('hidden', 'border-emerald-400/40', 'border-red-400/40', 'border-amber-300/40', 'bg-emerald-950/90', 'bg-red-950/90', 'bg-amber-950/90');
      if (style === 'error') {
        el.classList.add('border-red-400/40');
        el.classList.add('bg-red-950/90');
      } else if (style === 'warning') {
        el.classList.add('border-amber-300/40');
        el.classList.add('bg-amber-950/90');
      } else {
        el.classList.add('border-emerald-400/40');
        el.classList.add('bg-emerald-950/90');
      }
      el.textContent = message;
      clearTimeout(timeoutId);
      timeoutId = setTimeout(() => el.classList.add('hidden'), 6000);
    }
    window.addEventListener('toast', (e) => showToast(e.detail));
    @if(session()->has('toast'))
      window.addEventListener('DOMContentLoaded', () => {
        window.dispatchEvent(new CustomEvent('toast', {
          detail: @json(session('toast'))
        }));
      });
    @endif
  })();
</script>
<script>
  (function () {
    function syncSidebarToggle(toggle, sidebar) {
      if (!toggle || !sidebar) {
        return;
      }

      const collapsed = sidebar.hasAttribute('data-flux-sidebar-collapsed-desktop');
      toggle.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
      toggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
      toggle.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    }

    function bindSidebarToggle() {
      const toggle = document.getElementById('mf-sidebar-collapse-toggle');
      const sidebar = document.getElementById('app-sidebar');

      if (!sidebar) {
        return;
      }

      syncSidebarToggle(toggle, sidebar);

      if (toggle) {
        if (toggle.dataset.mfBound !== '1') {
          toggle.dataset.mfBound = '1';
          toggle.addEventListener('click', () => {
            document.dispatchEvent(new CustomEvent('flux-sidebar-toggle', { bubbles: true }));
            requestAnimationFrame(() => syncSidebarToggle(toggle, sidebar));
          });
        }
      }

      const registry = window;
      if (!registry.__mfSidebarCollapseObserverBound) {
        registry.__mfSidebarCollapseObserverBound = true;
        new MutationObserver(() => {
          syncSidebarToggle(document.getElementById('mf-sidebar-collapse-toggle'), document.getElementById('app-sidebar'));
        }).observe(sidebar, {
          attributes: true,
          attributeFilter: ['data-flux-sidebar-collapsed-desktop'],
        });
      }
    }

    bindSidebarToggle();
    document.addEventListener('livewire:navigated', bindSidebarToggle);
  })();
</script>
<script>
  (function () {
    function scrollTop() {
      const main = document.getElementById('app-main');
      const doScroll = () => {
        if (main) {
          main.scrollTop = 0;
        }
        window.scrollTo({ top: 0, left: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
      };
      doScroll();
      requestAnimationFrame(doScroll);
      setTimeout(doScroll, 50);
    }
    document.addEventListener('livewire:navigated', scrollTop);
    document.addEventListener('livewire:navigation', scrollTop);
  })();
</script>

@fluxScripts
@livewireScripts
@livewireScriptConfig
</body>
</html>
