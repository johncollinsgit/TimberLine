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
  $childMenuItems = [
      'administration' => $adminSubItems,
  ];
  $wikiSectionItems = (array) ($navigationShell['wiki_sections'] ?? []);
  $wikiSectionsActive = collect($wikiSectionItems)->contains(fn (array $item): bool => (bool) ($item['current'] ?? false));
  $opsAttention = is_array($navigationShell['ops_attention'] ?? null) ? $navigationShell['ops_attention'] : [];
  $unresolvedExceptions = (int) ($opsAttention['unresolved_exceptions'] ?? 0);
  $latestRun = $opsAttention['latest_run'] ?? null;
  $shellContext = (string) ($navigationShell['shell_context'] ?? 'tenant');
  $isLandlordShell = $shellContext === 'landlord';
  $isNeutralTenantSurface = request()->routeIs('proposals.*', 'billing.*', 'payments.*', 'invoices.*')
      || request()->is('proposals*', 'billing*', 'payments*', 'invoices*');
  $activeTenant = $navigationShell['tenant'] ?? null;
  $tenantBrand = app(\App\Services\Tenancy\TenantBrandProfileService::class)->presentationFor(
      ($isLandlordShell || $isNeutralTenantSurface) ? null : ($activeTenant instanceof \App\Models\Tenant ? $activeTenant : null)
  );
  $tenantThemeStyle = implode('', [
      '--tenant-primary: '.e((string) $tenantBrand['primary_color']).';',
      '--tenant-accent: '.e((string) $tenantBrand['accent_color']).';',
      '--tenant-surface: '.e((string) $tenantBrand['surface_color']).';',
      '--tenant-text: '.e((string) $tenantBrand['text_color']).';',
  ]);
  $showDataTools = $canAccessOps && ! $isLandlordShell;
  $hrefDashboard = $isLandlordShell ? route('landlord.dashboard') : route('dashboard');
  $workspaceLabel = (string) ($workspace['label'] ?? 'Unified workspace');
  $workspaceSubtitle = (string) ($workspace['subtitle'] ?? 'One product surface that adapts to the tenant in front of it.');
  $commandPlaceholder = (string) ($workspace['command_placeholder'] ?? 'Search or ask what you want to do...');
  $consoleSwitches = collect((array) ($navigationShell['console_switches'] ?? []))
      ->filter(fn (mixed $switch): bool => is_array($switch) && trim((string) ($switch['href'] ?? '')) !== '')
      ->values();
  $brandMarkSrc = (string) $tenantBrand['icon_url'];
  $brandLightLogoSrc = (string) $tenantBrand['light_logo_url'];
  $brandDarkLogoSrc = (string) $tenantBrand['dark_logo_url'];
  $brandHasFullLogo = ! $isLandlordShell && ! $isNeutralTenantSurface && (bool) ($tenantBrand['has_light_logo'] ?? false);
  $brandWordmark = trim((string) $tenantBrand['display_name']);
  $assistantHref = route('shopify.embedded.assistant', absolute: false);
  $footerUserName = trim((string) ($user?->name ?? '')) !== ''
      ? trim((string) $user?->name)
      : trim((string) ($user?->email ?? 'User'));
  $accountMode = strtolower(trim((string) ($experienceProfile['account_mode'] ?? 'production')));
  $accessLaneBanner = match ($accountMode) {
      'demo' => [
          'label' => 'Viewing Demo Tenant',
          'copy' => 'Sample workspace for evaluation. Keep production decisions in landlord review.',
          'classes' => 'border-sky-200 bg-sky-50 text-sky-900',
      ],
      'sandbox', 'test' => [
          'label' => 'Viewing Sandbox Test Tenant',
          'copy' => 'Safe workspace for testing. Data and workflows here are allowed to be disposable.',
          'classes' => 'border-amber-200 bg-amber-50 text-amber-950',
      ],
      default => null,
  };
  $marketingActive = request()->routeIs('marketing.*') || request()->is('marketing*');
  $adminActive = request()->routeIs('admin.*', 'tenant.brand.*') || request()->is('admin*');
  $childMenuOpenState = [
      'administration' => $adminActive,
      'marketing' => $marketingActive,
  ];
  $topbarContextPills = [];
  if ($isLandlordShell) {
      $topbarContextPills[] = 'Operator view';
      $topbarContextPills[] = 'Safe controls';
  } elseif (! empty($experienceProfile['tenant_name'] ?? null)) {
      $topbarContextPills[] = (string) $experienceProfile['tenant_name'];
      $topbarContextPills[] = strtoupper((string) ($experienceProfile['channel_type'] ?? 'direct'));
  }
