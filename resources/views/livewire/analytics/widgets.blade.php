@php($data = $this->analyticsData)
@php($widgets = $this->visibleWidgets)
@php($library = $this->widgetLibrary)
@php($stateBadge = function ($state) {
    $state = strtolower((string) $state);
    return match ($state) {
        'forecast' => 'border-sky-300/35 bg-sky-400/20 text-sky-50',
        'current' => 'border-amber-300/35 bg-amber-400/20 text-amber-50',
        'actual' => 'border-emerald-300/35 bg-emerald-400/20 text-emerald-50',
        default => 'border-white/20 bg-white/10 text-white/80',
    };
})
@php($riskBadge = function ($status) {
    $status = strtolower((string) $status);
    return match ($status) {
        'reorder' => 'border-rose-300/35 bg-rose-400/20 text-rose-50',
        'low' => 'border-amber-300/35 bg-amber-400/20 text-amber-50',
        default => 'border-emerald-300/35 bg-emerald-400/20 text-emerald-50',
    };
})
@php($fmt = fn ($n, $d = 0) => number_format((float) $n, $d))

<div class="space-y-6" data-widget-root wire:ignore.self>
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)]">
    <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
      <div>
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Reporting Widgets</div>
        <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Operational Analytics Overview</div>
        <div class="mt-2 text-sm text-emerald-50/70">
          Forecast, current, and actual are intentionally separated. Widgets consume service-layer reporting contracts.
        </div>
      </div>
      <div class="flex flex-wrap items-end gap-3">
        <label class="flex flex-col gap-1 text-xs text-emerald-100/70">
          <span class="uppercase tracking-[0.22em]">Window</span>
          <select wire:model.live="windowWeeks" class="rounded-xl border border-emerald-200/20 bg-emerald-500/10 px-3 py-2 text-sm text-white">
            <option value="2">Next 2 weeks</option>
            <option value="4">Next 4 weeks</option>
            <option value="8">Next 8 weeks</option>
          </select>
        </label>

        <label class="flex flex-col gap-1 text-xs text-emerald-100/70">
          <span class="uppercase tracking-[0.22em]">Channel</span>
          <select wire:model.live="channel" class="rounded-xl border border-emerald-200/20 bg-emerald-500/10 px-3 py-2 text-sm text-white">
            <option value="all">All</option>
            <option value="retail">Retail</option>
            <option value="wholesale">Wholesale</option>
            <option value="event">Event/Markets</option>
          </select>
        </label>

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

  <div class="grid grid-cols-1 gap-6 md:grid-cols-12" data-widget-grid>
    @foreach($widgets as $w)
      @php($size = $w['size'] ?? '2')
      @php($span = $size === '1' ? 'md:col-span-4' : ($size === '2' ? 'md:col-span-8' : 'md:col-span-12'))
      @php($id = $w['id'] ?? '')
      @php($title = $w['title'] ?? ($id ?: 'Widget'))

      <section class="min-h-[220px] cursor-move rounded-2xl border border-emerald-200/10 bg-[#0f1412]/80 p-5 shadow-[0_20px_60px_-40px_rgba(0,0,0,0.9)] backdrop-blur {{ $span }}"
        data-widget data-widget-id="{{ $id }}">
        <div class="mb-3 flex items-center justify-between gap-3">
          <h3 class="text-sm font-semibold text-white/90">{{ $title }}</h3>
          <div class="flex items-center gap-2">
            <div class="flex items-center gap-1 rounded-full border border-emerald-200/10 bg-emerald-500/5 px-1 py-0.5">
              @foreach(['1' => '1', '2' => '2', '3' => '3'] as $val => $label)
                <button type="button" wire:click="setWidgetSize('{{ $id }}','{{ $val }}')"
                  class="mf-no-drag h-6 w-6 rounded-full text-[11px] font-semibold transition {{ $size === $val ? 'bg-emerald-400/60 text-white' : 'text-emerald-100/70 hover:bg-emerald-400/20' }}">
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

        @if($id === 'unmapped_exceptions')
          @php($exceptions = $data['exceptions'] ?? [])
          <div class="space-y-3 text-sm text-white/75">
            <div class="rounded-xl border border-amber-200/20 bg-amber-500/10 p-3">
              <div class="text-xs uppercase tracking-[0.24em] text-amber-100/80">Open Unmapped</div>
              <div class="mt-1 text-2xl font-semibold text-white">{{ (int) ($exceptions['open_count'] ?? 0) }}</div>
            </div>

            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
              @forelse(($exceptions['by_channel'] ?? []) as $row)
                <div class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs">
                  <div class="uppercase tracking-[0.18em] text-white/55">{{ strtoupper((string) ($row['channel'] ?? 'unknown')) }}</div>
                  <div class="mt-1 text-base font-semibold text-white">{{ (int) ($row['open_count'] ?? 0) }}</div>
                </div>
              @empty
                <div class="text-white/55">No open mapping exceptions.</div>
              @endforelse
            </div>

            @if(!empty($exceptions['top_raw_names']))
              <div class="rounded-xl border border-emerald-200/15 bg-emerald-500/5 p-3">
                <div class="text-xs uppercase tracking-[0.24em] text-emerald-100/70">Top Unmapped Names</div>
                <div class="mt-2 space-y-1 text-xs text-white/80">
                  @foreach(($exceptions['top_raw_names'] ?? []) as $row)
                    <div class="flex items-center justify-between gap-3">
                      <span class="truncate">{{ $row['raw_name'] ?? 'Unknown' }}</span>
                      <span class="font-semibold text-white">{{ (int) ($row['open_count'] ?? 0) }}</span>
                    </div>
                  @endforeach
                </div>
              </div>
            @endif

            <a href="{{ $data['urls']['mapping_exceptions'] ?? '#' }}" wire:navigate
              class="inline-flex items-center rounded-full border border-amber-300/25 bg-amber-500/10 px-3 py-1.5 text-xs font-semibold text-amber-100 hover:bg-amber-500/20">
              Open Mapping Resolution
            </a>
          </div>

        @elseif(in_array($id, ['top_scents_forecast', 'top_scents_current', 'top_scents_actual'], true))
          @php($state = str_replace('top_scents_', '', $id))
          @php($slice = $data[$state] ?? ['top_scents' => [], 'snapshot' => ['totals' => []]])
          <div class="mb-3 flex items-center justify-between gap-3">
            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] {{ $stateBadge($state) }}">{{ $state }}</span>
            <div class="text-xs text-emerald-100/70">
              Units {{ $fmt(data_get($slice, 'snapshot.totals.units', 0)) }} · Wax {{ $fmt(data_get($slice, 'snapshot.totals.wax_grams', 0), 1) }}g
            </div>
          </div>
          <div class="overflow-x-auto rounded-xl border border-emerald-200/10">
            <table class="min-w-full text-sm">
              <thead class="bg-white/5 text-white/70">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">Scent</th>
                  <th class="px-3 py-2 text-right font-medium">Units</th>
                  <th class="px-3 py-2 text-right font-medium">Wax g</th>
                  <th class="px-3 py-2 text-right font-medium">Oil g</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/10">
                @forelse(($slice['top_scents'] ?? []) as $row)
                  <tr class="hover:bg-white/5">
                    <td class="px-3 py-2 text-white/90">{{ $row['scent_name'] ?? 'Unknown' }}</td>
                    <td class="px-3 py-2 text-right text-white/80">{{ $fmt($row['units'] ?? 0) }}</td>
                    <td class="px-3 py-2 text-right text-white/80">{{ $fmt($row['wax_grams'] ?? 0, 1) }}</td>
                    <td class="px-3 py-2 text-right text-white/80">{{ $fmt($row['oil_grams'] ?? 0, 1) }}</td>
                  </tr>
                @empty
                  <tr>
                    <td class="px-3 py-4 text-white/55" colspan="4">No data for this state/window.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>

        @elseif($id === 'top_oils_forecast')
          <div class="mb-3 flex items-center justify-between gap-3">
            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] {{ $stateBadge('forecast') }}">Forecast</span>
            <div class="text-xs text-emerald-100/70">
              Total {{ $fmt(data_get($data, 'top_oils_forecast_totals.oil_grams', 0), 1) }}g
            </div>
          </div>
          <div class="overflow-x-auto rounded-xl border border-emerald-200/10">
            <table class="min-w-full text-sm">
              <thead class="bg-white/5 text-white/70">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">Oil</th>
                  <th class="px-3 py-2 text-right font-medium">Grams</th>
                  <th class="px-3 py-2 text-right font-medium">% of Total</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/10">
                @forelse(($data['top_oils_forecast'] ?? []) as $row)
                  <tr class="hover:bg-white/5">
                    <td class="px-3 py-2 text-white/90">{{ $row['base_oil_name'] ?? 'Unknown' }}</td>
                    <td class="px-3 py-2 text-right text-white/80">{{ $fmt($row['grams'] ?? 0, 1) }}</td>
                    <td class="px-3 py-2 text-right text-white/80">{{ $fmt($row['percent_of_total'] ?? 0, 2) }}%</td>
                  </tr>
                @empty
                  <tr>
                    <td class="px-3 py-4 text-white/55" colspan="3">No forecast oil demand rows.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
          @if(!empty($data['top_oils_forecast_unresolved']))
            <div class="mt-2 text-xs text-amber-100/80">
              {{ count($data['top_oils_forecast_unresolved']) }} unresolved recipe mapping path(s) in forecast flattening.
            </div>
          @endif

        @elseif($id === 'oil_reorder_risk')
          @php($riskRows = data_get($data, 'reorder_risk.oil.rows', []))
          @php($summary = data_get($data, 'reorder_risk.oil.summary', []))
          <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
            <span class="rounded-full border border-rose-300/35 bg-rose-400/20 px-2 py-0.5 text-rose-50">Reorder: {{ (int) ($summary['reorder_count'] ?? 0) }}</span>
            <span class="rounded-full border border-amber-300/35 bg-amber-400/20 px-2 py-0.5 text-amber-50">Low: {{ (int) ($summary['low_count'] ?? 0) }}</span>
            <span class="rounded-full border border-emerald-300/35 bg-emerald-400/20 px-2 py-0.5 text-emerald-50">OK: {{ (int) ($summary['ok_count'] ?? 0) }}</span>
          </div>
          <div class="overflow-x-auto rounded-xl border border-emerald-200/10">
            <table class="min-w-full text-sm">
              <thead class="bg-white/5 text-white/70">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">Oil</th>
                  <th class="px-3 py-2 text-right font-medium">On Hand</th>
                  <th class="px-3 py-2 text-right font-medium">Projected</th>
                  <th class="px-3 py-2 text-right font-medium">Risk</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/10">
                @forelse($riskRows as $row)
                  @php($status = data_get($row, 'state_after_demand.status', 'ok'))
                  <tr class="hover:bg-white/5">
                    <td class="px-3 py-2 text-white/90">{{ $row['name'] ?? 'Unknown' }}</td>
                    <td class="px-3 py-2 text-right text-white/80">{{ $fmt($row['on_hand_grams'] ?? 0, 1) }}g</td>
                    <td class="px-3 py-2 text-right text-white/80">{{ $fmt($row['projected_on_hand_grams'] ?? 0, 1) }}g</td>
                    <td class="px-3 py-2 text-right">
                      <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $riskBadge($status) }}">{{ $status }}</span>
                    </td>
                  </tr>
                @empty
                  <tr>
                    <td class="px-3 py-4 text-white/55" colspan="4">No oil reorder-risk rows.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>

        @elseif($id === 'wax_reorder_risk')
          @php($riskRows = data_get($data, 'reorder_risk.wax.rows', []))
          <div class="space-y-2">
            @forelse($riskRows as $row)
              @php($status = data_get($row, 'state_after_demand.status', 'ok'))
              <div class="rounded-xl border border-emerald-200/15 bg-emerald-500/5 p-3">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <div class="text-sm font-semibold text-white/90">{{ $row['name'] ?? 'Wax' }}</div>
                    <div class="mt-1 text-xs text-white/70">
                      On hand {{ $fmt($row['on_hand_grams'] ?? 0, 1) }}g · Projected {{ $fmt($row['projected_on_hand_grams'] ?? 0, 1) }}g
                    </div>
                  </div>
                  <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $riskBadge($status) }}">{{ $status }}</span>
                </div>
              </div>
            @empty
              <div class="text-sm text-white/55">No wax reorder-risk rows.</div>
            @endforelse
          </div>

        @elseif($id === 'inventory_snapshot')
          @php($snap = $data['inventory_snapshot'] ?? [])
          <div class="grid grid-cols-2 gap-2 text-xs sm:grid-cols-3">
            <div class="rounded-xl border border-white/10 bg-white/5 p-3">
              <div class="uppercase tracking-[0.16em] text-white/55">Oils Tracked</div>
              <div class="mt-1 text-lg font-semibold text-white">{{ (int) ($snap['oil_total_items'] ?? 0) }}</div>
            </div>
            <div class="rounded-xl border border-amber-300/25 bg-amber-500/10 p-3">
              <div class="uppercase tracking-[0.16em] text-amber-100/70">Oil Low</div>
              <div class="mt-1 text-lg font-semibold text-amber-50">{{ (int) ($snap['oil_low_count'] ?? 0) }}</div>
            </div>
            <div class="rounded-xl border border-rose-300/25 bg-rose-500/10 p-3">
              <div class="uppercase tracking-[0.16em] text-rose-100/70">Oil Reorder</div>
              <div class="mt-1 text-lg font-semibold text-rose-50">{{ (int) ($snap['oil_reorder_count'] ?? 0) }}</div>
            </div>
            <div class="rounded-xl border border-white/10 bg-white/5 p-3">
              <div class="uppercase tracking-[0.16em] text-white/55">Wax On Hand</div>
              <div class="mt-1 text-lg font-semibold text-white">{{ $fmt($snap['wax_on_hand_grams'] ?? 0, 1) }}g</div>
              <div class="text-[11px] text-white/55">{{ $fmt($snap['wax_on_hand_boxes'] ?? 0, 2) }} × 45lb</div>
            </div>
            <div class="rounded-xl border border-amber-300/25 bg-amber-500/10 p-3">
              <div class="uppercase tracking-[0.16em] text-amber-100/70">Wax Low</div>
              <div class="mt-1 text-lg font-semibold text-amber-50">{{ (int) ($snap['wax_low_count'] ?? 0) }}</div>
            </div>
            <div class="rounded-xl border border-rose-300/25 bg-rose-500/10 p-3">
              <div class="uppercase tracking-[0.16em] text-rose-100/70">Wax Reorder</div>
              <div class="mt-1 text-lg font-semibold text-rose-50">{{ (int) ($snap['wax_reorder_count'] ?? 0) }}</div>
            </div>
          </div>
          <a href="{{ $data['urls']['inventory'] ?? '#' }}" wire:navigate
             class="mt-3 inline-flex items-center rounded-full border border-emerald-300/25 bg-emerald-500/10 px-3 py-1.5 text-xs font-semibold text-emerald-100 hover:bg-emerald-500/20">
            Open Inventory Maintenance
          </a>

        @elseif($id === 'demand_state_overview')
          <div class="overflow-x-auto rounded-xl border border-emerald-200/10">
            <table class="min-w-full text-sm">
              <thead class="bg-white/5 text-white/70">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">State</th>
                  <th class="px-3 py-2 text-right font-medium">Units</th>
                  <th class="px-3 py-2 text-right font-medium">Wax g</th>
                  <th class="px-3 py-2 text-right font-medium">Oil g</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/10">
                @foreach(($data['state_totals'] ?? []) as $row)
                  <tr class="hover:bg-white/5">
                    <td class="px-3 py-2">
                      <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] {{ $stateBadge($row['state'] ?? '') }}">
                        {{ $row['state'] ?? 'unknown' }}
                      </span>
                    </td>
                    <td class="px-3 py-2 text-right text-white/85">{{ $fmt($row['units'] ?? 0) }}</td>
                    <td class="px-3 py-2 text-right text-white/85">{{ $fmt($row['wax_grams'] ?? 0, 1) }}</td>
                    <td class="px-3 py-2 text-right text-white/85">{{ $fmt($row['oil_grams'] ?? 0, 1) }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>

        @else
          <div class="text-sm text-white/50">Unknown widget.</div>
        @endif
      </section>
    @endforeach
  </div>
</div>
