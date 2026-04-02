<div class="rounded-3xl border border-zinc-200 bg-white p-4">
  <div class="flex items-center justify-between">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Pouring Dashboard</div>
    <button wire:click="toggle" class="rounded-full border border-emerald-400/25 bg-emerald-100 px-3 py-1 text-[11px] text-zinc-700">
      {{ $enabled ? 'Hide' : 'Show' }} Dashboard
    </button>
  </div>

  @if($enabled)
    <div class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-5">
      <div class="rounded-2xl border border-zinc-200 bg-emerald-50 px-3 py-2 text-xs text-zinc-700">
        <div class="text-zinc-500">Total units</div>
        <div class="text-lg text-zinc-950">{{ $totalUnits }}</div>
      </div>
      <div class="rounded-2xl border border-zinc-200 bg-emerald-50 px-3 py-2 text-xs text-zinc-700">
        <div class="text-zinc-500">Retail units</div>
        <div class="text-lg text-zinc-950">{{ $summary['retail']['units'] ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-zinc-200 bg-emerald-50 px-3 py-2 text-xs text-zinc-700">
        <div class="text-zinc-500">Wholesale units</div>
        <div class="text-lg text-zinc-950">{{ $summary['wholesale']['units'] ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-zinc-200 bg-emerald-50 px-3 py-2 text-xs text-zinc-700">
        <div class="text-zinc-500">Market units</div>
        <div class="text-lg text-zinc-950">{{ $summary['event']['units'] ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-zinc-200 bg-emerald-50 px-3 py-2 text-xs text-zinc-700">
        <div class="text-zinc-500">Pending publish</div>
        <div class="text-lg text-zinc-950">{{ $pendingPublish }}</div>
      </div>
    </div>
  @endif
</div>
