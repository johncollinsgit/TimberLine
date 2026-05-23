{{-- resources/views/components/app-layout.blade.php --}}
<x-layouts::app.sidebar :title="$title ?? null">
  {{ $slot }}
</x-layouts::app.sidebar>
