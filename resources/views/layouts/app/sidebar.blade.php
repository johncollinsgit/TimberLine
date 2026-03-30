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

  $hrefDashboard = Route::has('dashboard')        ? route('dashboard')        : '/dashboard';
  $hrefShipping  = Route::has('shipping.orders')  ? route('shipping.orders')  : '/shipping/orders';
  $hrefPouring   = Route::has('pouring.index')    ? route('pouring.index')    : '/pouring';
  $hrefRetailPlan = Route::has('retail.plan')     ? route('retail.plan')      : '/retail/plan';
  $hrefAdmin     = Route::has('admin.index')      ? route('admin.index')      : '/admin';
  $hrefAnalytics = Route::has('analytics.index')  ? route('analytics.index')  : '/analytics';

  $shippingActive  = request()->routeIs('shipping.*')  || request()->is('shipping*');
  $pouringActive   = request()->routeIs('pouring.index')
      || request()->routeIs('pouring.queue')
      || request()->routeIs('pouring.bulk')
      || request()->is('pouring')
      || request()->is('pouring/queue')
      || request()->is('pouring/bulk');
  $retailPlanActive = request()->routeIs('retail.plan') || request()->is('retail/plan');
  $adminActive     = request()->routeIs('admin.*')     || request()->is('admin*');
  $analyticsActive = request()->routeIs('analytics.*') || request()->is('analytics*');
  $marketingActive = request()->routeIs('marketing.*') || request()->is('marketing*');
  $birthdaysActive = request()->routeIs('birthdays.*') || request()->is('birthdays*');
  $wikiActive = request()->routeIs('wiki.index') || request()->is('wiki');
  $inventoryActive = request()->routeIs('inventory.index');
  $eventsActive = request()->routeIs('events.*');
  $marketListsActive = request()->routeIs('markets.lists.*');
  $marketsActive = request()->routeIs('markets.browser.*');
  $adminTab = is_string(request()->query('tab')) ? (string) request()->query('tab') : '';

  $sidebarItems = [];
  if ($canAccessOps) {
      $sidebarItems[] = ['key' => 'retail-plan', 'icon' => 'clipboard-document', 'href' => $hrefRetailPlan, 'label' => 'Pour Lists', 'current' => $retailPlanActive];
  }
  if ($canAccessOps) {
      $sidebarItems[] = ['key' => 'events', 'icon' => 'calendar-days', 'href' => route('events.index'), 'label' => 'Events', 'current' => $eventsActive];
  }
  if ($canAccessOps) {
      $sidebarItems[] = ['key' => 'shipping-room', 'icon' => 'truck', 'href' => $hrefShipping, 'label' => 'Shipping Room', 'current' => $shippingActive];
  }
  if ($canAccessOps || $isPouring) {
      $sidebarItems[] = ['key' => 'pouring-room', 'icon' => 'beaker', 'href' => $hrefPouring, 'label' => 'Pouring Room', 'current' => $pouringActive];
  }
  if ($canAccessOps) {
      $sidebarItems[] = ['key' => 'analytics', 'icon' => 'chart-bar', 'href' => $hrefAnalytics, 'label' => 'Analytics', 'current' => $analyticsActive];
  }
  if ($canAccessMarketing) {
      $sidebarItems[] = ['key' => 'marketing', 'icon' => 'megaphone', 'href' => route('marketing.overview'), 'label' => 'Marketing', 'current' => $marketingActive];
  }
  if ($canAccessMarketing) {
      $sidebarItems[] = ['key' => 'birthdays', 'icon' => 'gift', 'href' => route('birthdays.customers'), 'label' => 'Birthdays', 'current' => $birthdaysActive];
  }
  if ($canAccessOps) {
      $sidebarItems[] = ['key' => 'markets', 'icon' => 'shopping-bag', 'href' => route('markets.browser.index'), 'label' => 'Markets', 'current' => $marketsActive];
  }
  if ($canAccessOps) {
      $sidebarItems[] = ['key' => 'administration', 'icon' => 'wrench-screwdriver', 'href' => $hrefAdmin, 'label' => 'Administration', 'current' => $adminActive];
  }
  $sidebarItems[] = ['key' => 'backstage-wiki', 'icon' => 'book-open', 'href' => route('wiki.index'), 'label' => 'Backstage Wiki', 'current' => $wikiActive];
  if ($canAccessOps) {
      $sidebarItems[] = ['key' => 'inventory', 'icon' => 'archive-box', 'href' => route('inventory.index'), 'label' => 'Inventory', 'current' => $inventoryActive];
  }

  $preferredSidebarOrder = is_array($prefs['sidebar_order'] ?? null) ? $prefs['sidebar_order'] : [];
  $sidebarItemsByKey = collect($sidebarItems)->keyBy('key');
  $orderedSidebarKeys = [];
  foreach ($preferredSidebarOrder as $key) {
      if (is_string($key) && $sidebarItemsByKey->has($key) && !in_array($key, $orderedSidebarKeys, true)) {
          $orderedSidebarKeys[] = $key;
      }
  }
  foreach ($sidebarItems as $item) {
      if (!in_array($item['key'], $orderedSidebarKeys, true)) {
          $orderedSidebarKeys[] = $item['key'];
      }
  }
  $orderedSidebarItems = collect($orderedSidebarKeys)
      ->map(fn ($key) => $sidebarItemsByKey->get($key))
      ->filter()
      ->values();
  $adminSubItems = [];
  if ($canAccessOps) {
      $adminSubItems = [
          [
              'key' => 'master-data',
              'label' => 'Data Manager',
              'href' => route('admin.index', ['tab' => 'master-data', 'resource' => (string) request()->query('resource', 'scents') ?: 'scents']),
              'current' => $adminActive && $adminTab === 'master-data',
          ],
          ...($isAdmin ? [[
              'key' => 'users',
              'label' => 'Team Access',
              'href' => route('admin.index', ['tab' => 'users']),
              'current' => $adminActive && $adminTab === 'users',
          ]] : []),
          [
              'key' => 'imports',
              'label' => 'Import Issues',
              'href' => route('admin.index', ['tab' => 'imports']),
              'current' => $adminActive && $adminTab === 'imports',
          ],
          [
              'key' => 'scent-intake',
              'label' => 'New Scent Requests',
              'href' => route('admin.index', ['tab' => 'scent-intake']),
              'current' => $adminActive && $adminTab === 'scent-intake',
          ],
          [
              'key' => 'catalog',
              'label' => 'Scent Catalog',
              'href' => route('admin.index', ['tab' => 'catalog']),
              'current' => $adminActive && $adminTab === 'catalog',
          ],
          [
              'key' => 'sizes-wicks',
              'label' => 'Sizes & Wicks',
              'href' => route('admin.index', ['tab' => 'sizes-wicks']),
              'current' => $adminActive && $adminTab === 'sizes-wicks',
          ],
          [
              'key' => 'wholesale-custom',
              'label' => 'Wholesale Custom',
              'href' => route('admin.index', ['tab' => 'wholesale-custom']),
              'current' => $adminActive && $adminTab === 'wholesale-custom',
          ],
          [
              'key' => 'blends',
              'label' => 'Oil Blends',
              'href' => route('admin.index', ['tab' => 'blends']),
              'current' => $adminActive && $adminTab === 'blends',
          ],
          [
              'key' => 'candle-club',
              'label' => 'Candle Club',
              'href' => route('admin.index', ['tab' => 'candle-club']),
              'current' => $adminActive && $adminTab === 'candle-club',
          ],
          [
              'key' => 'oils',
              'label' => 'Oil Abbreviations',
              'href' => route('admin.index', ['tab' => 'oils']),
              'current' => $adminActive && $adminTab === 'oils',
          ],
      ];
  }

  $marketingSubItems = [];
  $marketingSubGroups = [];
  $birthdaySubItems = [];
  $birthdaySubGroups = [];
  if ($canAccessMarketing) {
      $marketingSubItems = collect(\App\Support\Marketing\MarketingSectionRegistry::sections())
          ->map(function (array $section, string $key): array {
              return [
                  'key' => $key,
                  'label' => $section['label'],
                  'href' => route($section['route']),
                  'current' => request()->routeIs($section['route']) || request()->routeIs($section['route'] . '.*'),
              ];
          })
          ->values()
          ->all();

      $marketingSubGroups = \App\Support\Marketing\MarketingSectionRegistry::groupNavigationItems($marketingSubItems);

      $birthdaySubItems = collect(\App\Support\Birthdays\BirthdaySectionRegistry::sections())
          ->map(function (array $section, string $key): array {
              return [
                  'key' => $key,
                  'label' => $section['label'],
                  'href' => route($section['route']),
                  'current' => request()->routeIs($section['route']) || request()->routeIs($section['route'] . '.*'),
              ];
          })
          ->values()
          ->all();

      $birthdaySubGroups = \App\Support\Birthdays\BirthdaySectionRegistry::groupNavigationItems($birthdaySubItems);
  }

  $wikiSectionItems = [
      [
          'key' => 'wholesale-processes',
          'label' => 'Wholesale Processes',
          'href' => route('wiki.wholesale-processes'),
          'current' => request()->routeIs('wiki.wholesale-processes') || request()->is('wiki/article/wholesale*'),
      ],
      [
          'key' => 'market-room-process',
          'label' => 'Market Room Process',
          'href' => route('wiki.article', ['slug' => 'market-room']),
          'current' => request()->routeIs('wiki.article') && request()->route('slug') === 'market-room',
      ],
  ];
  $wikiSectionsActive = collect($wikiSectionItems)->contains(fn (array $item): bool => (bool) ($item['current'] ?? false));

  $unresolvedExceptions = 0;
  $latestRun = null;

  try {
      if (\Illuminate\Support\Facades\Schema::hasTable('mapping_exceptions')) {
          $unresolvedExceptions = \App\Models\MappingException::query()
              ->whereNull('resolved_at')
              ->count();
      }

      if (\Illuminate\Support\Facades\Schema::hasTable('shopify_import_runs')) {
          $latestRun = \App\Models\ShopifyImportRun::query()
              ->orderByDesc('id')
              ->first();
      }
  } catch (\Throwable $e) {
      // Sidebar telemetry should never break page rendering.
      $unresolvedExceptions = 0;
      $latestRun = null;
  }
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
                @if($item['key'] === 'administration' && count($adminSubItems) > 0)
                  <details class="mf-admin-group" {{ $adminActive ? 'open' : '' }}>
                    <summary class="mf-admin-group-summary {{ $item['current'] ? 'mf-active-pill' : '' }}">
                      <span class="mf-admin-group-main">
                        <flux:icon.wrench-screwdriver class="size-4" />
                        <span class="mf-nav-label">Administration</span>
                      </span>
                      <flux:icon.chevron-right class="mf-admin-group-chevron size-3" />
                    </summary>
                    <div class="mf-admin-subnav">
                      @foreach($adminSubItems as $subItem)
                        <a
                          href="{{ $subItem['href'] }}"
                          wire:navigate
                          class="mf-admin-subnav-link {{ $subItem['current'] ? 'mf-admin-subnav-link-active' : '' }}"
                        >
                          <span>{{ $subItem['label'] }}</span>
                        </a>
                      @endforeach
                    </div>
                  </details>
                @elseif($item['key'] === 'marketing' && count($marketingSubGroups) > 0)
                  <details class="mf-admin-group" {{ $marketingActive ? 'open' : '' }}>
                    <summary class="mf-admin-group-summary {{ $item['current'] ? 'mf-active-pill' : '' }}">
                      <span class="mf-admin-group-main">
                        <flux:icon.megaphone class="size-4" />
                        <span class="mf-nav-label">Marketing</span>
                      </span>
                      <flux:icon.chevron-right class="mf-admin-group-chevron size-3" />
                    </summary>
                    <div class="mf-admin-subnav">
                      @foreach($marketingSubGroups as $marketingGroup)
                        <details class="mf-admin-group mf-admin-group-nested" {{ ($marketingGroup['current'] ?? false) ? 'open' : '' }}>
                          <summary class="mf-admin-group-summary mf-admin-group-summary-compact">
                            <span class="mf-admin-group-main">
                              <span class="mf-nav-label">{{ $marketingGroup['label'] }}</span>
                            </span>
                            <flux:icon.chevron-right class="mf-admin-group-chevron size-3" />
                          </summary>
                          <div class="mf-admin-subnav mf-admin-subnav-deep">
                            @foreach($marketingGroup['items'] as $subItem)
                              <a
                                href="{{ $subItem['href'] }}"
                                wire:navigate
                                class="mf-admin-subnav-link {{ $subItem['current'] ? 'mf-admin-subnav-link-active' : '' }}"
                              >
                                <span>{{ $subItem['label'] }}</span>
                              </a>
                            @endforeach
                          </div>
                        </details>
                      @endforeach
                    </div>
                  </details>
                @elseif($item['key'] === 'birthdays' && count($birthdaySubGroups) > 0)
                  <details class="mf-admin-group" {{ $birthdaysActive ? 'open' : '' }}>
                    <summary class="mf-admin-group-summary {{ $item['current'] ? 'mf-active-pill' : '' }}">
                      <span class="mf-admin-group-main">
                        <flux:icon.gift class="size-4" />
                        <span class="mf-nav-label">Birthdays</span>
                      </span>
                      <flux:icon.chevron-right class="mf-admin-group-chevron size-3" />
                    </summary>
                    <div class="mf-admin-subnav">
                      @foreach($birthdaySubGroups as $birthdayGroup)
                        <details class="mf-admin-group mf-admin-group-nested" {{ ($birthdayGroup['current'] ?? false) ? 'open' : '' }}>
                          <summary class="mf-admin-group-summary mf-admin-group-summary-compact">
                            <span class="mf-admin-group-main">
                              <span class="mf-nav-label">{{ $birthdayGroup['label'] }}</span>
                            </span>
                            <flux:icon.chevron-right class="mf-admin-group-chevron size-3" />
                          </summary>
                          <div class="mf-admin-subnav mf-admin-subnav-deep">
                            @foreach($birthdayGroup['items'] as $subItem)
                              <a
                                href="{{ $subItem['href'] }}"
                                wire:navigate
                                class="mf-admin-subnav-link {{ $subItem['current'] ? 'mf-admin-subnav-link-active' : '' }}"
                              >
                                <span>{{ $subItem['label'] }}</span>
                              </a>
                            @endforeach
                          </div>
                        </details>
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

        @if($canAccessOps)
        <flux:sidebar.group heading="Quick Actions" class="grid mt-3 mf-sidebar-group-balanced">
          <flux:sidebar.item icon="clock" href="{{ $hrefShipping }}" wire:navigate class="mf-transition mf-nav-item">
            <span class="mf-nav-label">Ship due soon</span>
          </flux:sidebar.item>

          @if($canAccessOps)
            <details class="mt-2 rounded-2xl border p-3 group mf-sidebar-panel">
              <summary class="cursor-pointer list-none text-[10px] uppercase tracking-[0.3em] text-emerald-100/50 flex items-center justify-between">
                <span>Import Tools</span>
                <span class="text-[10px] transition-transform group-open:rotate-90">▸</span>
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

<div id="mf-toast" role="status" aria-live="polite" class="pointer-events-none fixed left-1/2 top-5 z-50 hidden w-[min(92vw,48rem)] -translate-x-1/2 rounded-2xl border border-white/10 bg-zinc-900/95 px-5 py-4 text-base font-semibold text-white shadow-2xl"></div>
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
