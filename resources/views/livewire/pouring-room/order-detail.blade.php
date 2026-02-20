<div class="space-y-6">
  <div class="flex flex-wrap items-center gap-2 text-[11px] text-emerald-100/70">
    <a href="{{ route('pouring.index') }}" class="rounded-full border border-emerald-200/15 bg-emerald-500/10 px-3 py-1 hover:bg-emerald-500/20">Pouring Room</a>
    <a href="{{ route('pouring.stack', $order->channel ?? $order->order_type ?? 'retail') }}" class="rounded-full border border-emerald-200/15 bg-emerald-500/10 px-3 py-1 hover:bg-emerald-500/20">Stack</a>
    <span class="rounded-full border border-emerald-200/15 bg-emerald-500/20 px-3 py-1 text-white/85">{{ $order->order_number ?? 'Order' }}</span>
  </div>
  <livewire:pouring-room.dashboard-bar />

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Order</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">{{ $order->display_name }}</div>
    <div class="mt-2 text-sm text-emerald-50/70">
      {{ $order->order_number ?? '—' }} · {{ ucfirst($order->channel) }} · Due {{ optional($order->due_at)->format('M j, Y') ?? '—' }}
      @if($order->ship_by_at)
        · Ship {{ $order->ship_by_at->format('M j, Y') }}
      @endif
    </div>
    <div class="mt-4 flex flex-wrap gap-2">
      <button wire:click="start" class="rounded-full border border-emerald-400/25 bg-emerald-500/15 px-4 py-2 text-xs text-white/90">Start this order</button>
      <button wire:click="complete" class="rounded-full border border-emerald-400/25 bg-emerald-500/20 px-4 py-2 text-xs text-white/90">Mark complete</button>
      <button wire:click="toggleCompleted" class="rounded-full border border-emerald-400/30 bg-emerald-500/10 px-4 py-2 text-xs text-white/90">
        {{ $showCompleted ? 'Hide Completed' : 'See Completed' }} ({{ $completedCount }})
      </button>
    </div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60">What needs to be made</div>
    <div class="mt-4 space-y-3">
      @foreach($groups as $group)
        <div class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 p-4">
          <div class="grid gap-4 lg:grid-cols-[1.4fr,0.6fr]">
            <div>
              <div class="flex items-center justify-between gap-3">
                <div>
                  <div class="text-lg text-white/95 font-semibold">{{ $group['scent']?->display_name ?? $group['scent']?->name ?? 'Unknown scent' }}</div>
                  <div class="text-xs text-emerald-100/60">
                    {{ $group['size']?->label ?? $group['size']?->code ?? 'Unknown size' }}
                    @if($group['wick']) · {{ ucfirst($group['wick']) }} wick @endif
                  </div>
                </div>
                <div class="rounded-full border border-emerald-200/15 bg-emerald-500/20 px-3 py-1 text-sm text-white">{{ $group['qty'] }}</div>
              </div>
              <div class="mt-3 flex flex-wrap items-center gap-2">
                @foreach(['laid_out', 'first_pour', 'second_pour', 'brought_down'] as $status)
                  <button
                    wire:click="setGroupStatus(@js($group['key']), '{{ $status }}')"
                    class="rounded-full border px-3 py-1 text-[11px] transition
                      {{ ($group['status'] ?? '') === $status
                        ? 'border-emerald-300/40 bg-emerald-500/25 text-emerald-50'
                        : 'border-emerald-200/15 bg-emerald-500/5 text-emerald-100/75 hover:bg-emerald-500/15' }}">
                    {{ $statusOptions[$status] }}
                  </button>
                @endforeach
                <button
                  wire:click="setGroupStatus(@js($group['key']), 'waiting_on_oil')"
                  class="rounded-full border px-3 py-1 text-[11px] transition
                    {{ ($group['status'] ?? '') === 'waiting_on_oil'
                      ? 'border-amber-300/45 bg-amber-500/25 text-amber-50'
                      : 'border-amber-200/20 bg-amber-500/10 text-amber-100/80 hover:bg-amber-500/20' }}">
                  {{ $statusOptions['waiting_on_oil'] }}
                </button>
              </div>
            </div>
            <div class="rounded-2xl border border-emerald-200/15 bg-black/30 p-3">
              <div class="flex items-center justify-between gap-2">
                <div class="text-[11px] uppercase tracking-[0.25em] text-emerald-100/60">Materials</div>
                <div class="text-[10px] text-emerald-100/65">
                  Pitchers: {{ count($group['pitchers'] ?? []) }}
                </div>
              </div>
              @if(!empty($group['ingredients']))
                <div class="mt-2 flex flex-wrap gap-2 text-[11px] text-white/90">
                  @forelse(($group['pitchers'] ?? []) as $pitcher)
                    @php
                      $pillStyles = [
                        'border-cyan-300/45 bg-cyan-500/30',
                        'border-amber-300/45 bg-amber-500/30',
                        'border-fuchsia-300/45 bg-fuchsia-500/30',
                        'border-lime-300/45 bg-lime-500/30',
                      ];
                      $pillClass = $pillStyles[($pitcher['index'] - 1) % count($pillStyles)];
                    @endphp
                    <span class="rounded-full border {{ $pillClass }} px-5 py-2 text-base font-semibold text-white">
                      Pitcher {{ $pitcher['index'] }}: Wax {{ $pitcher['wax_grams'] }}g · Oil {{ $pitcher['oil_grams'] }}g
                    </span>
                  @empty
                    <span class="rounded-full border border-white/15 bg-white/5 px-2.5 py-1 text-white/70">
                      No pitcher mix required
                    </span>
                  @endforelse
                </div>
                <div class="mt-2 text-[11px] text-emerald-100/70">Total: {{ $group['ingredients']['total_grams'] ?? 0 }} g</div>
              @else
                <div class="mt-2 text-[11px] text-emerald-100/70">No formulation found.</div>
              @endif
            </div>
          </div>
          <div class="mt-2 text-[10px] text-emerald-100/70">
            @if($group['scent']?->oilBlend)
              <div class="flex items-center gap-2 overflow-x-auto whitespace-nowrap pr-1">
                <span>Recipe: {{ $group['scent']->oilBlend->name }}</span>
                @foreach($group['scent']->oilBlend->components as $component)
                  <span class="rounded-full border border-emerald-200/10 bg-emerald-500/10 px-2 py-0.5 text-[10px] text-emerald-50">
                    {{ $component->baseOil?->name ?? 'Oil' }} ({{ $component->ratio_weight }})
                  </span>
                @endforeach
              </div>
            @else
              <div class="rounded-xl border border-amber-300/30 bg-amber-500/10 px-3 py-2 text-amber-100">
                Recipe missing for {{ $group['scent']?->name ?? 'this scent' }}.
                <a href="{{ route('admin.oils.blends') }}" class="underline">Open Recipes Admin</a>
              </div>
            @endif
          </div>
        </div>
      @endforeach
    </div>
  </section>
</div>
