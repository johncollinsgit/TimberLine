{{-- resources/views/components/app-layout.blade.php --}}
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
<body class="min-h-screen antialiased mf-app-shell {{ $wideLayout ? 'mf-wide' : '' }} {{ $compactTables ? 'mf-compact' : '' }}">
  <main class="min-h-screen p-4 sm:p-6">
    <div class="mx-auto w-full max-w-[1700px] fb-page-canvas mf-container">
      @isset($header)
        <section class="fb-page-surface fb-page-surface--subtle px-6 py-5">
          {{ $header }}
        </section>
      @endisset
      <div class="rounded-3xl mf-app-card mf-app-glow p-5 md:p-7">
      {{ $slot }}
      </div>
    </div>
  </main>

  @stack('scripts')
  @fluxScripts
</body>
</html>
