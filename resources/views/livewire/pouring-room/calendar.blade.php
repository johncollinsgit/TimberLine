<div class="space-y-6">
  <div class="flex flex-wrap items-center gap-2 text-[11px] text-emerald-100/70">
    <a href="{{ route('pouring.index') }}" class="rounded-full border border-emerald-200/15 bg-emerald-500/10 px-3 py-1 hover:bg-emerald-500/20">Pouring Room</a>
    <span class="rounded-full border border-emerald-200/15 bg-emerald-500/20 px-3 py-1 text-white/85">Calendar</span>
  </div>
  <livewire:pouring-room.dashboard-bar />

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="flex items-center justify-between">
      <div>
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Calendar</div>
        <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">{{ $monthStart->format('F Y') }}</div>
      </div>
      <div class="flex gap-2">
        <button wire:click="prevMonth" class="rounded-full border border-emerald-400/25 bg-emerald-500/15 px-3 py-1 text-xs text-white/90">Prev</button>
        <button wire:click="nextMonth" class="rounded-full border border-emerald-400/25 bg-emerald-500/15 px-3 py-1 text-xs text-white/90">Next</button>
      </div>
    </div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="grid grid-cols-7 gap-2 text-[11px] text-white/50">
      @foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $day)
        <div class="text-center">{{ $day }}</div>
      @endforeach
    </div>
    <div class="mt-2 grid grid-cols-7 gap-2">
      @foreach($days as $day)
        <div class="min-h-[110px] rounded-2xl border border-emerald-200/10 p-2 {{ $day['in_month'] ? 'bg-emerald-500/5' : 'bg-black/20 text-white/40' }}">
          <div class="text-[11px] text-white/70">{{ $day['date']->format('j') }}</div>
          <div class="mt-1 space-y-1">
            @foreach($day['orders'] as $order)
              <a href="{{ route('pouring.order', $order) }}" class="block rounded-lg border border-emerald-300/20 bg-emerald-500/10 px-2 py-1 text-[10px] text-white/80 truncate">
                {{ $order->order_number ?? 'Order' }} · {{ $order->display_name }}
              </a>
            @endforeach
          </div>
        </div>
      @endforeach
    </div>
  </section>
</div>
