<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
  @include('partials.head')
  @livewireStyles
@livewireScripts
@livewireScriptConfig


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
        radial-gradient(700px 400px at 0% 0%, rgba(99,102,241,.22), transparent 55%),
        radial-gradient(600px 350px at 0% 100%, rgba(56,189,248,.16), transparent 55%);
      filter: blur(14px);
      pointer-events:none;
      opacity:.9;
    }

    .mf-nav-item { will-change: transform; }
    .mf-nav-item:hover { transform: translateX(2px); }

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
      background: linear-gradient(to bottom, rgba(56,189,248,.95), rgba(99,102,241,.45));
      box-shadow: 0 0 0 1px rgba(56,189,248,.25), 0 10px 25px rgba(56,189,248,.18);
    }

    /* Surface: explicit light + explicit dark (prevents “whiting out”) */
    .mf-surface{
      background:
        radial-gradient(900px 520px at 10% 0%, rgba(56,189,248,.10), transparent 58%),
        radial-gradient(900px 520px at 90% 10%, rgba(99,102,241,.08), transparent 60%),
        linear-gradient(to bottom, rgba(255,255,255,.80), rgba(255,255,255,.65));
    }
    .dark .mf-surface{
      background:
        radial-gradient(900px 520px at 10% 0%, rgba(56,189,248,.12), transparent 58%),
        radial-gradient(900px 520px at 90% 10%, rgba(99,102,241,.10), transparent 60%),
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

<body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased">
@php
  use Illuminate\Support\Facades\Route;

  $hrefDashboard = Route::has('dashboard')        ? route('dashboard')        : '/dashboard';
  $hrefShipping  = Route::has('shipping.orders')  ? route('shipping.orders')  : '/shipping/orders';
  $hrefPouring   = Route::has('pouring.index')    ? route('pouring.index')    : '/pouring';
  $hrefAdmin     = Route::has('admin.index')      ? route('admin.index')      : '/admin';
  $hrefAnalytics = Route::has('analytics.index')  ? route('analytics.index')  : '/analytics';

  $shippingActive  = request()->routeIs('shipping.*')  || request()->is('shipping*');
  $pouringActive   = request()->routeIs('pouring.*')   || request()->is('pouring*');
  $adminActive     = request()->routeIs('admin.*')     || request()->is('admin*');
  $analyticsActive = request()->routeIs('analytics.*') || request()->is('analytics*');
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
        <flux:sidebar.group heading="Production OS" class="grid">
          <div class="space-y-1">
            <div class="{{ $shippingActive ? 'mf-active-pill' : '' }}">
              <flux:sidebar.item icon="truck" href="{{ $hrefShipping }}" :current="$shippingActive" wire:navigate class="mf-transition mf-nav-item">
                Shipping Room
              </flux:sidebar.item>
            </div>

            <div class="{{ $pouringActive ? 'mf-active-pill' : '' }}">
              <flux:sidebar.item icon="fire" href="{{ $hrefPouring }}" :current="$pouringActive" wire:navigate class="mf-transition mf-nav-item">
                Pouring Room
              </flux:sidebar.item>
            </div>

            <div class="{{ $adminActive ? 'mf-active-pill' : '' }}">
              <flux:sidebar.item icon="cog" href="{{ $hrefAdmin }}" :current="$adminActive" wire:navigate class="mf-transition mf-nav-item">
                Administration
              </flux:sidebar.item>
            </div>

            <div class="{{ $analyticsActive ? 'mf-active-pill' : '' }}">
              <flux:sidebar.item icon="chart-bar" href="{{ $hrefAnalytics }}" :current="$analyticsActive" wire:navigate class="mf-transition mf-nav-item">
                Analytics
              </flux:sidebar.item>
            </div>
          </div>
        </flux:sidebar.group>

        <flux:sidebar.group heading="Quick Actions" class="grid mt-2">
          {{-- Wire these to real routes/actions when you have them; keeping your intent intact --}}
          <flux:sidebar.item icon="plus" href="{{ $hrefShipping }}" wire:navigate class="mf-transition mf-nav-item">
            New manual order
          </flux:sidebar.item>
          <flux:sidebar.item icon="clock" href="{{ $hrefShipping }}" wire:navigate class="mf-transition mf-nav-item">
            Due soon
          </flux:sidebar.item>
        </flux:sidebar.group>
      </flux:sidebar.nav>

      <flux:spacer />

      <flux:sidebar.nav class="opacity-90">
        <flux:sidebar.group heading="Help" class="grid">
          <flux:sidebar.item icon="book-open" href="https://laravel.com/docs" target="_blank" class="mf-transition mf-nav-item">
            Laravel Docs
          </flux:sidebar.item>
        </flux:sidebar.group>
      </flux:sidebar.nav>

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
    <main class="flex-1 min-w-0 overflow-y-auto p-6 mf-fade-in">
      <div class="rounded-3xl mf-surface mf-soft-edge p-6 md:p-7 text-zinc-900 dark:text-zinc-100">
        {{ $slot }}
      </div>
    </main>

  </div>
</div>

@livewireScripts
@livewireScriptConfig
@fluxScripts
</body>
</html>
