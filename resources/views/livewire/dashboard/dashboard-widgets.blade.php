@php($data = $this->dashboardData)
@php($quote = $this->quoteOfDay)
@php($library = $this->widgetLibrary)
@php($visible = $this->visibleWidgets)
@php($typeBadge = function ($type) {
    $type = strtolower((string) $type);
    return match ($type) {
      'wholesale' => 'border-amber-300/35 bg-amber-400/20 text-amber-50',
      'event', 'market' => 'border-purple-300/35 bg-purple-400/20 text-purple-50',
      default => 'border-sky-300/35 bg-sky-400/20 text-sky-50',
    };
})

<div class="space-y-4 sm:space-y-6 min-w-0" data-dashboard-root wire:ignore.self>
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-4 sm:p-6 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)] min-w-0">
    <div class="flex min-w-0 flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
      <div class="min-w-0">
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Dashboard</div>
        <div class="mt-2 text-2xl sm:text-3xl font-['Fraunces'] font-semibold text-white">{{ $this->greeting }}</div>
      </div>
      <div class="flex min-w-0 flex-col items-start gap-3 xl:items-end">
        <div class="max-w-xl text-sm text-emerald-50/70 break-words">
          “{{ $quote['quote'] ?? '' }}”
          <span class="text-emerald-100/60">— {{ $quote['author'] ?? '' }}</span>
        </div>
        <div class="flex w-full flex-wrap items-center gap-2 xl:justify-end">
          <span class="rounded-full border border-emerald-200/20 bg-emerald-500/10 px-3 py-1.5 text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-100/80">Range</span>
          <select wire:model.live="range" class="min-w-[7.25rem] rounded-full border border-emerald-300/35 bg-emerald-500/20 px-4 py-2 text-sm font-medium text-white focus:border-emerald-200/70 focus:outline-none">
            <option value="1">Today</option>
            <option value="7">7 days</option>
            <option value="30">30 days</option>
          </select>
          <span class="ml-2 rounded-full border border-emerald-200/20 bg-emerald-500/10 px-3 py-1.5 text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-100/80">Channel</span>
          <select wire:model.live="channel" class="min-w-[8.5rem] rounded-full border border-emerald-300/35 bg-emerald-500/20 px-4 py-2 text-sm font-medium text-white focus:border-emerald-200/70 focus:outline-none">
            <option value="all">All</option>
            <option value="retail">Retail</option>
            <option value="wholesale">Wholesale</option>
          </select>
          <button type="button" wire:click="toggleLibrary"
            class="ml-0 sm:ml-2 inline-flex items-center rounded-full border px-4 py-2 text-sm font-semibold transition {{ $showLibrary ? 'border-emerald-300/60 bg-emerald-500/35 text-white' : 'border-emerald-200/25 bg-emerald-500/10 text-emerald-100/90 hover:bg-emerald-500/20' }}">
            Widgets Tray
          </button>
        </div>
      </div>
    </div>
  </section>

  @if($showLibrary)
    <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-4 sm:p-6 min-w-0">
      <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60">Widget Library</div>
      <div class="mt-2 text-sm text-emerald-50/70">Click to add. Drag to reorder on the canvas.</div>
      <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-3 min-w-0" data-widget-library>
        @foreach($library as $widget)
          @php($enabled = in_array($widget['id'], array_column($visible, 'id'), true))
          <button type="button" wire:click="addWidget('{{ $widget['id'] }}')"
            class="rounded-2xl border px-4 py-3 text-left transition {{ $enabled ? 'border-emerald-400/30 bg-emerald-500/15 text-white' : 'border-emerald-200/10 bg-emerald-500/5 text-white/70 hover:bg-emerald-500/10' }}"
            data-widget-id="{{ $widget['id'] }}"
            @if($enabled) disabled @endif>
            <div class="text-sm font-semibold">{{ $widget['title'] }}</div>
            <div class="mt-1 text-xs text-emerald-100/60">{{ $widget['description'] }}</div>
            <div class="mt-2 text-[11px] uppercase tracking-[0.2em] text-emerald-100/50">{{ $enabled ? 'Added' : 'Add Widget' }}</div>
          </button>
        @endforeach
      </div>
    </section>
  @endif

  <div class="grid grid-cols-1 gap-4 sm:gap-6 md:grid-cols-2 xl:grid-cols-12 min-w-0" data-widget-grid>
    @foreach($visible as $w)
      @php($size = $w['size'] ?? '2')
      @php($span = $size === '1' ? 'md:col-span-1 xl:col-span-4' : ($size === '2' ? 'md:col-span-2 xl:col-span-8' : 'md:col-span-2 xl:col-span-12'))
      @php($id = $w['id'] ?? '')
      @php($title = $w['title'] ?? ($id ?: 'Widget'))

      <section class="{{ $span }} min-h-[230px] min-w-0 cursor-move rounded-2xl border border-emerald-200/10 bg-[#0f1412]/80 p-4 sm:p-5 shadow-[0_20px_60px_-40px_rgba(0,0,0,0.9)]"
        data-widget
        data-widget-id="{{ $id }}">
        <div class="mb-3 flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div class="flex min-w-0 items-center gap-3">
            <h3 class="truncate text-sm font-semibold text-white/90">{{ $title }}</h3>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center gap-1 rounded-full border border-emerald-200/10 bg-emerald-500/5 px-1 py-0.5">
              @foreach(['1' => '1', '2' => '2', '3' => '3'] as $val => $label)
                <button type="button" wire:click="setWidgetSize('{{ $id }}','{{ $val }}')"
                  class="mf-no-drag h-6 w-6 rounded-full text-[11px] font-semibold transition {{ $size === $val ? 'bg-emerald-400/60 text-white' : 'text-emerald-100/70 hover:bg-emerald-400/20' }}">
                  {{ $label }}
                </button>
              @endforeach
            </div>
            <button type="button" wire:click="removeWidget('{{ $id }}')" class="mf-no-drag text-[10px] uppercase tracking-[0.2em] text-emerald-100/50 hover:text-emerald-100/80">remove</button>
          </div>
        </div>

        @if($id === 'today_glance')
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-2xl border border-emerald-300/15 bg-emerald-500/10 p-4">
              <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/50">Due Today</div>
              <div class="mt-2 text-2xl font-semibold text-white">{{ $data['todayAtGlance']['dueToday'] ?? 0 }}</div>
            </div>
            <div class="rounded-2xl border border-emerald-300/15 bg-emerald-500/10 p-4">
              <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/50">Next 3 Days</div>
              <div class="mt-2 text-2xl font-semibold text-white">{{ $data['todayAtGlance']['dueNext3Days'] ?? 0 }}</div>
            </div>
            <div class="rounded-2xl border border-emerald-300/15 bg-emerald-500/10 p-4">
              <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/50">Open Orders</div>
              <div class="mt-2 text-2xl font-semibold text-white">{{ $data['todayAtGlance']['openOrders'] ?? 0 }}</div>
            </div>
            <div class="rounded-2xl border border-amber-300/15 bg-amber-400/10 p-4">
              <div class="text-xs uppercase tracking-[0.3em] text-amber-100/60">Unpublished</div>
              <div class="mt-2 text-2xl font-semibold text-white">{{ $data['todayAtGlance']['unpublishedOrders'] ?? 0 }}</div>
            </div>
            <div class="rounded-2xl border border-amber-300/15 bg-amber-400/10 p-4">
              <div class="text-xs uppercase tracking-[0.3em] text-amber-100/60">Exceptions</div>
              <div class="mt-2 text-2xl font-semibold text-white">{{ $data['todayAtGlance']['exceptions'] ?? 0 }}</div>
            </div>
          </div>

        @elseif($id === 'due_window')
          <div class="mb-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="rounded-xl border border-emerald-200/10 bg-emerald-500/5 p-3 text-sm text-white/90">Due today: <span class="font-semibold">{{ $data['dueWindow']['dueToday'] ?? 0 }}</span></div>
            <div class="rounded-xl border border-emerald-200/10 bg-emerald-500/5 p-3 text-sm text-white/90">Next 3 business days: <span class="font-semibold">{{ $data['dueWindow']['dueNext3Days'] ?? 0 }}</span></div>
          </div>
          <div class="space-y-2">
            @forelse(($data['dueWindow']['upcoming'] ?? []) as $o)
              <div class="rounded-xl border border-emerald-200/10 bg-emerald-500/5 px-3 py-2">
                <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                  <div class="min-w-0">
                    <div class="truncate text-sm text-white/90">#{{ ltrim((string) ($o['number'] ?? '—'), '#') }} — {{ $o['customer'] ?? 'Unknown' }}</div>
                    <div class="flex items-center gap-2 text-xs text-emerald-100/60">
                      <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] {{ $typeBadge($o['channel'] ?? '') }}">{{ $o['channel'] ?? 'n/a' }}</span>
                      <span class="text-white/40">•</span>
                      <span>{{ $o['status'] ?? 'n/a' }}</span>
                    </div>
                  </div>
                  <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                    <button type="button" wire:click="toggleDueWindow({{ (int) ($o['id'] ?? 0) }})" class="inline-flex items-center rounded-full border px-3 py-1 text-[10px] font-semibold {{ $typeBadge($o['channel'] ?? '') }}">Details</button>
                    <div class="text-xs text-white/70">{{ $o['due'] ?? '—' }}</div>
                  </div>
                </div>
                @if(!empty($o['id']) && ($this->expandedDueWindow[$o['id']] ?? false))
                  <div class="mt-2 space-y-1 text-xs text-white/75">
                    @foreach(($o['lines_preview'] ?? []) as $line)
                      <div class="truncate">{{ $line }}</div>
                    @endforeach
                    @if(($o['lines_more'] ?? 0) > 0)
                      <div class="text-[11px] text-white/50">+ {{ $o['lines_more'] }} more</div>
                    @endif
                  </div>
                @endif
              </div>
            @empty
              <div class="text-sm text-white/50">No due orders in this window.</div>
            @endforelse
          </div>

        @elseif($id === 'unpublished_queue')
          <div class="mf-table-wrap">
            <table class="min-w-full text-sm">
              <thead class="bg-white/5 text-white/70">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">Channel</th>
                  <th class="px-3 py-2 text-left font-medium">Status</th>
                  <th class="px-3 py-2 text-right font-medium">Count</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/10">
                @forelse(($data['unpublished']['rows'] ?? []) as $row)
                  <tr class="hover:bg-white/5">
                    <td class="px-3 py-2 text-white/85">{{ $row['channel'] }}</td>
                    <td class="px-3 py-2 text-white/70">{{ $row['status'] }}</td>
                    <td class="px-3 py-2 text-right text-white/90">{{ $row['count'] }}</td>
                  </tr>
                @empty
                  <tr><td colspan="3" class="px-3 py-4 text-white/50">No unpublished orders.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>

        @elseif($id === 'shipping_queue')
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <div class="rounded-2xl border border-emerald-300/15 bg-emerald-500/10 p-4">
              <div class="text-xs uppercase tracking-[0.25em] text-emerald-100/50">Ready</div>
              <div class="mt-2 text-2xl font-semibold text-white">{{ $data['shippingQueue']['ready'] ?? 0 }}</div>
            </div>
            <div class="rounded-2xl border border-amber-300/15 bg-amber-400/10 p-4">
              <div class="text-xs uppercase tracking-[0.25em] text-amber-100/60">Blocked</div>
              <div class="mt-2 text-2xl font-semibold text-white">{{ $data['shippingQueue']['blocked'] ?? 0 }}</div>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
              <div class="text-xs uppercase tracking-[0.25em] text-white/50">Avg Age</div>
              <div class="mt-2 text-2xl font-semibold text-white">{{ $data['shippingQueue']['avgAgeDays'] ?? 0 }}d</div>
            </div>
          </div>

        @elseif($id === 'import_health')
          <div class="space-y-3 text-sm text-white/80">
            <div class="rounded-xl border border-emerald-200/10 bg-emerald-500/5 p-3">Last import run: <span class="font-semibold text-white">{{ $data['importHealth']['lastRunAt'] ?? 'Never' }}</span></div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div class="rounded-xl border border-emerald-200/10 bg-emerald-500/5 p-3">Imported 24h: <span class="font-semibold text-white">{{ $data['importHealth']['ordersImportedLast24h'] ?? 0 }}</span></div>
              <div class="rounded-xl border border-amber-300/15 bg-amber-400/10 p-3">Import exceptions 24h: <span class="font-semibold text-white">{{ $data['importHealth']['importExceptionsLast24h'] ?? 0 }}</span></div>
              <div class="rounded-xl border border-amber-300/15 bg-amber-400/10 p-3">Mapping exceptions open: <span class="font-semibold text-white">{{ $data['importHealth']['mappingExceptionsOpen'] ?? 0 }}</span></div>
            </div>
          </div>

        @elseif($id === 'production_load')
          <div class="mb-3 rounded-xl border border-emerald-200/10 bg-emerald-500/5 p-3 text-sm text-white/90">
            Open line items total: <span class="font-semibold">{{ $data['productionLoad']['openLineItemsTotal'] ?? 0 }}</span>
          </div>
          <div class="space-y-2">
            @foreach(($data['productionLoad']['byType'] ?? []) as $type => $count)
              <div>
                <div class="mb-1 flex items-center justify-between text-xs text-white/70">
                  <span class="capitalize">{{ $type }}</span>
                  <span>{{ $count }}</span>
                </div>
                <div class="h-2 rounded-full bg-white/10">
                  @php($max = max(1, max(($data['productionLoad']['byType'] ?? [0]))))
                  <div class="h-2 rounded-full bg-emerald-400/70" style="width: {{ round(($count / $max) * 100, 1) }}%"></div>
                </div>
              </div>
            @endforeach
          </div>

        @elseif($id === 'top_scents')
          <div class="mb-2 text-xs text-emerald-100/60">Range: {{ $data['topScents']['rangeStart'] ?? '—' }} to {{ $data['topScents']['rangeEnd'] ?? '—' }}</div>
          <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            @php($channels = ['retail', 'wholesale'])
            @foreach($channels as $ch)
              <div class="rounded-xl border border-emerald-200/10 bg-emerald-500/5 p-3">
                <div class="text-xs uppercase tracking-[0.2em] text-emerald-100/60">{{ $ch }}</div>
                <div class="mt-2 space-y-2 text-sm">
                  @forelse(($data['topScents']['byChannel'][$ch] ?? []) as $item)
                    <div class="flex items-center justify-between gap-2 text-white/80">
                      <span class="truncate">{{ $item['scent'] }}</span>
                      <span class="text-white/95">{{ $item['qty'] }}</span>
                    </div>
                  @empty
                    <div class="text-white/50">No data.</div>
                  @endforelse
                </div>
              </div>
            @endforeach
          </div>

        @elseif($id === 'revenue_snapshot')
          <div class="mb-2 text-xs text-emerald-100/60">{{ $data['revenue']['note'] ?? 'Needs data.' }}</div>
          <div class="mf-table-wrap">
            <table class="min-w-full text-sm">
              <thead class="bg-white/5 text-white/70">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">Channel</th>
                  <th class="px-3 py-2 text-right font-medium">Gross 7d</th>
                  <th class="px-3 py-2 text-right font-medium">Gross 30d</th>
                  <th class="px-3 py-2 text-right font-medium">Missing Price Qty</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/10">
                @forelse(($data['revenue']['byChannel'] ?? []) as $ch => $row)
                  <tr class="hover:bg-white/5">
                    <td class="px-3 py-2 text-white/85">{{ $ch }}</td>
                    <td class="px-3 py-2 text-right text-white/85">${{ number_format((float) ($row['gross_7'] ?? 0), 2) }}</td>
                    <td class="px-3 py-2 text-right text-white/85">${{ number_format((float) ($row['gross_30'] ?? 0), 2) }}</td>
                    <td class="px-3 py-2 text-right text-white/70">{{ $row['qty_missing_price'] ?? 0 }}</td>
                  </tr>
                @empty
                  <tr><td colspan="4" class="px-3 py-4 text-white/50">Needs data.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>

        @elseif($id === 'status_pie')
          <div class="h-[260px]" wire:ignore>
            <canvas data-chart="status" class="!h-full !w-full"></canvas>
          </div>

        @elseif($id === 'channel_pie')
          <div class="h-[260px]" wire:ignore>
            <canvas data-chart="channel" class="!h-full !w-full"></canvas>
          </div>

        @elseif($id === 'recent_orders')
          <div class="overflow-x-auto rounded-xl border border-emerald-200/10">
            <table class="min-w-full text-sm">
              <thead class="bg-white/5 text-white/70">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">Order</th>
                  <th class="px-3 py-2 text-left font-medium">Customer</th>
                  <th class="px-3 py-2 text-left font-medium">Type</th>
                  <th class="px-3 py-2 text-left font-medium">Status</th>
                  <th class="px-3 py-2 text-left font-medium">Ship By</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/10">
                @forelse(($data['recentOrders'] ?? []) as $o)
                  <tr class="hover:bg-white/5">
                    <td class="px-3 py-2 text-white/90">#{{ ltrim((string) ($o['number'] ?? '—'), '#') }}</td>
                    <td class="px-3 py-2 text-white/80">{{ $o['customer'] ?? 'Unknown' }}</td>
                    <td class="px-3 py-2">
                      <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] {{ $typeBadge($o['channel'] ?? '') }}">{{ $o['channel'] ?? '—' }}</span>
                    </td>
                    <td class="px-3 py-2 text-white/70">{{ $o['status'] ?? '—' }}</td>
                    <td class="px-3 py-2 text-white/70">{{ $o['due'] ?? '—' }}</td>
                  </tr>
                @empty
                  <tr><td class="px-3 py-4 text-white/50" colspan="5">No orders found.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>

        @elseif($id === 'exceptions')
          <div class="space-y-3 text-sm text-white/70 min-w-0">
            <div class="rounded-xl border border-amber-200/20 bg-amber-500/10 p-3">
              Unresolved exceptions: <span class="font-semibold text-white/90">{{ $data['todayAtGlance']['exceptions'] ?? 0 }}</span>
            </div>
            <div class="text-xs text-emerald-100/50">Route unrecognized line items to Mapping Exceptions.</div>
          </div>

        @elseif($id === 'capacity_staffing')
          <div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-white/70">
            {{ $data['placeholders']['capacityStaffing']['message'] ?? 'Needs data.' }}
          </div>

        @elseif($id === 'cash_runway')
          <div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-white/70">
            {{ $data['placeholders']['cashRunway']['message'] ?? 'Needs data.' }}
          </div>

        @elseif($id === 'inventory_alerts')
          <div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-white/70">
            {{ $data['placeholders']['inventoryAlerts']['message'] ?? 'Needs data.' }}
          </div>

        @elseif($id === 'notes_reminders')
          <div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-white/70">
            {{ $data['placeholders']['notesReminders']['message'] ?? 'Needs data.' }}
          </div>

        @else
          <div class="text-sm text-white/50">Unknown widget: <span class="text-white/70">{{ $id ?: 'n/a' }}</span></div>
        @endif
      </section>
    @endforeach
  </div>

  <script type="application/json" data-dashboard-payload>
    @json([
      'statusCounts' => $data['statusCounts'] ?? [],
      'channelCounts' => $data['channelCounts'] ?? [],
    ])
  </script>
</div>
