@php($data = $this->analyticsData)
@php($widgets = $this->visibleWidgets)
@php($library = $this->widgetLibrary)
@php($drilldown = $this->drilldownData)
@php($drilldownEnabled = ['unmapped_exceptions','oil_reorder_risk','wax_reorder_risk','top_scents_forecast','top_scents_current','top_scents_actual','top_oils_forecast','demand_state_overview'])
@php($stateBadge = function ($state) {
    $state = strtolower((string) $state);
    return match ($state) {
        'forecast' => 'border-sky-300/35 bg-sky-100 text-sky-900',
        'current' => 'border-amber-300/35 bg-amber-100 text-amber-900',
        'actual' => 'border-zinc-300 bg-emerald-100 text-emerald-900',
        default => 'border-zinc-300 bg-zinc-100 text-zinc-700',
    };
})
@php($riskBadge = function ($status) {
    $status = strtolower((string) $status);
    return match ($status) {
        'reorder' => 'border-rose-300/35 bg-rose-100 text-rose-900',
        'low' => 'border-amber-300/35 bg-amber-100 text-amber-900',
        default => 'border-zinc-300 bg-emerald-100 text-emerald-900',
    };
})
@php($fmt = fn ($n, $d = 0) => number_format((float) $n, $d))
@php($deltaLabel = function ($metric) use ($fmt) {
    if (!is_array($metric)) return '—';
    $delta = (float) ($metric['delta'] ?? 0);
    $pct = $metric['delta_pct'] ?? null;
    $arrow = $delta > 0 ? '↑' : ($delta < 0 ? '↓' : '→');
    $prefix = $delta > 0 ? '+' : '';
    if ($pct === null) {
        return $arrow.' '.$prefix.$fmt($delta, 1);
    }
    return $arrow.' '.$prefix.$fmt($delta, 1).' ('.$prefix.$fmt($pct, 1).'%)';
})

