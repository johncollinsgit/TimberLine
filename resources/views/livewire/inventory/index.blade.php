<div class="space-y-6">
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)]">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Inventory</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Unclaimed Candles</div>
    <div class="mt-2 text-sm text-emerald-50/70">Tracks candles poured for inventory (not tied to a customer).</div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="flex items-center justify-between">
      <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60">All Scents</div>
      <input type="text" wire:model.live.debounce.250ms="search"
        placeholder="Search scents..."
        class="h-9 rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-xs text-white/90" />
    </div>
    <div class="mt-4 rounded-2xl border border-emerald-200/10 overflow-hidden">
      <div class="grid grid-cols-5 gap-0 bg-black/30 text-[11px] text-white/50 px-3 py-2">
        <div>Scent</div>
        <div>Size</div>
        <div>Total Poured</div>
        <div>On Hand</div>
        <div>Status</div>
      </div>
      <div class="divide-y divide-emerald-200/10">
        @forelse($scents as $scent)
          <div class="grid grid-cols-5 gap-0 px-3 py-2 text-xs text-white/80">
            <div class="font-semibold">{{ $scent['name'] }}</div>
            <div class="text-white/70">{{ $scent['size'] }}</div>
            <div>{{ $scent['qty'] }}</div>
            <div>
              <input type="number" min="0" value="{{ $scent['on_hand'] }}"
                wire:change="updateOnHand({{ $scent['id'] }}, {{ $scent['size_id'] ?? 'null' }}, $event.target.value)"
                class="w-20 rounded-lg border border-emerald-200/10 bg-black/20 px-2 py-1 text-xs text-white/90 appearance-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none" />
            </div>
            <div class="text-white/50">Unclaimed</div>
          </div>
        @empty
          <div class="px-3 py-3 text-xs text-white/60">No inventory pours yet.</div>
        @endforelse
      </div>
    </div>
  </section>
</div>
