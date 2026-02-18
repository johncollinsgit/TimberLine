{{-- resources/views/components/app-layout.blade.php --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
  @include('partials.head')
</head>
<body class="bg-zinc-950 text-zinc-100">
  {{-- Optional: your sidebar/toolbar wrapper if you have one --}}
  {{-- @include('partials.sidebar') --}}

  <main class="min-h-screen">
    {{ $slot }}
  </main>

  @stack('scripts')
</body>
</html>
