<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
  @include('partials.head')
  @livewireStyles


  <style>
    html { scroll-behavior: smooth; }

    /* Motion/feel */
    .mf-transition { transition: all 200ms cubic-bezier(.2,.8,.2,1); }
    .mf-fade-in { animation: mfFadeIn 220ms ease-out both; }
    @keyframes mfFadeIn {
      from { opacity: 0; transform: translateY(4px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* Sidebar glow */
    .mf-sidebar-glow::before{
      content:"";
      position:absolute;
      inset:-40px -60px -40px -60px;
      background:
        radial-gradient(700px 400px at 0% 0%, rgba(16,185,129,.25), transparent 55%),
        radial-gradient(600px 350px at 0% 100%, rgba(245,158,11,.16), transparent 55%);
      filter: blur(14px);
      pointer-events:none;
      opacity:.9;
    }

    .mf-nav-item { will-change: transform; }
    .mf-nav-item:hover { transform: translateX(2px); }
    .mf-sidebar-sort-item { cursor: grab; touch-action: manipulation; }
    .mf-sidebar-sort-item:active { cursor: grabbing; }
    .mf-sidebar-ghost { opacity: .45; }
    .mf-sidebar-drag { opacity: .8; }

    .mf-active-pill { position: relative; }
    .mf-active-pill::after{
      content:"";
      position:absolute;
      left:-10px;
      top:50%;
      width:6px;
      height:22px;
      transform: translateY(-50%);
      border-radius:999px;
      background: linear-gradient(to bottom, rgba(16,185,129,.95), rgba(245,158,11,.45));
      box-shadow: 0 0 0 1px rgba(16,185,129,.25), 0 10px 25px rgba(16,185,129,.18);
    }

    /* Surface: explicit light + explicit dark (prevents “whiting out”) */
    .mf-surface{
      background:
        radial-gradient(900px 520px at 10% 0%, rgba(16,185,129,.10), transparent 58%),
        radial-gradient(900px 520px at 90% 10%, rgba(245,158,11,.08), transparent 60%),
        linear-gradient(to bottom, rgba(255,255,255,.80), rgba(255,255,255,.65));
    }
    .dark .mf-surface{
      background:
        radial-gradient(900px 520px at 10% 0%, rgba(16,185,129,.12), transparent 58%),
        radial-gradient(900px 520px at 90% 10%, rgba(245,158,11,.10), transparent 60%),
        linear-gradient(to bottom, rgba(24,24,27,.78), rgba(24,24,27,.55));
    }

    /* Soft edges; avoids harsh wireframe */
    .mf-soft-edge{
      box-shadow:
        inset 0 0 0 1px rgba(255,255,255,.07),
        0 30px 80px -55px rgba(0,0,0,.90);
    }

    /* Keep borders tasteful inside the main surface only */
    .mf-surface :where(.border, [class*="border-"]) { border-color: rgba(255,255,255,.08) !important; }
    .mf-surface :where(input, textarea, select) {
      background-color: rgba(255,255,255,.04) !important;
      border-color: rgba(255,255,255,.10) !important;
    }

    /* Optional: if you ever tag a debug banner, it gets nuked */
    .mf-hide-debug-banner :where(.debug-banner, #debug-banner, [data-debug-banner]) { display:none !important; }
  </style>
</head>

@php
  $prefs = is_array(auth()->user()?->ui_preferences ?? null) ? auth()->user()->ui_preferences : [];
  $wideLayout = !empty($prefs['wide_layout']);
  $compactTables = !empty($prefs['compact_tables']);
@endphp
<body class="min-h-screen text-zinc-100 antialiased mf-app-shell {{ $wideLayout ? 'mf-wide' : '' }} {{ $compactTables ? 'mf-compact' : '' }}">
@php
  use Illuminate\Support\Facades\Route;
  $user = auth()->user();
  $isAdmin = $user?->isAdmin() ?? true;
  $isManager = $user?->isManager() ?? false;
  $isPouring = $user?->isPouring() ?? false;

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
  $wikiActive = request()->routeIs('wiki.index') || request()->is('wiki');
  $inventoryActive = request()->routeIs('inventory.index');
  $eventsActive = request()->routeIs('events.*');
  $marketListsActive = request()->routeIs('markets.lists.*');
  $marketsActive = request()->routeIs('pouring.requests');

  $sidebarItems = [];
  if (!$isPouring) {
      $sidebarItems[] = ['key' => 'retail-plan', 'icon' => 'clipboard-document-check', 'href' => $hrefRetailPlan, 'label' => 'Retail/Pour List', 'current' => $retailPlanActive];
      $sidebarItems[] = ['key' => 'inventory', 'icon' => 'archive-box', 'href' => route('inventory.index'), 'label' => 'Inventory', 'current' => $inventoryActive];
      $sidebarItems[] = ['key' => 'shipping-room', 'icon' => 'truck', 'href' => $hrefShipping, 'label' => 'Shipping Room', 'current' => $shippingActive];
  }
  if ($isAdmin || $isManager) {
      $sidebarItems[] = ['key' => 'events', 'icon' => 'calendar-days', 'href' => route('events.index'), 'label' => 'Events', 'current' => $eventsActive];
      $sidebarItems[] = ['key' => 'market-pour-lists', 'icon' => 'list-bullet', 'href' => route('markets.lists.index'), 'label' => 'Market Pour Lists', 'current' => $marketListsActive];
  }
  $sidebarItems[] = ['key' => 'pouring-room', 'icon' => 'fire', 'href' => $hrefPouring, 'label' => 'Pouring Room', 'current' => $pouringActive];
  $sidebarItems[] = ['key' => 'markets', 'icon' => 'clipboard-document', 'href' => route('pouring.requests'), 'label' => 'Markets', 'current' => $marketsActive];
  if ($isAdmin || $isManager) {
      $sidebarItems[] = ['key' => 'administration', 'icon' => 'cog', 'href' => $hrefAdmin, 'label' => 'Administration', 'current' => $adminActive];
  }
  if (!$isPouring) {
      $sidebarItems[] = ['key' => 'analytics', 'icon' => 'chart-bar', 'href' => $hrefAnalytics, 'label' => 'Analytics', 'current' => $analyticsActive];
  }
  $sidebarItems[] = ['key' => 'backstage-wiki', 'icon' => 'book-open', 'href' => route('wiki.index'), 'label' => 'Backstage Wiki', 'current' => $wikiActive];

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

  $unresolvedExceptions = \App\Models\MappingException::query()
      ->whereNull('resolved_at')
      ->count();
  $latestRun = \App\Models\ShopifyImportRun::query()
      ->orderByDesc('id')
      ->first();
@endphp

<div class="min-h-screen flex">

  {{-- Sidebar --}}
  <flux:sidebar
    sticky
    collapsible="mobile"
    class="relative overflow-hidden mf-transition border-e border-white/10 bg-zinc-950"
  >
    <div class="mf-sidebar-glow absolute inset-0"></div>

    <div class="relative mf-fade-in">
      <flux:sidebar.header class="mf-transition">
        <x-app-logo :sidebar="true" href="{{ $hrefDashboard }}" wire:navigate class="mf-transition" />
        <flux:sidebar.collapse class="lg:hidden mf-transition" />
      </flux:sidebar.header>

      <flux:sidebar.nav>
        <flux:sidebar.group heading="Modern Forestry Backstage" class="grid">
          <div class="space-y-1" data-sidebar-sortable data-sidebar-save-url="{{ route('ui.preferences.sidebar-order') }}" data-sidebar-csrf="{{ csrf_token() }}">
            @foreach($orderedSidebarItems as $item)
              <div
                class="mf-sidebar-sort-item {{ $item['current'] ? 'mf-active-pill' : '' }}"
                data-sidebar-item
                data-sidebar-key="{{ $item['key'] }}"
              >
                <flux:sidebar.item icon="{{ $item['icon'] }}" href="{{ $item['href'] }}" :current="$item['current']" wire:navigate class="mf-transition mf-nav-item">
                  {{ $item['label'] }}
                </flux:sidebar.item>
              </div>
            @endforeach
            <details class="ml-3 rounded-xl border border-emerald-200/10 bg-emerald-500/5 px-2 py-1" {{ request()->routeIs('wiki.wholesale-processes') || request()->is('wiki/article/wholesale*') || (request()->routeIs('wiki.article') && request()->route('slug') === 'market-room') ? 'open' : '' }}>
              <summary class="cursor-pointer list-none text-xs text-emerald-100/70 flex items-center justify-between px-2 py-1 group">
                <span>Wiki Sections</span>
                <span class="text-[10px] transition-transform group-open:rotate-90">▸</span>
              </summary>
              <div class="mt-2 rounded-xl bg-black/30 p-2 space-y-1">
                <div class="{{ request()->routeIs('wiki.wholesale-processes') || request()->is('wiki/article/wholesale*') ? 'mf-active-pill' : '' }}">
                  <flux:sidebar.item icon="folder-open" href="{{ route('wiki.wholesale-processes') }}" class="mf-transition mf-nav-item">
                    Wholesale Processes
                  </flux:sidebar.item>
                </div>
                <div class="{{ request()->routeIs('wiki.article') && request()->route('slug') === 'market-room' ? 'mf-active-pill' : '' }}">
                  <flux:sidebar.item icon="sparkles" href="{{ route('wiki.article', ['slug' => 'market-room']) }}" class="mf-transition mf-nav-item">
                    Market Room Process
                  </flux:sidebar.item>
                </div>
              </div>
            </details>
          </div>
        </flux:sidebar.group>

        @if(!$isPouring)
        <flux:sidebar.group heading="Quick Actions" class="grid mt-2">
          <flux:sidebar.item icon="clock" href="{{ $hrefShipping }}" wire:navigate class="mf-transition mf-nav-item">
            Due soon
          </flux:sidebar.item>

          @if($isAdmin || $isManager)
            <details class="mt-2 rounded-2xl border border-emerald-400/10 bg-emerald-500/5 p-3 group">
              <summary class="cursor-pointer list-none text-[10px] uppercase tracking-[0.3em] text-emerald-100/50 flex items-center justify-between">
                <span>Import Tools</span>
                <span class="text-[10px] transition-transform group-open:rotate-90">▸</span>
              </summary>
              <div class="mt-3 space-y-2">
                <form method="POST" action="{{ route('admin.tools.clear-orders') }}">
                  @csrf
                  <button type="submit" class="w-full rounded-xl border border-red-400/25 bg-red-500/10 px-3 py-2 text-xs text-red-100/90 hover:bg-red-500/20">
                    Clear Orders
                  </button>
                </form>
                <form method="POST" action="{{ route('admin.tools.import-retail') }}">
                  @csrf
                  <button type="submit" class="w-full rounded-xl border border-emerald-400/25 bg-emerald-500/10 px-3 py-2 text-xs text-emerald-100/90 hover:bg-emerald-500/20">
                    Import Retail Orders
                  </button>
                </form>
                <form method="POST" action="{{ route('admin.tools.import-wholesale') }}">
                  @csrf
                  <button type="submit" class="w-full rounded-xl border border-amber-400/25 bg-amber-500/10 px-3 py-2 text-xs text-amber-100/90 hover:bg-amber-500/20">
                    Import Wholesale Orders
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
        <div class="mt-3 mf-transition">
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
      @if($unresolvedExceptions > 0)
        <div class="mb-4 rounded-2xl border border-amber-300/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-50/90">
          <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
            <div>
              <span class="font-semibold">Import Attention:</span>
              {{ $unresolvedExceptions }} unmapped line item{{ $unresolvedExceptions === 1 ? '' : 's' }} need review.
              @if($latestRun)
                <span class="text-amber-100/60 ml-2">Last run: #{{ $latestRun->id }} ({{ $latestRun->store_key ?? 'store' }})</span>
              @endif
            </div>
            <div class="flex items-center gap-2">
              <a href="{{ route('admin.mapping-exceptions') }}"
                 class="inline-flex items-center rounded-full border border-amber-300/40 bg-amber-500/20 px-3 py-1.5 text-xs font-semibold text-amber-50">
                Fix Exceptions
              </a>
              <a href="{{ route('admin.import-runs') }}"
                 class="inline-flex items-center rounded-full border border-amber-300/30 bg-amber-500/10 px-3 py-1.5 text-xs font-semibold text-amber-50/80">
                Import Runs
              </a>
            </div>
          </div>
        </div>
      @endif
      <div class="rounded-3xl mf-app-card mf-app-glow p-6 md:p-7 text-zinc-100">
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
    document.addEventListener('admin-tab-changed', scrollTop);
  })();
</script>

@fluxScripts
@livewireScripts
@livewireScriptConfig
</body>
</html>
