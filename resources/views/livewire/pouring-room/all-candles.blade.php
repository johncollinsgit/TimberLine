<div class="space-y-6">
  <div class="flex flex-wrap items-center gap-2 text-[11px] text-emerald-100/70">
    <a href="{{ route('pouring.index') }}" class="rounded-full border border-emerald-200/15 bg-emerald-500/10 px-3 py-1 hover:bg-emerald-500/20">Pouring Room</a>
    <span class="rounded-full border border-emerald-200/15 bg-emerald-500/20 px-3 py-1 text-white/85">All Candles</span>
  </div>
  <livewire:pouring-room.dashboard-bar />

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">All Candles</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Aggregate Pouring Needs</div>
    <div class="mt-2 text-sm text-emerald-50/70">Totals across all open orders.</div>
    <div class="mt-4 flex flex-wrap gap-2">
      @foreach(['all' => 'All', 'retail' => 'Retail', 'wholesale' => 'Wholesale', 'event' => 'Market'] as $key => $label)
        <button wire:click="$set('channel','{{ $key }}')" class="px-3 py-1.5 rounded-full text-xs border {{ $channel===$key ? 'border-emerald-300/35 bg-emerald-400/25 text-emerald-50' : 'border-emerald-400/15 bg-emerald-500/5 text-white/80' }}">{{ $label }}</button>
      @endforeach
      @foreach(['3' => 'Next 3 days', '7' => 'Next 7 days', '14' => 'Next 14 days', 'all' => 'All'] as $key => $label)
        <button wire:click="$set('dueWindow','{{ $key }}')" class="px-3 py-1.5 rounded-full text-xs border {{ $dueWindow===$key ? 'border-emerald-300/35 bg-emerald-400/25 text-emerald-50' : 'border-emerald-400/15 bg-emerald-500/5 text-white/80' }}">{{ $label }}</button>
      @endforeach
    </div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="rounded-2xl border border-emerald-200/10 overflow-hidden">
      <div class="grid grid-cols-5 gap-0 bg-black/30 text-[11px] text-white/50 px-3 py-2">
        <div>Scent</div>
        <div>Size/Wick</div>
        <div>Total</div>
        <div>Breakdown</div>
        <div>Earliest Due</div>
      </div>
      <div class="divide-y divide-emerald-200/10">
        @forelse($lines as $row)
          <div class="grid grid-cols-5 gap-0 px-3 py-2 text-xs text-white/80">
            <div>{{ $row['scent']?->display_name ?? $row['scent']?->name ?? 'Unknown' }}</div>
            <div>{{ $row['size']?->label ?? $row['size']?->code ?? '—' }} @if($row['wick']) · {{ ucfirst($row['wick']) }} @endif</div>
            <div>{{ $row['qty'] }}</div>
            <div class="text-[11px] text-white/60">
              @foreach(($row['breakdown'] ?? []) as $k => $v)
                <span class="mr-2">{{ ucfirst($k) }} {{ $v }}</span>
              @endforeach
            </div>
            <div>{{ optional($row['earliest_due'] ?? null)->format('M j, Y') ?? '—' }}</div>
          </div>
        @empty
          <div class="px-3 py-3 text-xs text-white/60">No lines found.</div>
        @endforelse
      </div>
    </div>
  </section>
</div>
