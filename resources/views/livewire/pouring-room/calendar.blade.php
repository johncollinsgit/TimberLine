<div class="space-y-6">
  <div class="flex flex-wrap items-center gap-2 text-[11px] text-emerald-800">
    <a href="{{ route('pouring.index') }}" class="rounded-full border border-zinc-200 bg-emerald-100 px-3 py-1 hover:bg-emerald-100">Pouring Room</a>
    <span class="rounded-full border border-zinc-200 bg-emerald-100 px-3 py-1 text-zinc-800">Calendar</span>
  </div>
  <livewire:pouring-room.dashboard-bar />

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="flex items-center justify-between">
      <div>
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Calendar</div>
        <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">{{ $monthStart->format('F Y') }}</div>
      </div>
      <div class="flex gap-2">
        <button wire:click="prevMonth" class="rounded-full border border-emerald-400/25 bg-emerald-100 px-3 py-1 text-xs text-zinc-900">Prev</button>
        <button wire:click="nextMonth" class="rounded-full border border-emerald-400/25 bg-emerald-100 px-3 py-1 text-xs text-zinc-900">Next</button>
      </div>
    </div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="grid grid-cols-7 gap-2 text-[11px] text-zinc-500">
      @foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $day)
        <div class="text-center">{{ $day }}</div>
      @endforeach
    </div>
    <div class="mt-2 grid grid-cols-7 gap-2">
      @foreach($days as $day)
        <div class="min-h-[110px] rounded-2xl border border-zinc-200 p-2 {{ $day['in_month'] ? 'bg-emerald-50' : 'bg-zinc-50 text-zinc-500' }}">
          <div class="text-[11px] text-zinc-600">{{ $day['date']->format('j') }}</div>
          <div class="mt-1 space-y-1">
            @foreach($day['orders'] as $order)
              <a href="{{ route('pouring.order', $order) }}" class="block rounded-lg border border-emerald-300/20 bg-emerald-100 px-2 py-1 text-[10px] text-zinc-700 truncate">
                {{ $order->order_number ?? 'Order' }} · {{ $order->display_name }}
              </a>
            @endforeach
          </div>
        </div>
      @endforeach
    </div>
  </section>
</div>
