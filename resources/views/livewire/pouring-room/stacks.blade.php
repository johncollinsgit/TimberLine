<div class="space-y-6">
  <div class="flex flex-wrap items-center gap-2 text-[11px] text-emerald-100/70">
    <a href="{{ route('pouring.index') }}" class="rounded-full border border-emerald-200/15 bg-emerald-500/10 px-3 py-1 hover:bg-emerald-500/20">Pouring Room</a>
    <span class="rounded-full border border-emerald-200/15 bg-emerald-500/20 px-3 py-1 text-white/85">Stacks</span>
  </div>
  <livewire:pouring-room.dashboard-bar />

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Pouring Room</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Stacks</div>
    <div class="mt-2 text-sm text-emerald-50/70">Choose a stack to focus on what needs poured next.</div>
  </section>

  <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
    @foreach(['retail' => 'Retail', 'wholesale' => 'Wholesale', 'event' => 'Events/Markets'] as $key => $label)
      @php($s = $summary[$key] ?? [])
      <a href="{{ route('pouring.stack', $key) }}" class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5 hover:border-emerald-300/30 transition">
        <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60">{{ $label }}</div>
        <div class="mt-3 flex items-end justify-between gap-3">
          <div class="text-3xl text-white font-semibold">{{ $s['orders'] ?? 0 }}</div>
          <div class="text-xs text-white/65">{{ $s['units'] ?? 0 }} units</div>
        </div>
        <div class="mt-1 text-[11px] text-emerald-100/60">Orders</div>
        <div class="mt-2 text-xs text-emerald-100/60">Next due: {{ optional($s['earliest_due'] ?? null)->format('M j, Y') ?? '—' }}</div>
        <div class="mt-2 flex flex-wrap gap-2 text-[11px]">
          <span class="rounded-full border border-emerald-200/10 bg-emerald-500/5 px-2 py-1 text-white/70">Overdue: {{ $s['overdue'] ?? 0 }}</span>
          <span class="rounded-full border border-emerald-200/10 bg-emerald-500/5 px-2 py-1 text-white/70">Pending publish: {{ $s['pending_publish'] ?? 0 }}</span>
        </div>
        <div class="mt-3 text-[11px] text-emerald-100/70">
          <span class="text-white/60">Working now:</span>
          @php($active = $s['active_orders'] ?? collect())
          @if($active->isEmpty())
            <span class="text-white/40">None</span>
          @else
            <div class="mt-1 flex flex-wrap gap-2">
              @foreach($active->take(3) as $order)
                <span class="rounded-full border border-emerald-200/15 bg-emerald-500/10 px-2 py-1 text-white/80">
                  {{ $order->display_name ?? $order->order_number ?? 'Order' }}
                </span>
              @endforeach
              @if($active->count() > 3)
                <span class="rounded-full border border-emerald-200/15 bg-emerald-500/10 px-2 py-1 text-white/70">
                  +{{ $active->count() - 3 }} more
                </span>
              @endif
            </div>
          @endif
        </div>
      </a>
    @endforeach
  </div>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60">More Views</div>
    <div class="mt-3 flex flex-wrap gap-2">
      <a href="{{ route('pouring.all-candles') }}" class="rounded-full border border-emerald-400/25 bg-emerald-500/15 px-4 py-2 text-xs text-white/90">All Candles</a>
      <a href="{{ route('pouring.calendar') }}" class="rounded-full border border-emerald-400/25 bg-emerald-500/15 px-4 py-2 text-xs text-white/90">Calendar</a>
      <a href="{{ route('pouring.timeline') }}" class="rounded-full border border-emerald-400/25 bg-emerald-500/15 px-4 py-2 text-xs text-white/90">Timeline</a>
    </div>
  </section>
</div>