@endphp
<body
  data-mf-theme="{{ ($isLandlordShell || $isNeutralTenantSurface) ? 'everbranch' : $tenantBrand['theme_key'] }}"
  data-tenant-theme="{{ ($isLandlordShell || $isNeutralTenantSurface) ? 'everbranch' : $tenantBrand['theme_key'] }}"
  data-tenant-decor="{{ ($isLandlordShell || $isNeutralTenantSurface) ? 'none' : $tenantBrand['decor_preset'] }}"
  data-tenant-display="{{ ($isLandlordShell || $isNeutralTenantSurface) ? 'classic' : $tenantBrand['display_style'] }}"
  data-tenant-corners="{{ ($isLandlordShell || $isNeutralTenantSurface) ? 'soft' : $tenantBrand['corner_style'] }}"
  style="{{ ($isLandlordShell || $isNeutralTenantSurface) ? '' : $tenantThemeStyle }}"
  class="min-h-screen antialiased mf-app-shell {{ $wideLayout ? 'mf-wide' : '' }} {{ $compactTables ? 'mf-compact' : '' }} {{ (! $isLandlordShell && ! $isNeutralTenantSurface) ? 'mf-tenant-themed' : '' }}"
>

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
      <flux:sidebar.header class="mf-transition mf-sidebar-header" data-shell-context="{{ $shellContext }}">
        <div class="mf-sidebar-brand-row">
          <a
            href="{{ $hrefDashboard }}"
            wire:navigate
            class="mf-transition mf-sidebar-brand-lockup"
            aria-label="{{ $isLandlordShell ? 'Open Everbranch Admin home' : 'Open workspace home' }}"
          >
            @if($brandHasFullLogo)
              <img src="{{ $brandLightLogoSrc }}" alt="{{ $brandWordmark }}" class="mf-sidebar-brand-mark-image mf-sidebar-brand-mark-image--full mf-sidebar-brand-mark-image--light" loading="eager" decoding="async" />
              <img src="{{ $brandDarkLogoSrc }}" alt="" aria-hidden="true" class="mf-sidebar-brand-mark-image mf-sidebar-brand-mark-image--full mf-sidebar-brand-mark-image--dark" loading="eager" decoding="async" />
            @else
              <img src="{{ $brandMarkSrc }}" alt="{{ $brandWordmark }}" class="mf-sidebar-brand-mark-image" loading="eager" decoding="async" />
              <span class="mf-sidebar-brand-wordmark">{{ $brandWordmark }}</span>
            @endif
          </a>
          <button
            type="button"
            id="mf-sidebar-collapse-toggle"
            class="hidden lg:inline-flex mf-sidebar-pin-btn"
            aria-pressed="false"
            aria-label="Collapse sidebar"
            title="Collapse sidebar"
          >
            <flux:icon.chevron-left class="size-4 mf-sidebar-pin-icon" />
          </button>
        </div>
        <flux:sidebar.collapse class="lg:hidden mf-transition" />
      </flux:sidebar.header>

      <flux:sidebar.nav class="mf-sidebar-nav">
        <flux:sidebar.group :heading="$isLandlordShell ? 'Everbranch Admin' : 'Workspace'" class="grid mf-sidebar-group-balanced">
          <div class="space-y-1 mf-sidebar-main-list">
            @foreach($orderedSidebarItems as $item)
              <div
                class="mf-sidebar-entry"
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
                      || $hasActiveChild;
                  $groupIsCurrent = (bool) ($item['current'] ?? false)
                      || $hasActiveChild;
                @endphp

                @if($hasChildren)
                  <details class="mf-admin-group" data-sidebar-group-key="{{ $itemKey }}" {{ $isGroupOpen ? 'open' : '' }}>
                    <summary class="mf-admin-group-summary {{ $groupIsCurrent ? 'is-current-group' : '' }}">
                      <span class="mf-admin-group-main">
                        <span class="mf-leaf-icon-badge" aria-hidden="true">
                          <flux:icon :icon="(string) ($item['icon'] ?? 'squares-2x2')" class="size-3.5" />
                        </span>
                        <span class="mf-nav-label">{{ $item['label'] }}</span>
                      </span>
                      <flux:icon.chevron-right class="mf-admin-group-chevron size-3" />
                    </summary>
                    <div class="mf-admin-subnav">
                      @foreach($children as $subItem)
                        <a
                          href="{{ $subItem['href'] ?? '#' }}"
                          wire:navigate
                          data-sidebar-child-key="{{ $subItem['key'] ?? 'section' }}"
                          class="mf-admin-subnav-link {{ ! empty($subItem['current']) ? 'mf-admin-subnav-link-active' : '' }}"
                          @if(! empty($subItem['current'])) aria-current="page" @endif
                        >
                          <flux:icon :icon="(string) ($subItem['icon'] ?? 'chevron-right')" class="mf-admin-subnav-icon size-3.5" aria-hidden="true" />
                          <span>{{ $subItem['label'] ?? 'Section' }}</span>
                        </a>
                      @endforeach
                    </div>
                  </details>
                @else
                  <a
                    href="{{ $item['href'] }}"
                    wire:navigate
                    data-flux-sidebar-item
                    @if(! empty($item['current'])) data-current="data-current" aria-current="page" @endif
                    class="mf-transition mf-nav-item {{ ! empty($item['current']) ? 'mf-active-pill' : '' }}"
                  >
                      <span class="mf-nav-item-copy">
                        <span class="mf-leaf-icon-badge" aria-hidden="true">
                          <flux:icon :icon="(string) ($item['icon'] ?? 'squares-2x2')" class="size-3.5" />
                      </span>
                      <span class="mf-nav-label">{{ $item['label'] }}</span>
                    </span>
                  </a>
                @endif
              </div>
            @endforeach
            <div class="mf-sidebar-entry" data-sidebar-key="wiki-sections">
              <details class="mf-admin-group" data-sidebar-group-key="wiki-sections" {{ $wikiSectionsActive ? 'open' : '' }}>
                <summary class="mf-admin-group-summary {{ $wikiSectionsActive ? 'is-current-group' : '' }}">
                  <span class="mf-admin-group-main">
                    <span class="mf-leaf-icon-badge" aria-hidden="true">
                      <flux:icon icon="book-open-text" class="size-3.5" />
                    </span>
                    <span class="mf-nav-label">Workspace Guide</span>
                  </span>
                  <flux:icon.chevron-right class="mf-admin-group-chevron size-3" />
                </summary>
                <div class="mf-admin-subnav">
                  @foreach($wikiSectionItems as $wikiSection)
                    <a
                      href="{{ $wikiSection['href'] }}"
                      wire:navigate
                      data-sidebar-child-key="{{ $wikiSection['key'] ?? 'wiki-section' }}"
                      class="mf-admin-subnav-link {{ $wikiSection['current'] ? 'mf-admin-subnav-link-active' : '' }}"
                      @if($wikiSection['current']) aria-current="page" @endif
                    >
                      <flux:icon :icon="(string) ($wikiSection['icon'] ?? 'book-open-text')" class="mf-admin-subnav-icon size-3.5" aria-hidden="true" />
                      <span>{{ $wikiSection['label'] }}</span>
                    </a>
                  @endforeach
                </div>
              </details>
            </div>
          </div>
        </flux:sidebar.group>
      </flux:sidebar.nav>

      <flux:spacer />

      @auth
        <div class="mf-transition mf-sidebar-footer">
          <x-desktop-user-menu class="hidden lg:block" position="top" :name="$footerUserName" :email="auth()->user()->email" :console-switches="$consoleSwitches->all()" />
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
    <main id="app-main" class="mf-app-main flex-1 min-w-0 overflow-y-auto">
      <div class="mf-shell-topbar" data-app-shell-topbar>
        <div class="mf-shell-location">
          <span class="mf-shell-location-eyebrow">{{ $isLandlordShell ? 'Everbranch Admin' : 'Everbranch Workspace' }}</span>
          <span class="mf-shell-location-title">{{ $workspaceLabel }}</span>
        </div>

        <button
          type="button"
          data-command-trigger
          class="mf-shell-search"
          aria-label="Search or ask what you want to do..."
        >
          <span class="mf-shell-search-icon" aria-hidden="true">
            <x-brand.leaf-icon />
          </span>
          <span class="mf-shell-search-placeholder">Search or ask what you want to do...</span>
          <span class="mf-shell-search-shortcut">Cmd K</span>
        </button>

        <div class="mf-shell-actions">
          @foreach($topbarContextPills as $pill)
            <span class="mf-shell-context-pill">{{ $pill }}</span>
          @endforeach
          <a
            href="{{ $assistantHref }}"
            wire:navigate
            class="mf-bud-entry"
            data-assistant-entry
            aria-label="Open Bud assistant"
            title="Open Bud assistant"
          >
            <img src="{{ $brandMarkSrc }}" alt="" aria-hidden="true" />
            <span>Bud</span>
          </a>
        </div>
      </div>

      @if(is_array($accessLaneBanner))
        <div
          class="mx-auto mb-4 max-w-[1180px] rounded-2xl border px-4 py-3 text-sm {{ $accessLaneBanner['classes'] }}"
          data-access-lane-banner="{{ $accountMode }}"
        >
          <div class="font-semibold">{{ $accessLaneBanner['label'] }}</div>
          <div class="mt-1 text-xs opacity-80">{{ $accessLaneBanner['copy'] }}</div>
        </div>
      @endif

      @if($showDataTools && $unresolvedExceptions > 0)
        <div class="mf-announcement mx-auto mb-4 max-w-[1180px] rounded-2xl border px-4 py-3 text-sm">
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
      <div class="mf-shell-content mx-auto text-[var(--fb-text)]">
        <div class="rounded-2xl mf-app-card mf-app-glow p-5 md:p-7">
        {{ $slot }}
        </div>
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
@stack('scripts')
</body>
</html>
