<div class="space-y-6">
  <div class="flex flex-wrap items-center gap-2 text-[11px] text-emerald-800">
    <a href="{{ route('pouring.index') }}" class="rounded-full border border-zinc-200 bg-emerald-100 px-3 py-1 hover:bg-emerald-100">Pouring Room</a>
    <span class="rounded-full border border-zinc-200 bg-emerald-100 px-3 py-1 text-zinc-800">Timeline</span>
  </div>
  <livewire:pouring-room.dashboard-bar />

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Timeline</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">Due Dates</div>
  </section>

  <div class="space-y-4">
    @forelse($groups as $date => $orders)
      <section class="rounded-3xl border border-zinc-200 bg-white p-5">
        <div class="text-sm text-zinc-700 font-semibold">{{ \Carbon\CarbonImmutable::parse($date)->format('M j, Y') }}</div>
        <div class="mt-3 space-y-2">
          @foreach($orders as $order)
            <a href="{{ route('pouring.order', $order) }}" class="block rounded-2xl border border-zinc-200 bg-emerald-50 px-4 py-3">
              <div class="text-sm text-zinc-900 font-semibold">{{ $order->display_name }}</div>
              <div class="mt-1 text-xs text-emerald-800">{{ $order->order_number ?? '—' }} · {{ ucfirst($order->channel) }}</div>
            </a>
          @endforeach
        </div>
      </section>
    @empty
      <section class="rounded-3xl border border-zinc-200 bg-white p-6 text-sm text-zinc-600">No due dates found.</section>
    @endforelse
  </div>
</div>
