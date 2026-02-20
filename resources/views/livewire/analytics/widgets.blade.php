@php($data = $this->analyticsData)
@php($widgets = $this->visibleWidgets)
@php($library = $this->widgetLibrary)
@php($typeBadge = function ($type) {
    $type = strtolower((string) $type);
    return match ($type) {
      'wholesale' => 'border-amber-300/35 bg-amber-400/20 text-amber-50',
      'event', 'market' => 'border-purple-300/35 bg-purple-400/20 text-purple-50',
      default => 'border-sky-300/35 bg-sky-400/20 text-sky-50',
    };
})

<div class="space-y-6" data-widget-root wire:ignore.self>
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)]">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
      <div>
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Analytics</div>
        <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Operational Insights</div>
        <div class="mt-2 text-sm text-emerald-50/70">Drag widgets, add from the library, and save your layout.</div>
      </div>
      <div class="flex flex-col items-start gap-3 md:items-end">
        <div class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 px-4 py-3 text-xs text-emerald-50/80">
          Widgets: {{ count($widgets) }}
        </div>
        <button type="button" wire:click="toggleLibrary"
          class="inline-flex items-center rounded-full border px-4 py-2 text-sm font-semibold transition {{ $showLibrary ? 'border-emerald-300/60 bg-emerald-500/35 text-white' : 'border-emerald-200/25 bg-emerald-500/10 text-emerald-100/90 hover:bg-emerald-500/20' }}">
          Widgets Tray
        </button>
      </div>
    </div>
  </section>

  @if($showLibrary)
    <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
      <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60">Widget Library</div>
      <div class="mt-2 text-sm text-emerald-50/70">Click to add. Drag to reorder on the canvas.</div>
      <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3" data-widget-library>
        @foreach($library as $widget)
          @php($enabled = in_array($widget['id'], array_column($widgets, 'id'), true))
          <button type="button" wire:click="addWidget('{{ $widget['id'] }}')"
            class="rounded-2xl border px-4 py-3 text-left transition
            {{ $enabled ? 'border-emerald-400/30 bg-emerald-500/15 text-white' : 'border-emerald-200/10 bg-emerald-500/5 text-white/70 hover:bg-emerald-500/10' }}"
            data-widget-id="{{ $widget['id'] }}"
            @if($enabled) disabled @endif>
            <div class="text-sm font-semibold">{{ $widget['title'] }}</div>
            <div class="mt-1 text-xs text-emerald-100/60">{{ $widget['description'] }}</div>
            <div class="mt-2 text-[11px] uppercase tracking-[0.2em] text-emerald-100/50">
              {{ $enabled ? 'Added' : 'Add Widget' }}
            </div>
          </button>
        @endforeach
      </div>
    </section>
  @endif

  <div class="grid grid-cols-1 md:grid-cols-12 gap-6" data-widget-grid>
    @foreach($widgets as $w)
      @php($size = $w['size'] ?? '2')
      @php($span = $size === '1' ? 'md:col-span-4' : ($size === '2' ? 'md:col-span-8' : 'md:col-span-12'))
      @php($id = $w['id'] ?? '')
      @php($title = $w['title'] ?? ($id ?: 'Widget'))

      <section
        class="rounded-2xl border border-emerald-200/10 bg-[#0f1412]/80 backdrop-blur p-5 shadow-[0_20px_60px_-40px_rgba(0,0,0,0.9)] min-h-[220px] {{ $span }} cursor-move"
        data-widget
        data-widget-id="{{ $id }}"
      >
        <div class="flex items-center justify-between gap-3 mb-3">
          <div class="flex items-center gap-3">
            <h3 class="text-sm font-semibold text-white/90">{{ $title }}</h3>
          </div>
          <div class="flex items-center gap-2">
            <div class="flex items-center gap-1 rounded-full border border-emerald-200/10 bg-emerald-500/5 px-1 py-0.5">
              @foreach(['1' => '1', '2' => '2', '3' => '3'] as $val => $label)
                <button type="button" wire:click="setWidgetSize('{{ $id }}','{{ $val }}')"
                  class="mf-no-drag h-6 w-6 rounded-full text-[11px] font-semibold transition
                    {{ $size === $val ? 'bg-emerald-400/60 text-white' : 'text-emerald-100/70 hover:bg-emerald-400/20' }}">
                  {{ $label }}
                </button>
              @endforeach
            </div>
            <button type="button" wire:click="removeWidget('{{ $id }}')"
              class="mf-no-drag text-[10px] uppercase tracking-[0.2em] text-emerald-100/50 hover:text-emerald-100/80">
              remove
            </button>
          </div>
        </div>

        @if($id === 'orders_by_type')
          <div class="h-[240px]" wire:ignore>
            <canvas data-analytics-chart="type" class="!h-full !w-full"></canvas>
          </div>
        @elseif($id === 'orders_by_status')
          <div class="h-[240px]" wire:ignore>
            <canvas data-analytics-chart="status" class="!h-full !w-full"></canvas>
          </div>
        @elseif($id === 'exceptions')
          <div class="space-y-3 text-sm text-white/70">
            <div class="rounded-xl border border-amber-200/20 bg-amber-500/10 p-3">
              Unresolved exceptions: <span class="text-white/90 font-semibold">{{ $data['exceptions'] ?? 0 }}</span>
            </div>
          </div>
        @elseif($id === 'recent_orders')
          <div class="overflow-x-auto rounded-xl border border-emerald-200/10">
            <table class="min-w-full text-sm">
              <thead class="bg-white/5 text-white/70">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">Order</th>
                  <th class="px-3 py-2 text-left font-medium">Customer</th>
                  <th class="px-3 py-2 text-left font-medium">Type</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/10">
                @forelse(($data['recentOrders'] ?? []) as $o)
                  <tr class="hover:bg-white/5">
                    <td class="px-3 py-2 text-white/90">#{{ ltrim((string) ($o->order_number ?? '—'), '#') }}</td>
                    <td class="px-3 py-2 text-white/80">{{ $o->display_name ?? $o->order_label ?? 'Unknown' }}</td>
                    <td class="px-3 py-2">
                      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] border {{ $typeBadge($o->order_type ?? '') }}">
                        {{ $o->order_type ?? '—' }}
                      </span>
                    </td>
                  </tr>
                @empty
                  <tr>
                    <td class="px-3 py-4 text-white/50" colspan="3">No orders found.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        @elseif($id === 'upcoming_ship')
          <div class="space-y-2">
            @forelse(($data['nextShip'] ?? []) as $o)
              <div class="rounded-xl border border-emerald-200/10 bg-emerald-500/5 px-3 py-2">
                <div class="flex items-center justify-between">
                  <div class="min-w-0">
                    <div class="text-sm text-white/90 truncate">
                      #{{ ltrim((string) ($o->order_number ?? '—'), '#') }} — {{ $o->display_name ?? 'Unknown' }}
                    </div>
                    <div class="text-xs text-emerald-100/60 flex items-center gap-2">
                      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] border {{ $typeBadge($o->order_type ?? '') }}">
                        {{ $o->order_type ?? 'n/a' }}
                      </span>
                      <span class="text-white/40">•</span>
                      <span>{{ $o->status ?? 'n/a' }}</span>
                    </div>
                  </div>
                  <div class="flex items-center gap-2">
                    <button type="button" wire:click="toggleNextShip({{ (int) ($o->id ?? 0) }})" class="text-[10px] text-emerald-100/70 hover:text-emerald-100">
                      Details
                    </button>
                    <div class="text-xs text-white/70">
                      {{ optional($o->ship_by_at)->toDateString() ?? '—' }}
                    </div>
                  </div>
                </div>
                @if(!empty($o->id) && ($this->expandedNextShip[$o->id] ?? false))
                  <div class="mt-2 text-xs text-white/75 space-y-1">
                    @foreach(($o->lines_preview ?? []) as $line)
                      <div class="flex items-center justify-between">
                        <span class="truncate">{{ $line }}</span>
                      </div>
                    @endforeach
                    @if(($o->lines_more ?? 0) > 0)
                      <div class="text-[11px] text-white/50">+ {{ $o->lines_more }} more</div>
                    @endif
                  </div>
                @endif
              </div>
            @empty
              <div class="text-sm text-white/50">No ship dates found.</div>
            @endforelse
          </div>
        @else
          <div class="text-sm text-white/50">Unknown widget.</div>
        @endif
      </section>
    @endforeach
  </div>

  <script type="application/json" data-analytics-payload>
    @json([
      'typeCounts' => $data['typeCounts'] ?? [],
      'statusCounts' => $data['statusCounts'] ?? [],
    ])
  </script>
</div>
