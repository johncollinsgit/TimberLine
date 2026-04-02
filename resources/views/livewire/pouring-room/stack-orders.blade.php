@php
  $labels = ['retail' => 'Retail', 'wholesale' => 'Wholesale', 'event' => 'Events/Markets'];
  $title = $labels[$channel] ?? ucfirst($channel);
  $stateLabel = ($state ?? 'current') === 'actual' ? 'Actual (Brought Down / Verified)' : 'Current (Submitted / Pouring)';
  $celebrate = request()->boolean('celebrate');
@endphp

<div class="space-y-6">
  @if($celebrate)
    <style>
      .mf-firework {
        position: absolute;
        width: 8px;
        height: 8px;
        border-radius: 999px;
        opacity: 0;
        animation: mf-firework-burst 900ms ease-out forwards;
        animation-delay: var(--d);
        left: var(--x);
        top: var(--y);
        background: hsl(var(--h) 90% 60%);
        box-shadow: 0 0 22px hsla(var(--h), 100%, 70%, .95);
      }
      @keyframes mf-firework-burst {
        0% { transform: translate(0, 0) scale(.2); opacity: 0; }
        12% { opacity: 1; }
        100% { transform: translate(var(--tx), var(--ty)) scale(1.15); opacity: 0; }
      }
    </style>
    <div id="mf-celebration" class="pointer-events-none fixed inset-0 z-[90] overflow-hidden">
      <div class="absolute left-1/2 top-14 -translate-x-1/2 rounded-full border border-fuchsia-300/45 bg-fuchsia-500/30 px-6 py-2 text-base font-semibold text-zinc-950 shadow-xl">
        🦄 Order Complete!
      </div>
      @for($i = 0; $i < 48; $i++)
        <span class="mf-firework"
          style="
            --x: {{ mt_rand(10, 90) }}%;
            --y: {{ mt_rand(12, 58) }}%;
            --tx: {{ mt_rand(-120, 120) }}px;
            --ty: {{ mt_rand(-120, 120) }}px;
            --h: {{ mt_rand(0, 360) }};
            --d: {{ mt_rand(0, 450) }}ms;
          "></span>
      @endfor
    </div>
    <script>
      (function () {
        setTimeout(function () {
          const el = document.getElementById('mf-celebration');
          if (el) el.remove();
          const u = new URL(window.location.href);
          u.searchParams.delete('celebrate');
          window.history.replaceState({}, '', u.toString());
        }, 2200);
      })();
    </script>
  @endif

  <div class="flex flex-wrap items-center gap-2 text-[11px] text-emerald-800">
    <a href="{{ route('pouring.index') }}" class="rounded-full border border-zinc-200 bg-emerald-100 px-3 py-1 hover:bg-emerald-100">Pouring Room</a>
    <a href="{{ route('pouring.index') }}" class="rounded-full border border-zinc-200 bg-emerald-100 px-3 py-1 hover:bg-emerald-100">Stacks</a>
    <span class="rounded-full border border-zinc-200 bg-emerald-100 px-3 py-1 text-zinc-800">{{ $title }}</span>
  </div>
  <livewire:pouring-room.dashboard-bar />

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Stack</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">{{ $title }}</div>
    <div class="mt-2 text-sm text-zinc-600">Orders sorted by what must be poured next.</div>
    <div class="mt-2 inline-flex items-center rounded-full border border-emerald-300/25 bg-emerald-100 px-3 py-1 text-[11px] uppercase tracking-[0.2em] text-emerald-800">
      {{ $stateLabel }}
    </div>
    <div class="mt-4 flex flex-wrap items-center gap-2">
      <button wire:click="$set('sort','due')" class="px-3 py-1.5 rounded-full text-xs border {{ $sort==='due' ? 'border-zinc-300 bg-emerald-400/25 text-emerald-900' : 'border-emerald-400/15 bg-emerald-50 text-zinc-700' }}">Due soon</button>
      <button wire:click="$set('sort','largest')" class="px-3 py-1.5 rounded-full text-xs border {{ $sort==='largest' ? 'border-zinc-300 bg-emerald-400/25 text-emerald-900' : 'border-emerald-400/15 bg-emerald-50 text-zinc-700' }}">Largest first</button>
      <button wire:click="$set('sort','recent')" class="px-3 py-1.5 rounded-full text-xs border {{ $sort==='recent' ? 'border-zinc-300 bg-emerald-400/25 text-emerald-900' : 'border-emerald-400/15 bg-emerald-50 text-zinc-700' }}">Recently updated</button>
      <button wire:click="submitSelected" class="ml-auto px-3 py-1.5 rounded-full text-xs border border-emerald-400/25 bg-emerald-100 text-zinc-900">Submit selected</button>
    </div>
  </section>

  <div class="space-y-3">
    @forelse($orders as $order)
      @php
        $label = $order->display_name ?? $order->order_label ?? $order->order_number ?? 'Order';
        $urgencyLabel = $urgency($order->due_at);
        $orderUrl = route('pouring.order', ['order' => $order, 'return_to' => url()->full()]);
      @endphp
      <div
        class="rounded-3xl border border-zinc-200 bg-white p-5 cursor-pointer"
        role="link"
        tabindex="0"
        onclick="window.location='{{ $orderUrl }}'"
        onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); window.location='{{ $orderUrl }}'; }">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div class="min-w-0">
            <div class="text-sm text-zinc-900 font-semibold truncate">{{ $label }}</div>
            <div class="mt-1 text-xs text-emerald-800">
              {{ $order->order_number ?? '—' }} · {{ $urgencyLabel }} · Due {{ optional($order->due_at)->format('M j, Y') ?? '—' }}
            </div>
          </div>
          <div class="flex items-center gap-2">
            <span class="rounded-full border border-zinc-200 bg-emerald-50 px-3 py-1 text-xs text-zinc-700">{{ $order->units }} units</span>
            <a href="{{ $orderUrl }}" onclick="event.stopPropagation()" class="px-3 py-1.5 rounded-full text-xs border border-emerald-400/25 bg-emerald-100 text-zinc-900">Open</a>
            <button wire:click.stop="toggleSelect({{ $order->id }})" onclick="event.stopPropagation()" class="px-3 py-1.5 rounded-full text-xs border border-zinc-300 bg-zinc-50 text-zinc-700">
              {{ !empty($selected[$order->id] ?? false) ? 'Selected' : 'Select' }}
            </button>
            <button wire:click.stop="startOrder({{ $order->id }})" onclick="event.stopPropagation()"
              class="px-3 py-1.5 rounded-full text-xs border border-emerald-400/25 bg-emerald-100 text-zinc-900 {{ ($order->status ?? '') !== 'submitted_to_pouring' ? 'opacity-40 cursor-not-allowed' : '' }}"
              @if(($order->status ?? '') !== 'submitted_to_pouring') disabled @endif>
              Start
            </button>
            <button wire:click.stop="completeOrder({{ $order->id }})" onclick="event.stopPropagation()"
              class="px-3 py-1.5 rounded-full text-xs border border-emerald-400/25 bg-emerald-100 text-zinc-900 {{ !in_array($order->status ?? '', ['pouring','brought_down'], true) ? 'opacity-40 cursor-not-allowed' : '' }}"
              @if(!in_array($order->status ?? '', ['pouring','brought_down'], true)) disabled @endif>
              Complete
            </button>
          </div>
        </div>
      </div>
    @empty
      <div class="rounded-3xl border border-zinc-200 bg-white p-6 text-sm text-zinc-600">No orders ready for pouring.</div>
    @endforelse
  </div>
</div>
