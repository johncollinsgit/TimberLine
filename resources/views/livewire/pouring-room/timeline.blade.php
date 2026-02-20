<div class="space-y-6">
  <div class="flex flex-wrap items-center gap-2 text-[11px] text-emerald-100/70">
    <a href="{{ route('pouring.index') }}" class="rounded-full border border-emerald-200/15 bg-emerald-500/10 px-3 py-1 hover:bg-emerald-500/20">Pouring Room</a>
    <span class="rounded-full border border-emerald-200/15 bg-emerald-500/20 px-3 py-1 text-white/85">Timeline</span>
  </div>
  <livewire:pouring-room.dashboard-bar />

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Timeline</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Due Dates</div>
  </section>

  <div class="space-y-4">
    @forelse($groups as $date => $orders)
      <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5">
        <div class="text-sm text-white/80 font-semibold">{{ \Carbon\CarbonImmutable::parse($date)->format('M j, Y') }}</div>
        <div class="mt-3 space-y-2">
          @foreach($orders as $order)
            <a href="{{ route('pouring.order', $order) }}" class="block rounded-2xl border border-emerald-200/10 bg-emerald-500/5 px-4 py-3">
              <div class="text-sm text-white/90 font-semibold">{{ $order->display_name }}</div>
              <div class="mt-1 text-xs text-emerald-100/60">{{ $order->order_number ?? '—' }} · {{ ucfirst($order->channel) }}</div>
            </a>
          @endforeach
        </div>
      </section>
    @empty
      <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 text-sm text-emerald-50/70">No due dates found.</section>
    @endforelse
  </div>
</div>
