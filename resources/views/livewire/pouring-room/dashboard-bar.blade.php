<div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-4">
  <div class="flex items-center justify-between">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Pouring Dashboard</div>
    <button wire:click="toggle" class="rounded-full border border-emerald-400/25 bg-emerald-500/15 px-3 py-1 text-[11px] text-white/80">
      {{ $enabled ? 'Hide' : 'Show' }} Dashboard
    </button>
  </div>

  @if($enabled)
    <div class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-5">
      <div class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 px-3 py-2 text-xs text-white/80">
        <div class="text-white/50">Total units</div>
        <div class="text-lg text-white">{{ $totalUnits }}</div>
      </div>
      <div class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 px-3 py-2 text-xs text-white/80">
        <div class="text-white/50">Retail units</div>
        <div class="text-lg text-white">{{ $summary['retail']['units'] ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 px-3 py-2 text-xs text-white/80">
        <div class="text-white/50">Wholesale units</div>
        <div class="text-lg text-white">{{ $summary['wholesale']['units'] ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 px-3 py-2 text-xs text-white/80">
        <div class="text-white/50">Market units</div>
        <div class="text-lg text-white">{{ $summary['event']['units'] ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 px-3 py-2 text-xs text-white/80">
        <div class="text-white/50">Pending publish</div>
        <div class="text-lg text-white">{{ $pendingPublish }}</div>
      </div>
    </div>
  @endif
</div>
