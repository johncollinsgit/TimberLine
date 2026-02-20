{{-- resources/views/components/app-layout.blade.php --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
  @include('partials.head')
</head>
@php
  $prefs = is_array(auth()->user()?->ui_preferences ?? null) ? auth()->user()->ui_preferences : [];
  $wideLayout = !empty($prefs['wide_layout']);
  $compactTables = !empty($prefs['compact_tables']);
@endphp
<body class="min-h-screen text-zinc-100 antialiased mf-app-shell {{ $wideLayout ? 'mf-wide' : '' }} {{ $compactTables ? 'mf-compact' : '' }}">
  <main class="min-h-screen p-6">
    <div class="mx-auto w-full max-w-[1600px] rounded-3xl mf-app-card mf-app-glow p-6 md:p-7 mf-container">
      {{ $slot }}
    </div>
  </main>

  @stack('scripts')
  @fluxScripts
</body>
</html>
