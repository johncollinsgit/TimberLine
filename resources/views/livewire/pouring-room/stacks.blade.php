<div class="space-y-6">
  <div class="flex flex-wrap items-center gap-2 text-[11px] text-emerald-800">
    <a href="{{ route('pouring.index') }}" class="rounded-full border border-zinc-200 bg-emerald-100 px-3 py-1 hover:bg-emerald-100">Pouring Room</a>
    <span class="rounded-full border border-zinc-200 bg-emerald-100 px-3 py-1 text-zinc-800">Stacks</span>
  </div>
  <livewire:pouring-room.dashboard-bar />

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Pouring Room</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">Stacks</div>
    <div class="mt-2 text-sm text-zinc-600">Choose a stack to focus on what needs poured next.</div>
  </section>

  <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
    @foreach(['retail' => 'Retail', 'wholesale' => 'Wholesale', 'event' => 'Events/Markets'] as $key => $label)
      @php($s = $summary[$key] ?? [])
      <a href="{{ route('pouring.stack', $key) }}" class="rounded-3xl border border-zinc-200 bg-white p-5 hover:border-emerald-300/30 transition">
        <div class="text-xs uppercase tracking-[0.3em] text-emerald-800">{{ $label }}</div>
        <div class="mt-3 flex items-end justify-between gap-3">
          <div class="text-3xl text-zinc-950 font-semibold">{{ $s['orders'] ?? 0 }}</div>
          <div class="text-xs text-zinc-600">{{ $s['units'] ?? 0 }} units</div>
        </div>
        <div class="mt-1 text-[11px] text-emerald-800">Orders</div>
        <div class="mt-2 text-xs text-emerald-800">Next due: {{ optional($s['earliest_due'] ?? null)->format('M j, Y') ?? '—' }}</div>
        <div class="mt-2 flex flex-wrap gap-2 text-[11px]">
          <span class="rounded-full border border-zinc-200 bg-emerald-50 px-2 py-1 text-zinc-600">Overdue: {{ $s['overdue'] ?? 0 }}</span>
          <span class="rounded-full border border-zinc-200 bg-emerald-50 px-2 py-1 text-zinc-600">Pending publish: {{ $s['pending_publish'] ?? 0 }}</span>
        </div>
        <div class="mt-3 text-[11px] text-emerald-800">
          <span class="text-zinc-500">Working now:</span>
          @php($active = $s['active_orders'] ?? collect())
          @if($active->isEmpty())
            <span class="text-zinc-500">None</span>
          @else
            <div class="mt-1 flex flex-wrap gap-2">
              @foreach($active->take(3) as $order)
                <span class="rounded-full border border-zinc-200 bg-emerald-100 px-2 py-1 text-zinc-700">
                  {{ $order->display_name ?? $order->order_number ?? 'Order' }}
                </span>
              @endforeach
              @if($active->count() > 3)
                <span class="rounded-full border border-zinc-200 bg-emerald-100 px-2 py-1 text-zinc-600">
                  +{{ $active->count() - 3 }} more
                </span>
              @endif
            </div>
          @endif
        </div>
      </a>
    @endforeach
  </div>

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="text-xs uppercase tracking-[0.3em] text-emerald-800">More Views</div>
    <div class="mt-3 flex flex-wrap gap-2">
      <a href="{{ route('pouring.all-candles') }}" class="rounded-full border border-emerald-400/25 bg-emerald-100 px-4 py-2 text-xs text-zinc-900">All Candles</a>
      <a href="{{ route('pouring.calendar') }}" class="rounded-full border border-emerald-400/25 bg-emerald-100 px-4 py-2 text-xs text-zinc-900">Calendar</a>
      <a href="{{ route('pouring.timeline') }}" class="rounded-full border border-emerald-400/25 bg-emerald-100 px-4 py-2 text-xs text-zinc-900">Timeline</a>
    </div>
  </section>
</div>