<div class="space-y-6" data-widget-root wire:ignore.self>
  <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
    <div class="flex flex-col gap-4">
      <div>
        <div class="text-[11px] uppercase tracking-[0.35em] text-zinc-600">Reporting Widgets</div>
        <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">Operational Analytics Overview</div>
        <div class="mt-2 text-sm text-zinc-600">Forecast, current, and actual are separated and can be compared against explicit prior windows.</div>
      </div>

      <div class="grid grid-cols-1 gap-3 lg:grid-cols-6">
        <label class="flex flex-col gap-1 text-xs text-zinc-600">
          <span class="uppercase tracking-[0.22em]">Mode</span>
          <select wire:model.defer="timeMode" class="rounded-xl border border-zinc-200 bg-emerald-100 px-3 py-2 text-sm text-zinc-950">
            @foreach($this->timeModeOptions as $option)
              <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
            @endforeach
          </select>
        </label>

        <label class="flex flex-col gap-1 text-xs text-zinc-600 lg:col-span-2">
          <span class="uppercase tracking-[0.22em]">Preset</span>
          <select wire:model.defer="preset" class="rounded-xl border border-zinc-200 bg-emerald-100 px-3 py-2 text-sm text-zinc-950">
            @foreach($this->presetOptions as $option)
              <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
            @endforeach
          </select>
        </label>

        <label class="flex flex-col gap-1 text-xs text-zinc-600">
          <span class="uppercase tracking-[0.22em]">Compare</span>
          <select wire:model.defer="comparisonMode" class="rounded-xl border border-zinc-200 bg-emerald-100 px-3 py-2 text-sm text-zinc-950">
            @foreach($this->comparisonOptions as $option)
              <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
            @endforeach
          </select>
        </label>

        <label class="flex flex-col gap-1 text-xs text-zinc-600">
          <span class="uppercase tracking-[0.22em]">Channel</span>
          <select wire:model.defer="channel" class="rounded-xl border border-zinc-200 bg-emerald-100 px-3 py-2 text-sm text-zinc-950">
            @foreach($this->channelOptions as $option)
              <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
            @endforeach
          </select>
        </label>

        <div class="flex items-end justify-start gap-2 lg:justify-end">
          <button type="button" wire:click="applyFilters"
            class="inline-flex items-center rounded-full border border-emerald-300/45 bg-emerald-500/30 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-emerald-500/40">
            Apply
          </button>
          <button type="button" wire:click="toggleLibrary"
            class="inline-flex items-center rounded-full border px-4 py-2 text-sm font-semibold transition {{ $showLibrary ? 'border-emerald-300/60 bg-emerald-500/35 text-zinc-950' : 'border-zinc-200 bg-emerald-100 text-zinc-600 hover:bg-emerald-100' }}">
            Widgets Tray
          </button>
        </div>
      </div>

      @if($preset === 'custom')
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <label class="flex flex-col gap-1 text-xs text-zinc-600">
            <span class="uppercase tracking-[0.22em]">Start</span>
            <input type="date" wire:model.defer="customStartDate" class="rounded-xl border border-zinc-200 bg-emerald-100 px-3 py-2 text-sm text-zinc-950" />
          </label>
          <label class="flex flex-col gap-1 text-xs text-zinc-600">
            <span class="uppercase tracking-[0.22em]">End</span>
            <input type="date" wire:model.defer="customEndDate" class="rounded-xl border border-zinc-200 bg-emerald-100 px-3 py-2 text-sm text-zinc-950" />
          </label>
        </div>
      @endif

      <div class="rounded-2xl border border-zinc-200 bg-emerald-50 px-4 py-3 text-xs text-zinc-600">
        Primary: {{ data_get($data, 'timeframe.labels.primary') }}
        @if(data_get($data, 'timeframe.labels.comparison'))
          <span class="mx-2 text-zinc-500">|</span>
          Compare: {{ data_get($data, 'timeframe.labels.comparison') }}
        @endif
      </div>
    </div>
  </section>

  @if($showLibrary)
    <section class="rounded-3xl border border-zinc-200 bg-white p-6">
      <div class="text-xs uppercase tracking-[0.3em] text-zinc-600">Widget Library</div>
      <div class="mt-2 text-sm text-zinc-600">Click to add. Drag to reorder on the canvas.</div>
      <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3" data-widget-library>
        @foreach($library as $widget)
          @php($enabled = in_array($widget['id'], array_column($widgets, 'id'), true))
          <button type="button" wire:click="addWidget('{{ $widget['id'] }}')"
            class="rounded-2xl border px-4 py-3 text-left transition {{ $enabled ? 'border-emerald-400/30 bg-emerald-100 text-zinc-950' : 'border-zinc-200 bg-emerald-50 text-zinc-600 hover:bg-emerald-100' }}"
            data-widget-id="{{ $widget['id'] }}"
            @if($enabled) disabled @endif>
            <div class="text-sm font-semibold">{{ $widget['title'] }}</div>
            <div class="mt-1 text-xs text-zinc-600">{{ $widget['description'] }}</div>
            <div class="mt-2 text-[11px] uppercase tracking-[0.2em] text-zinc-600">{{ $enabled ? 'Added' : 'Add Widget' }}</div>
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
      @php($defaultState = match($id) {
        'top_scents_forecast', 'top_oils_forecast' => 'forecast',
        'top_scents_current', 'oil_reorder_risk', 'wax_reorder_risk', 'unmapped_exceptions' => 'current',
        'top_scents_actual' => 'actual',
        default => null,
      })

      <section class="min-h-[220px] cursor-move rounded-2xl border border-zinc-200 bg-zinc-50 p-5 shadow-sm {{ $span }}"
        data-widget data-widget-id="{{ $id }}">
        <div class="mb-3 flex items-center justify-between gap-3">
          <h3 class="text-sm font-semibold text-zinc-900">{{ $title }}</h3>
          <div class="flex items-center gap-2">
            @if(in_array($id, $drilldownEnabled, true))
              <button type="button" wire:click="openDrilldown('{{ $id }}', @js($defaultState))"
                class="mf-no-drag rounded-full border border-emerald-300/30 bg-emerald-100 px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.2em] text-emerald-900 hover:bg-emerald-500/25">
                View details
              </button>
            @endif
            <div class="flex items-center gap-1 rounded-full border border-zinc-200 bg-emerald-50 px-1 py-0.5">
              @foreach(['1' => '1', '2' => '2', '3' => '3'] as $val => $label)
                <button type="button" wire:click="setWidgetSize('{{ $id }}','{{ $val }}')"
                  class="mf-no-drag h-6 w-6 rounded-full text-[11px] font-semibold transition {{ $size === $val ? 'bg-emerald-400/60 text-zinc-950' : 'text-zinc-600 hover:bg-emerald-100' }}">
                  {{ $label }}
                </button>
              @endforeach
            </div>
            <button type="button" wire:click="removeWidget('{{ $id }}')"
              class="mf-no-drag text-[10px] uppercase tracking-[0.2em] text-zinc-600 hover:text-zinc-600">remove</button>
          </div>
        </div>

        @if($id === 'unmapped_exceptions')
          @php($exceptions = $data['exceptions'] ?? [])
          <div class="space-y-3 text-sm text-zinc-700">
            <div class="rounded-xl border border-amber-200/20 bg-amber-100 p-3">
              <div class="text-xs uppercase tracking-[0.24em] text-amber-800">Open Unmapped</div>
              <div class="mt-1 text-2xl font-semibold text-zinc-950">{{ (int) ($exceptions['open_count'] ?? 0) }}</div>
            </div>

            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
              @forelse(($exceptions['by_channel'] ?? []) as $row)
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs">
                  <div class="uppercase tracking-[0.18em] text-zinc-500">{{ strtoupper((string) ($row['channel'] ?? 'unknown')) }}</div>
                  <div class="mt-1 text-base font-semibold text-zinc-950">{{ (int) ($row['open_count'] ?? 0) }}</div>
                </div>
              @empty
                <div class="text-zinc-500">No open mapping exceptions.</div>
              @endforelse
            </div>

            @if(!empty($exceptions['top_raw_names']))
              <div class="rounded-xl border border-zinc-200 bg-emerald-50 p-3">
                <div class="text-xs uppercase tracking-[0.24em] text-zinc-600">Top Unmapped Names</div>
                <div class="mt-2 space-y-1 text-xs text-zinc-700">
                  @foreach(($exceptions['top_raw_names'] ?? []) as $row)
                    <div class="flex items-center justify-between gap-3">
                      <span class="truncate">{{ $row['raw_name'] ?? 'Unknown' }}</span>
                      <span class="font-semibold text-zinc-950">{{ (int) ($row['open_count'] ?? 0) }}</span>
                    </div>
                  @endforeach
                </div>
              </div>
            @endif

            <a href="{{ $data['urls']['mapping_exceptions'] ?? '#' }}" wire:navigate
              class="inline-flex items-center rounded-full border border-amber-300/25 bg-amber-100 px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">Open Mapping Resolution</a>
          </div>

        @elseif(in_array($id, ['top_scents_forecast', 'top_scents_current', 'top_scents_actual'], true))
          @php($state = str_replace('top_scents_', '', $id))
          @php($slice = $data[$state] ?? ['top_scents' => [], 'bundle' => []])
          @php($bundle = $slice['bundle'] ?? [])
          <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] {{ $stateBadge($state) }}">{{ $state }}</span>
            <div class="text-xs text-zinc-600">Δ Units {{ $deltaLabel(data_get($bundle, 'delta.metrics.units')) }}</div>
          </div>

          <div class="mb-3 text-xs text-zinc-500">
            {{ data_get($bundle, 'timeframe.labels.primary') }}
            @if(data_get($bundle, 'timeframe.labels.comparison'))
              <span class="mx-1">vs</span>{{ data_get($bundle, 'timeframe.labels.comparison') }}
            @endif
          </div>

          <div class="overflow-x-auto rounded-xl border border-zinc-200">
            <table class="min-w-full text-sm">
              <thead class="bg-zinc-50 text-zinc-600">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">Scent</th>
                  <th class="px-3 py-2 text-right font-medium">Units</th>
                  <th class="px-3 py-2 text-right font-medium">Wax g</th>
                  <th class="px-3 py-2 text-right font-medium">Oil g</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-zinc-200">
                @forelse(($slice['top_scents'] ?? []) as $row)
                  <tr class="hover:bg-zinc-50">
                    <td class="px-3 py-2 text-zinc-900">{{ $row['scent_name'] ?? 'Unknown' }}</td>
                    <td class="px-3 py-2 text-right text-zinc-700">{{ $fmt($row['units'] ?? 0) }}</td>
                    <td class="px-3 py-2 text-right text-zinc-700">{{ $fmt($row['wax_grams'] ?? 0, 1) }}</td>
                    <td class="px-3 py-2 text-right text-zinc-700">{{ $fmt($row['oil_grams'] ?? 0, 1) }}</td>
                  </tr>
                @empty
                  <tr>
                    <td class="px-3 py-4 text-zinc-500" colspan="4">No data for this state/window.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>

        @elseif($id === 'top_oils_forecast')
          @php($bundle = $data['top_oils_forecast_bundle'] ?? [])
          <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] {{ $stateBadge('forecast') }}">forecast</span>
            <div class="text-xs text-zinc-600">Δ Oil {{ $deltaLabel(data_get($bundle, 'delta.metrics.oil_grams')) }}g</div>
          </div>
          <div class="mb-3 text-xs text-zinc-500">{{ data_get($bundle, 'timeframe.labels.primary') }} @if(data_get($bundle, 'timeframe.labels.comparison')) <span class="mx-1">vs</span>{{ data_get($bundle, 'timeframe.labels.comparison') }} @endif</div>
          <div class="overflow-x-auto rounded-xl border border-zinc-200">
            <table class="min-w-full text-sm">
              <thead class="bg-zinc-50 text-zinc-600">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">Oil</th>
                  <th class="px-3 py-2 text-right font-medium">Grams</th>
                  <th class="px-3 py-2 text-right font-medium">% of Total</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-zinc-200">
                @forelse(($data['top_oils_forecast'] ?? []) as $row)
                  <tr class="hover:bg-zinc-50">
                    <td class="px-3 py-2 text-zinc-900">{{ $row['base_oil_name'] ?? 'Unknown' }}</td>
                    <td class="px-3 py-2 text-right text-zinc-700">{{ $fmt($row['grams'] ?? 0, 1) }}</td>
                    <td class="px-3 py-2 text-right text-zinc-700">{{ $fmt($row['percent_of_total'] ?? 0, 2) }}%</td>
                  </tr>
                @empty
                  <tr>
                    <td class="px-3 py-4 text-zinc-500" colspan="3">No forecast oil demand rows.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>

        @elseif($id === 'oil_reorder_risk')
          @php($bundle = $data['reorder_risk_bundle'] ?? [])
          @php($primary = $bundle['primary'] ?? [])
          @php($riskRows = data_get($primary, 'oil.rows', []))
          @php($summary = data_get($primary, 'oil.summary', []))
          <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
            <span class="rounded-full border border-rose-300/35 bg-rose-100 px-2 py-0.5 text-rose-900">Reorder: {{ (int) ($summary['reorder_count'] ?? 0) }}</span>
            <span class="rounded-full border border-amber-300/35 bg-amber-100 px-2 py-0.5 text-amber-900">Low: {{ (int) ($summary['low_count'] ?? 0) }}</span>
            <span class="rounded-full border border-zinc-300 bg-emerald-100 px-2 py-0.5 text-emerald-900">OK: {{ (int) ($summary['ok_count'] ?? 0) }}</span>
            <span class="ml-auto text-zinc-600">Δ Reorder {{ $deltaLabel(data_get($bundle, 'delta.metrics.oil_reorder_count')) }}</span>
          </div>
          <div class="overflow-x-auto rounded-xl border border-zinc-200">
            <table class="min-w-full text-sm">
              <thead class="bg-zinc-50 text-zinc-600">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">Oil</th>
                  <th class="px-3 py-2 text-right font-medium">On Hand</th>
                  <th class="px-3 py-2 text-right font-medium">Projected</th>
                  <th class="px-3 py-2 text-right font-medium">Risk</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-zinc-200">
                @forelse($riskRows as $row)
                  @php($status = data_get($row, 'state_after_demand.status', 'ok'))
                  <tr class="hover:bg-zinc-50">
                    <td class="px-3 py-2 text-zinc-900">{{ $row['name'] ?? 'Unknown' }}</td>
                    <td class="px-3 py-2 text-right text-zinc-700">{{ $fmt($row['on_hand_grams'] ?? 0, 1) }}g</td>
                    <td class="px-3 py-2 text-right text-zinc-700">{{ $fmt($row['projected_on_hand_grams'] ?? 0, 1) }}g</td>
                    <td class="px-3 py-2 text-right"><span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $riskBadge($status) }}">{{ $status }}</span></td>
                  </tr>
                @empty
                  <tr>
                    <td class="px-3 py-4 text-zinc-500" colspan="4">No oil reorder-risk rows.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>

        @elseif($id === 'wax_reorder_risk')
          @php($bundle = $data['reorder_risk_bundle'] ?? [])
          @php($primary = $bundle['primary'] ?? [])
          @php($riskRows = data_get($primary, 'wax.rows', []))
          <div class="mb-2 text-xs text-zinc-600">Δ Reorder {{ $deltaLabel(data_get($bundle, 'delta.metrics.wax_reorder_count')) }}</div>
          <div class="space-y-2">
            @forelse($riskRows as $row)
              @php($status = data_get($row, 'state_after_demand.status', 'ok'))
              <div class="rounded-xl border border-zinc-200 bg-emerald-50 p-3">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <div class="text-sm font-semibold text-zinc-900">{{ $row['name'] ?? 'Wax' }}</div>
                    <div class="mt-1 text-xs text-zinc-600">On hand {{ $fmt($row['on_hand_grams'] ?? 0, 1) }}g · Projected {{ $fmt($row['projected_on_hand_grams'] ?? 0, 1) }}g</div>
                  </div>
                  <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $riskBadge($status) }}">{{ $status }}</span>
                </div>
              </div>
            @empty
              <div class="text-sm text-zinc-500">No wax reorder-risk rows.</div>
            @endforelse
          </div>

        @elseif($id === 'inventory_snapshot')
          @php($snap = $data['inventory_snapshot'] ?? [])
          <div class="grid grid-cols-2 gap-2 text-xs sm:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3"><div class="uppercase tracking-[0.16em] text-zinc-500">Oils Tracked</div><div class="mt-1 text-lg font-semibold text-zinc-950">{{ (int) ($snap['oil_total_items'] ?? 0) }}</div></div>
            <div class="rounded-xl border border-amber-300/25 bg-amber-100 p-3"><div class="uppercase tracking-[0.16em] text-amber-800">Oil Low</div><div class="mt-1 text-lg font-semibold text-amber-900">{{ (int) ($snap['oil_low_count'] ?? 0) }}</div></div>
            <div class="rounded-xl border border-rose-300/25 bg-rose-100 p-3"><div class="uppercase tracking-[0.16em] text-rose-800">Oil Reorder</div><div class="mt-1 text-lg font-semibold text-rose-900">{{ (int) ($snap['oil_reorder_count'] ?? 0) }}</div></div>
            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3"><div class="uppercase tracking-[0.16em] text-zinc-500">Wax On Hand</div><div class="mt-1 text-lg font-semibold text-zinc-950">{{ $fmt($snap['wax_on_hand_grams'] ?? 0, 1) }}g</div><div class="text-[11px] text-zinc-500">{{ $fmt($snap['wax_on_hand_boxes'] ?? 0, 2) }} × 45lb</div></div>
            <div class="rounded-xl border border-amber-300/25 bg-amber-100 p-3"><div class="uppercase tracking-[0.16em] text-amber-800">Wax Low</div><div class="mt-1 text-lg font-semibold text-amber-900">{{ (int) ($snap['wax_low_count'] ?? 0) }}</div></div>
            <div class="rounded-xl border border-rose-300/25 bg-rose-100 p-3"><div class="uppercase tracking-[0.16em] text-rose-800">Wax Reorder</div><div class="mt-1 text-lg font-semibold text-rose-900">{{ (int) ($snap['wax_reorder_count'] ?? 0) }}</div></div>
          </div>
          <a href="{{ $data['urls']['inventory'] ?? '#' }}" wire:navigate class="mt-3 inline-flex items-center rounded-full border border-emerald-300/25 bg-emerald-100 px-3 py-1.5 text-xs font-semibold text-emerald-900 hover:bg-emerald-100">Open Inventory Maintenance</a>

        @elseif($id === 'demand_state_overview')
          <div class="overflow-x-auto rounded-xl border border-zinc-200">
            <table class="min-w-full text-sm">
              <thead class="bg-zinc-50 text-zinc-600">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">State</th>
                  <th class="px-3 py-2 text-right font-medium">Units</th>
                  <th class="px-3 py-2 text-right font-medium">Wax g</th>
                  <th class="px-3 py-2 text-right font-medium">Oil g</th>
                  <th class="px-3 py-2 text-right font-medium">Δ Units</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-zinc-200">
                @foreach(($data['state_totals'] ?? []) as $row)
                  <tr class="hover:bg-zinc-50">
                    <td class="px-3 py-2"><span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] {{ $stateBadge($row['state'] ?? '') }}">{{ $row['state'] ?? 'unknown' }}</span></td>
                    <td class="px-3 py-2 text-right text-zinc-800">{{ $fmt($row['units'] ?? 0) }}</td>
                    <td class="px-3 py-2 text-right text-zinc-800">{{ $fmt($row['wax_grams'] ?? 0, 1) }}</td>
                    <td class="px-3 py-2 text-right text-zinc-800">{{ $fmt($row['oil_grams'] ?? 0, 1) }}</td>
                    <td class="px-3 py-2 text-right text-zinc-700">{{ $deltaLabel($row['delta'] ?? null) }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>

        @else
          <div class="text-sm text-zinc-500">Unknown widget.</div>
        @endif
      </section>
    @endforeach
  </div>

  @if($showDrilldown && !empty($drilldown))
    @php($trendBars = function (array $series) {
      $max = max(1, (float) collect($series)->max('value'));
      return ['max' => $max, 'series' => $series];
    })
    <div class="fixed inset-0 z-[120]">
      <div class="absolute inset-0 fb-overlay-soft" wire:click="closeDrilldown"></div>
      <aside class="absolute right-0 top-0 h-full w-full max-w-3xl overflow-y-auto border-l border-zinc-200 bg-white p-6 shadow-xl">
        <div class="flex items-start justify-between gap-4">
          <div>
            <div class="text-[11px] uppercase tracking-[0.3em] text-zinc-600">Drilldown Detail</div>
            <h3 class="mt-2 text-2xl font-['Fraunces'] font-semibold text-zinc-950">{{ $drilldown['title'] ?? 'Detail' }}</h3>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-zinc-600">
              @if(!empty($drilldown['state']) && !in_array($drilldown['state'], ['mixed', ''], true))
                <span class="inline-flex items-center rounded-full border px-2 py-0.5 font-semibold uppercase tracking-[0.16em] {{ $stateBadge($drilldown['state']) }}">State: {{ $drilldown['state'] }}</span>
              @endif
              <span>Primary: {{ data_get($drilldown, 'labels.primary') }}</span>
              @if(data_get($drilldown, 'labels.comparison'))
                <span class="text-zinc-500">|</span>
                <span>Compare: {{ data_get($drilldown, 'labels.comparison') }}</span>
              @endif
            </div>
          </div>
          <button type="button" wire:click="closeDrilldown" class="rounded-full border border-zinc-300 bg-zinc-100 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-zinc-100">Close</button>
        </div>

        @if(!empty($drilldown['actions']))
          <div class="mt-4 flex flex-wrap gap-2">
            @foreach(($drilldown['actions'] ?? []) as $action)
              <a href="{{ $action['url'] ?? '#' }}" wire:navigate class="inline-flex items-center rounded-full border border-emerald-300/30 bg-emerald-100 px-3 py-1.5 text-xs font-semibold text-emerald-900 hover:bg-emerald-500/25">{{ $action['label'] ?? 'Open' }}</a>
            @endforeach
          </div>
        @endif

        <div class="mt-6 space-y-6">
          @if(($drilldown['widget'] ?? '') === 'unmapped_exceptions')
            @php($summary = $drilldown['summary'] ?? [])
            @php($rows = data_get($drilldown, 'details.rows', []))
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div class="rounded-xl border border-amber-200/20 bg-amber-100 p-3">
                <div class="text-xs uppercase tracking-[0.2em] text-amber-800">Open Exceptions</div>
                <div class="mt-1 text-xl font-semibold text-zinc-950">{{ (int) ($summary['open_count'] ?? 0) }}</div>
              </div>
              @foreach(array_slice((array) ($summary['by_channel'] ?? []), 0, 2) as $channelRow)
                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3">
                  <div class="text-xs uppercase tracking-[0.2em] text-zinc-600">{{ strtoupper((string) ($channelRow['channel'] ?? 'unknown')) }}</div>
                  <div class="mt-1 text-xl font-semibold text-zinc-950">{{ (int) ($channelRow['open_count'] ?? 0) }}</div>
                </div>
              @endforeach
            </div>

            <div class="overflow-x-auto rounded-xl border border-zinc-200">
              <table class="min-w-full text-sm">
                <thead class="bg-zinc-50 text-zinc-600">
                  <tr>
                    <th class="px-3 py-2 text-left font-medium">Raw Name</th>
                    <th class="px-3 py-2 text-left font-medium">Variant</th>
                    <th class="px-3 py-2 text-left font-medium">Account/Store</th>
                    <th class="px-3 py-2 text-left font-medium">First Seen</th>
                    <th class="px-3 py-2 text-right font-medium">Action</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200">
                  @forelse($rows as $row)
                    <tr>
                      <td class="px-3 py-2 text-zinc-800">{{ $row['raw_name'] ?? 'Unknown' }}</td>
                      <td class="px-3 py-2 text-zinc-600">{{ $row['raw_variant'] ?: '—' }}</td>
                      <td class="px-3 py-2 text-zinc-600">{{ $row['account_name'] ?: ($row['store_key'] ?? '—') }}</td>
                      <td class="px-3 py-2 text-zinc-600">{{ $row['created_at'] ?? '—' }}</td>
                      <td class="px-3 py-2 text-right">
                        @if(!empty($row['handoff_url']))
                          <a href="{{ $row['handoff_url'] }}" wire:navigate class="inline-flex items-center rounded-full border border-amber-300/30 bg-amber-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-amber-900 hover:bg-amber-100">Resolve</a>
                        @else
                          <span class="text-zinc-500">—</span>
                        @endif
                      </td>
                    </tr>
                  @empty
                    <tr><td class="px-3 py-4 text-zinc-500" colspan="5">No unresolved rows.</td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>

            @php($trend = $trendBars((array) ($drilldown['trend'] ?? [])))
            <div>
              <div class="text-xs uppercase tracking-[0.22em] text-zinc-600">Unmapped Exceptions Trend</div>
              <div class="mt-3 space-y-2">
                @foreach($trend['series'] as $point)
                  <div class="flex items-center gap-3 text-xs">
                    <div class="w-32 shrink-0 text-zinc-600">{{ $point['label'] }}</div>
                    <div class="h-2 flex-1 rounded-full bg-zinc-100">
                      <div class="h-2 rounded-full bg-amber-400/70" style="width: {{ min(100, ((float) ($point['value'] ?? 0) / $trend['max']) * 100) }}%"></div>
                    </div>
                    <div class="w-10 text-right text-zinc-800">{{ (int) ($point['value'] ?? 0) }}</div>
                  </div>
                @endforeach
              </div>
            </div>

          @elseif(($drilldown['widget'] ?? '') === 'oil_reorder_risk')
            @php($bundle = $drilldown['bundle'] ?? [])
            @php($riskRows = data_get($bundle, 'primary.oil.rows', []))
            @php($contributors = collect(data_get($drilldown, 'contributors.primary.rows', []))->keyBy('base_oil_id'))
            <div class="overflow-x-auto rounded-xl border border-zinc-200">
              <table class="min-w-full text-sm">
                <thead class="bg-zinc-50 text-zinc-600">
                  <tr>
                    <th class="px-3 py-2 text-left font-medium">Oil</th>
                    <th class="px-3 py-2 text-right font-medium">Demand g</th>
                    <th class="px-3 py-2 text-right font-medium">On Hand g</th>
                    <th class="px-3 py-2 text-right font-medium">Projected g</th>
                    <th class="px-3 py-2 text-right font-medium">Threshold g</th>
                    <th class="px-3 py-2 text-right font-medium">Risk</th>
                    <th class="px-3 py-2 text-right font-medium">Action</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200">
                  @forelse($riskRows as $row)
                    @php($status = data_get($row, 'state_after_demand.status', 'ok'))
                    @php($oilContrib = data_get($contributors->get((int) ($row['base_oil_id'] ?? 0), []), 'contributors', []))
                    <tr>
                      <td class="px-3 py-2 text-zinc-900">
                        <div>{{ $row['name'] ?? 'Unknown' }}</div>
                        @if(!empty($oilContrib))
                          <div class="mt-1 text-[11px] text-zinc-500">Top scent drivers:
                            {{ collect($oilContrib)->take(3)->map(fn($x) => ($x['scent_name'] ?? 'Unknown').' '.number_format((float)($x['grams'] ?? 0), 1).'g')->implode(' · ') }}
                          </div>
                        @endif
                      </td>
                      <td class="px-3 py-2 text-right text-zinc-700">{{ $fmt($row['demand_grams'] ?? 0, 1) }}</td>
                      <td class="px-3 py-2 text-right text-zinc-700">{{ $fmt($row['on_hand_grams'] ?? 0, 1) }}</td>
                      <td class="px-3 py-2 text-right text-zinc-700">{{ $fmt($row['projected_on_hand_grams'] ?? 0, 1) }}</td>
                      <td class="px-3 py-2 text-right text-zinc-700">{{ $fmt($row['reorder_threshold_grams'] ?? 0, 1) }}</td>
                      <td class="px-3 py-2 text-right"><span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $riskBadge($status) }}">{{ $status }}</span></td>
                      <td class="px-3 py-2 text-right">
                        @if(!empty($row['handoff_url']))
                          <a href="{{ $row['handoff_url'] }}" wire:navigate class="inline-flex items-center rounded-full border border-emerald-300/30 bg-emerald-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-900 hover:bg-emerald-100">Open</a>
                        @else
                          <span class="text-zinc-500">—</span>
                        @endif
                      </td>
                    </tr>
                  @empty
                    <tr><td class="px-3 py-4 text-zinc-500" colspan="7">No oil risk rows.</td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>

            @php($trend = $trendBars((array) ($drilldown['trend'] ?? [])))
            <div>
              <div class="text-xs uppercase tracking-[0.22em] text-zinc-600">Current Oil Demand Trend</div>
              <div class="mt-3 space-y-2">
                @foreach($trend['series'] as $point)
                  <div class="flex items-center gap-3 text-xs">
                    <div class="w-32 shrink-0 text-zinc-600">{{ $point['label'] }}</div>
                    <div class="h-2 flex-1 rounded-full bg-zinc-100">
                      <div class="h-2 rounded-full bg-emerald-400/70" style="width: {{ min(100, ((float) ($point['value'] ?? 0) / $trend['max']) * 100) }}%"></div>
                    </div>
                    <div class="w-14 text-right text-zinc-800">{{ $fmt($point['value'] ?? 0, 1) }}g</div>
                  </div>
                @endforeach
              </div>
            </div>

          @elseif(($drilldown['widget'] ?? '') === 'wax_reorder_risk')
            @php($bundle = $drilldown['bundle'] ?? [])
            @php($waxRows = data_get($bundle, 'primary.wax.rows', []))
            <div class="space-y-3">
              @forelse($waxRows as $row)
                @php($status = data_get($row, 'state_after_demand.status', 'ok'))
                @php($gramsPerBox = 20411.66)
                <div class="rounded-xl border border-emerald-200/12 bg-emerald-50 p-4">
                  <div class="flex items-start justify-between gap-3">
                    <div>
                      <div class="text-sm font-semibold text-zinc-900">{{ $row['name'] ?? 'Wax' }}</div>
                      <div class="mt-1 text-xs text-zinc-600">
                        On hand {{ $fmt($row['on_hand_grams'] ?? 0, 1) }}g ({{ $fmt(((float)($row['on_hand_grams'] ?? 0)) / $gramsPerBox, 2) }} boxes)
                        · Projected {{ $fmt($row['projected_on_hand_grams'] ?? 0, 1) }}g ({{ $fmt(((float)($row['projected_on_hand_grams'] ?? 0)) / $gramsPerBox, 2) }} boxes)
                      </div>
                      <div class="mt-1 text-xs text-zinc-500">
                        Threshold {{ $fmt($row['reorder_threshold_grams'] ?? 0, 1) }}g ({{ $fmt(((float)($row['reorder_threshold_grams'] ?? 0)) / $gramsPerBox, 2) }} boxes)
                        · Demand {{ $fmt($row['demand_grams'] ?? 0, 1) }}g
                      </div>
                      @if(!empty($row['handoff_url']))
                        <a href="{{ $row['handoff_url'] }}" wire:navigate class="mt-2 inline-flex items-center rounded-full border border-emerald-300/30 bg-emerald-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-900 hover:bg-emerald-100">Open Inventory</a>
                      @endif
                    </div>
                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $riskBadge($status) }}">{{ $status }}</span>
                  </div>
                </div>
              @empty
                <div class="text-sm text-zinc-500">No wax risk rows.</div>
              @endforelse
            </div>

            @php($trend = $trendBars((array) ($drilldown['trend'] ?? [])))
            <div>
              <div class="text-xs uppercase tracking-[0.22em] text-zinc-600">Current Wax Demand Trend</div>
              <div class="mt-3 space-y-2">
                @foreach($trend['series'] as $point)
                  <div class="flex items-center gap-3 text-xs">
                    <div class="w-32 shrink-0 text-zinc-600">{{ $point['label'] }}</div>
                    <div class="h-2 flex-1 rounded-full bg-zinc-100">
                      <div class="h-2 rounded-full bg-emerald-400/70" style="width: {{ min(100, ((float) ($point['value'] ?? 0) / $trend['max']) * 100) }}%"></div>
                    </div>
                    <div class="w-14 text-right text-zinc-800">{{ $fmt($point['value'] ?? 0, 1) }}g</div>
                  </div>
                @endforeach
              </div>
            </div>

          @elseif(in_array(($drilldown['widget'] ?? ''), ['top_scents_forecast','top_scents_current','top_scents_actual'], true))
            @php($bundle = $drilldown['bundle'] ?? [])
            @php($rows = data_get($bundle, 'primary.rows', []))
            <div class="mb-2 text-sm text-zinc-600">Δ Units {{ $deltaLabel(data_get($bundle, 'delta.metrics.units')) }}</div>
            <div class="overflow-x-auto rounded-xl border border-zinc-200">
              <table class="min-w-full text-sm">
                <thead class="bg-zinc-50 text-zinc-600">
                  <tr>
                    <th class="px-3 py-2 text-left font-medium">Scent</th>
                    <th class="px-3 py-2 text-left font-medium">Channel</th>
                    <th class="px-3 py-2 text-right font-medium">Units</th>
                    <th class="px-3 py-2 text-right font-medium">Wax g</th>
                    <th class="px-3 py-2 text-right font-medium">Oil g</th>
                    <th class="px-3 py-2 text-right font-medium">Rows</th>
                    <th class="px-3 py-2 text-right font-medium">Action</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200">
                  @forelse($rows as $row)
                    <tr>
                      <td class="px-3 py-2 text-zinc-900">{{ $row['scent_name'] ?? 'Unknown' }}</td>
                      <td class="px-3 py-2 text-zinc-600">{{ $row['channel'] ?? '—' }}</td>
                      <td class="px-3 py-2 text-right text-zinc-700">{{ $fmt($row['units'] ?? 0) }}</td>
                      <td class="px-3 py-2 text-right text-zinc-700">{{ $fmt($row['wax_grams'] ?? 0, 1) }}</td>
                      <td class="px-3 py-2 text-right text-zinc-700">{{ $fmt($row['oil_grams'] ?? 0, 1) }}</td>
                      <td class="px-3 py-2 text-right text-zinc-700">{{ $fmt($row['order_count'] ?? 0) }}</td>
                      <td class="px-3 py-2 text-right">
                        @if(!empty($row['handoff_url']))
                          <a href="{{ $row['handoff_url'] }}" wire:navigate class="inline-flex items-center rounded-full border border-cyan-300/30 bg-cyan-500/10 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-cyan-100 hover:bg-cyan-500/20">Open</a>
                        @else
                          <span class="text-zinc-500">—</span>
                        @endif
                      </td>
                    </tr>
                  @empty
                    <tr><td class="px-3 py-4 text-zinc-500" colspan="7">No scent rows.</td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>

            @php($trend = $trendBars((array) ($drilldown['trend'] ?? [])))
            <div>
              <div class="text-xs uppercase tracking-[0.22em] text-zinc-600">Scent Demand Trend</div>
              <div class="mt-3 space-y-2">
                @foreach($trend['series'] as $point)
                  <div class="flex items-center gap-3 text-xs">
                    <div class="w-32 shrink-0 text-zinc-600">{{ $point['label'] }}</div>
                    <div class="h-2 flex-1 rounded-full bg-zinc-100">
                      <div class="h-2 rounded-full bg-cyan-400/70" style="width: {{ min(100, ((float) ($point['value'] ?? 0) / $trend['max']) * 100) }}%"></div>
                    </div>
                    <div class="w-14 text-right text-zinc-800">{{ $fmt($point['value'] ?? 0) }}</div>
                  </div>
                @endforeach
              </div>
            </div>

          @elseif(($drilldown['widget'] ?? '') === 'top_oils_forecast')
            @php($bundle = $drilldown['bundle'] ?? [])
            @php($rows = data_get($bundle, 'primary.rows', []))
            @php($contributors = data_get($drilldown, 'contributors.primary.rows', []))
            <div class="mb-2 text-sm text-zinc-600">Δ Oil {{ $deltaLabel(data_get($bundle, 'delta.metrics.oil_grams')) }}g</div>
            <div class="overflow-x-auto rounded-xl border border-zinc-200">
              <table class="min-w-full text-sm">
                <thead class="bg-zinc-50 text-zinc-600">
                  <tr>
                    <th class="px-3 py-2 text-left font-medium">Oil</th>
                    <th class="px-3 py-2 text-right font-medium">Grams</th>
                    <th class="px-3 py-2 text-right font-medium">% of Total</th>
                    <th class="px-3 py-2 text-right font-medium">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200">
                  @forelse($rows as $row)
                    <tr>
                      <td class="px-3 py-2 text-zinc-900">{{ $row['base_oil_name'] ?? 'Unknown' }}</td>
                      <td class="px-3 py-2 text-right text-zinc-700">{{ $fmt($row['grams'] ?? 0, 1) }}</td>
                      <td class="px-3 py-2 text-right text-zinc-700">{{ $fmt($row['percent_of_total'] ?? 0, 2) }}%</td>
                      <td class="px-3 py-2 text-right">
                        <div class="inline-flex items-center gap-1">
                          @if(!empty($row['handoff_url']))
                            <a href="{{ $row['handoff_url'] }}" wire:navigate class="rounded-full border border-emerald-300/30 bg-emerald-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-900 hover:bg-emerald-100">Open</a>
                          @endif
                          <button type="button" wire:click="openDrilldown('top_oils_forecast','forecast',{{ (int) ($row['base_oil_id'] ?? 0) }})" class="rounded-full border border-emerald-300/30 bg-emerald-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-900 hover:bg-emerald-100">Focus</button>
                        </div>
                      </td>
                    </tr>
                  @empty
                    <tr><td class="px-3 py-4 text-zinc-500" colspan="4">No oil rows.</td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>

            <div class="space-y-2">
              <div class="text-xs uppercase tracking-[0.22em] text-zinc-600">Oil Contributors</div>
              @forelse($contributors as $oilRow)
                <div class="rounded-xl border border-emerald-200/12 bg-emerald-50 p-3">
                  <div class="text-sm font-semibold text-zinc-900">{{ $oilRow['base_oil_name'] ?? 'Oil' }} · {{ $fmt($oilRow['total_grams'] ?? 0, 1) }}g</div>
                  <div class="mt-1 text-xs text-zinc-600">
                    {{ collect($oilRow['contributors'] ?? [])->map(fn($x) => ($x['scent_name'] ?? 'Unknown').' '.number_format((float)($x['grams'] ?? 0), 1).'g')->implode(' · ') ?: 'No contributors' }}
                  </div>
                </div>
              @empty
                <div class="text-sm text-zinc-500">No contributor rows.</div>
              @endforelse
            </div>

            @php($trend = $trendBars((array) ($drilldown['trend'] ?? [])))
            <div>
              <div class="text-xs uppercase tracking-[0.22em] text-zinc-600">Forecast Oil Trend</div>
              <div class="mt-3 space-y-2">
                @foreach($trend['series'] as $point)
                  <div class="flex items-center gap-3 text-xs">
                    <div class="w-32 shrink-0 text-zinc-600">{{ $point['label'] }}</div>
                    <div class="h-2 flex-1 rounded-full bg-zinc-100">
                      <div class="h-2 rounded-full bg-sky-400/70" style="width: {{ min(100, ((float) ($point['value'] ?? 0) / $trend['max']) * 100) }}%"></div>
                    </div>
                    <div class="w-14 text-right text-zinc-800">{{ $fmt($point['value'] ?? 0, 1) }}g</div>
                  </div>
                @endforeach
              </div>
            </div>

          @elseif(($drilldown['widget'] ?? '') === 'demand_state_overview')
            @php($states = $drilldown['states'] ?? [])
            <div class="overflow-x-auto rounded-xl border border-zinc-200">
              <table class="min-w-full text-sm">
                <thead class="bg-zinc-50 text-zinc-600">
                  <tr>
                    <th class="px-3 py-2 text-left font-medium">State</th>
                    <th class="px-3 py-2 text-right font-medium">Units</th>
                    <th class="px-3 py-2 text-right font-medium">Wax g</th>
                    <th class="px-3 py-2 text-right font-medium">Oil g</th>
                    <th class="px-3 py-2 text-right font-medium">Δ Units</th>
                    <th class="px-3 py-2 text-right font-medium">Action</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200">
                  @foreach(['forecast','current','actual'] as $stateKey)
                    @php($bundle = $states[$stateKey] ?? [])
                    <tr>
                      <td class="px-3 py-2"><span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] {{ $stateBadge($stateKey) }}">{{ $stateKey }}</span></td>
                      <td class="px-3 py-2 text-right text-zinc-800">{{ $fmt(data_get($bundle, 'primary.totals.units', 0)) }}</td>
                      <td class="px-3 py-2 text-right text-zinc-800">{{ $fmt(data_get($bundle, 'primary.totals.wax_grams', 0), 1) }}</td>
                      <td class="px-3 py-2 text-right text-zinc-800">{{ $fmt(data_get($bundle, 'primary.totals.oil_grams', 0), 1) }}</td>
                      <td class="px-3 py-2 text-right text-zinc-700">{{ $deltaLabel(data_get($bundle, 'delta.metrics.units')) }}</td>
                      <td class="px-3 py-2 text-right">
                        @php($handoff = data_get($drilldown, 'state_handoffs.'.$stateKey))
                        @if($handoff)
                          <a href="{{ $handoff }}" wire:navigate class="inline-flex items-center rounded-full border border-emerald-300/30 bg-emerald-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-900 hover:bg-emerald-100">Open</a>
                        @else
                          <span class="text-zinc-500">—</span>
                        @endif
                      </td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
              @foreach(['forecast','current','actual'] as $stateKey)
                @php($trend = $trendBars((array) data_get($drilldown, 'trend.'.$stateKey, [])))
                <div>
                  <div class="text-xs uppercase tracking-[0.2em] text-zinc-600">{{ $stateKey }} trend</div>
                  <div class="mt-2 space-y-2">
                    @foreach($trend['series'] as $point)
                      <div class="flex items-center gap-2 text-[11px]">
                        <div class="w-16 shrink-0 text-zinc-500">{{ $point['from'] }}</div>
                        <div class="h-2 flex-1 rounded-full bg-zinc-100">
                          <div class="h-2 rounded-full bg-emerald-400/70" style="width: {{ min(100, ((float) ($point['value'] ?? 0) / $trend['max']) * 100) }}%"></div>
                        </div>
                        <div class="w-8 text-right text-zinc-700">{{ (int) ($point['value'] ?? 0) }}</div>
                      </div>
                    @endforeach
                  </div>
                </div>
              @endforeach
            </div>
          @endif
        </div>
      </aside>
    </div>
  @endif
</div>
