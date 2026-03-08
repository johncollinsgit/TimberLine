@php($data = $this->analyticsData)
@php($widgets = $this->visibleWidgets)
@php($library = $this->widgetLibrary)
@php($drilldown = $this->drilldownData)
@php($drilldownEnabled = ['unmapped_exceptions','oil_reorder_risk','wax_reorder_risk','top_scents_forecast','top_scents_current','top_scents_actual','top_oils_forecast','demand_state_overview'])
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
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)]">
    <div class="flex flex-col gap-4">
      <div>
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Reporting Widgets</div>
        <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Operational Analytics Overview</div>
        <div class="mt-2 text-sm text-emerald-50/70">Forecast, current, and actual are separated and can be compared against explicit prior windows.</div>
      </div>

      <div class="grid grid-cols-1 gap-3 lg:grid-cols-6">
        <label class="flex flex-col gap-1 text-xs text-emerald-100/70">
          <span class="uppercase tracking-[0.22em]">Mode</span>
          <select wire:model.defer="timeMode" class="rounded-xl border border-emerald-200/20 bg-emerald-500/10 px-3 py-2 text-sm text-white">
            @foreach($this->timeModeOptions as $option)
              <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
            @endforeach
          </select>
        </label>

        <label class="flex flex-col gap-1 text-xs text-emerald-100/70 lg:col-span-2">
          <span class="uppercase tracking-[0.22em]">Preset</span>
          <select wire:model.defer="preset" class="rounded-xl border border-emerald-200/20 bg-emerald-500/10 px-3 py-2 text-sm text-white">
            @foreach($this->presetOptions as $option)
              <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
            @endforeach
          </select>
        </label>

        <label class="flex flex-col gap-1 text-xs text-emerald-100/70">
          <span class="uppercase tracking-[0.22em]">Compare</span>
          <select wire:model.defer="comparisonMode" class="rounded-xl border border-emerald-200/20 bg-emerald-500/10 px-3 py-2 text-sm text-white">
            @foreach($this->comparisonOptions as $option)
              <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
            @endforeach
          </select>
        </label>

        <label class="flex flex-col gap-1 text-xs text-emerald-100/70">
          <span class="uppercase tracking-[0.22em]">Channel</span>
          <select wire:model.defer="channel" class="rounded-xl border border-emerald-200/20 bg-emerald-500/10 px-3 py-2 text-sm text-white">
            @foreach($this->channelOptions as $option)
              <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
            @endforeach
          </select>
        </label>

        <div class="flex items-end justify-start gap-2 lg:justify-end">
          <button type="button" wire:click="applyFilters"
            class="inline-flex items-center rounded-full border border-emerald-300/45 bg-emerald-500/30 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500/40">
            Apply
          </button>
          <button type="button" wire:click="toggleLibrary"
            class="inline-flex items-center rounded-full border px-4 py-2 text-sm font-semibold transition {{ $showLibrary ? 'border-emerald-300/60 bg-emerald-500/35 text-white' : 'border-emerald-200/25 bg-emerald-500/10 text-emerald-100/90 hover:bg-emerald-500/20' }}">
            Widgets Tray
          </button>
        </div>
      </div>

      @if($preset === 'custom')
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <label class="flex flex-col gap-1 text-xs text-emerald-100/70">
            <span class="uppercase tracking-[0.22em]">Start</span>
            <input type="date" wire:model.defer="customStartDate" class="rounded-xl border border-emerald-200/20 bg-emerald-500/10 px-3 py-2 text-sm text-white" />
          </label>
          <label class="flex flex-col gap-1 text-xs text-emerald-100/70">
            <span class="uppercase tracking-[0.22em]">End</span>
            <input type="date" wire:model.defer="customEndDate" class="rounded-xl border border-emerald-200/20 bg-emerald-500/10 px-3 py-2 text-sm text-white" />
          </label>
        </div>
      @endif

      <div class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 px-4 py-3 text-xs text-emerald-100/75">
        Primary: {{ data_get($data, 'timeframe.labels.primary') }}
        @if(data_get($data, 'timeframe.labels.comparison'))
          <span class="mx-2 text-white/35">|</span>
          Compare: {{ data_get($data, 'timeframe.labels.comparison') }}
        @endif
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

      <section class="min-h-[220px] cursor-move rounded-2xl border border-emerald-200/10 bg-[#0f1412]/80 p-5 shadow-[0_20px_60px_-40px_rgba(0,0,0,0.9)] backdrop-blur {{ $span }}"
        data-widget data-widget-id="{{ $id }}">
        <div class="mb-3 flex items-center justify-between gap-3">
          <h3 class="text-sm font-semibold text-white/90">{{ $title }}</h3>
          <div class="flex items-center gap-2">
            @if(in_array($id, $drilldownEnabled, true))
              <button type="button" wire:click="openDrilldown('{{ $id }}', @js($defaultState))"
                class="mf-no-drag rounded-full border border-emerald-300/30 bg-emerald-500/15 px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.2em] text-emerald-100 hover:bg-emerald-500/25">
                View details
              </button>
            @endif
            <div class="flex items-center gap-1 rounded-full border border-emerald-200/10 bg-emerald-500/5 px-1 py-0.5">
              @foreach(['1' => '1', '2' => '2', '3' => '3'] as $val => $label)
                <button type="button" wire:click="setWidgetSize('{{ $id }}','{{ $val }}')"
                  class="mf-no-drag h-6 w-6 rounded-full text-[11px] font-semibold transition {{ $size === $val ? 'bg-emerald-400/60 text-white' : 'text-emerald-100/70 hover:bg-emerald-400/20' }}">
                  {{ $label }}
                </button>
              @endforeach
            </div>
            <button type="button" wire:click="removeWidget('{{ $id }}')"
              class="mf-no-drag text-[10px] uppercase tracking-[0.2em] text-emerald-100/50 hover:text-emerald-100/80">remove</button>
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
              class="inline-flex items-center rounded-full border border-amber-300/25 bg-amber-500/10 px-3 py-1.5 text-xs font-semibold text-amber-100 hover:bg-amber-500/20">Open Mapping Resolution</a>
          </div>

        @elseif(in_array($id, ['top_scents_forecast', 'top_scents_current', 'top_scents_actual'], true))
          @php($state = str_replace('top_scents_', '', $id))
          @php($slice = $data[$state] ?? ['top_scents' => [], 'bundle' => []])
          @php($bundle = $slice['bundle'] ?? [])
          <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] {{ $stateBadge($state) }}">{{ $state }}</span>
            <div class="text-xs text-emerald-100/70">Δ Units {{ $deltaLabel(data_get($bundle, 'delta.metrics.units')) }}</div>
          </div>

          <div class="mb-3 text-xs text-white/60">
            {{ data_get($bundle, 'timeframe.labels.primary') }}
            @if(data_get($bundle, 'timeframe.labels.comparison'))
              <span class="mx-1">vs</span>{{ data_get($bundle, 'timeframe.labels.comparison') }}
            @endif
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
          @php($bundle = $data['top_oils_forecast_bundle'] ?? [])
          <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] {{ $stateBadge('forecast') }}">forecast</span>
            <div class="text-xs text-emerald-100/70">Δ Oil {{ $deltaLabel(data_get($bundle, 'delta.metrics.oil_grams')) }}g</div>
          </div>
          <div class="mb-3 text-xs text-white/60">{{ data_get($bundle, 'timeframe.labels.primary') }} @if(data_get($bundle, 'timeframe.labels.comparison')) <span class="mx-1">vs</span>{{ data_get($bundle, 'timeframe.labels.comparison') }} @endif</div>
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

        @elseif($id === 'oil_reorder_risk')
          @php($bundle = $data['reorder_risk_bundle'] ?? [])
          @php($primary = $bundle['primary'] ?? [])
          @php($riskRows = data_get($primary, 'oil.rows', []))
          @php($summary = data_get($primary, 'oil.summary', []))
          <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
            <span class="rounded-full border border-rose-300/35 bg-rose-400/20 px-2 py-0.5 text-rose-50">Reorder: {{ (int) ($summary['reorder_count'] ?? 0) }}</span>
            <span class="rounded-full border border-amber-300/35 bg-amber-400/20 px-2 py-0.5 text-amber-50">Low: {{ (int) ($summary['low_count'] ?? 0) }}</span>
            <span class="rounded-full border border-emerald-300/35 bg-emerald-400/20 px-2 py-0.5 text-emerald-50">OK: {{ (int) ($summary['ok_count'] ?? 0) }}</span>
            <span class="ml-auto text-emerald-100/70">Δ Reorder {{ $deltaLabel(data_get($bundle, 'delta.metrics.oil_reorder_count')) }}</span>
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
                    <td class="px-3 py-2 text-right"><span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $riskBadge($status) }}">{{ $status }}</span></td>
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
          @php($bundle = $data['reorder_risk_bundle'] ?? [])
          @php($primary = $bundle['primary'] ?? [])
          @php($riskRows = data_get($primary, 'wax.rows', []))
          <div class="mb-2 text-xs text-emerald-100/70">Δ Reorder {{ $deltaLabel(data_get($bundle, 'delta.metrics.wax_reorder_count')) }}</div>
          <div class="space-y-2">
            @forelse($riskRows as $row)
              @php($status = data_get($row, 'state_after_demand.status', 'ok'))
              <div class="rounded-xl border border-emerald-200/15 bg-emerald-500/5 p-3">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <div class="text-sm font-semibold text-white/90">{{ $row['name'] ?? 'Wax' }}</div>
                    <div class="mt-1 text-xs text-white/70">On hand {{ $fmt($row['on_hand_grams'] ?? 0, 1) }}g · Projected {{ $fmt($row['projected_on_hand_grams'] ?? 0, 1) }}g</div>
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
            <div class="rounded-xl border border-white/10 bg-white/5 p-3"><div class="uppercase tracking-[0.16em] text-white/55">Oils Tracked</div><div class="mt-1 text-lg font-semibold text-white">{{ (int) ($snap['oil_total_items'] ?? 0) }}</div></div>
            <div class="rounded-xl border border-amber-300/25 bg-amber-500/10 p-3"><div class="uppercase tracking-[0.16em] text-amber-100/70">Oil Low</div><div class="mt-1 text-lg font-semibold text-amber-50">{{ (int) ($snap['oil_low_count'] ?? 0) }}</div></div>
            <div class="rounded-xl border border-rose-300/25 bg-rose-500/10 p-3"><div class="uppercase tracking-[0.16em] text-rose-100/70">Oil Reorder</div><div class="mt-1 text-lg font-semibold text-rose-50">{{ (int) ($snap['oil_reorder_count'] ?? 0) }}</div></div>
            <div class="rounded-xl border border-white/10 bg-white/5 p-3"><div class="uppercase tracking-[0.16em] text-white/55">Wax On Hand</div><div class="mt-1 text-lg font-semibold text-white">{{ $fmt($snap['wax_on_hand_grams'] ?? 0, 1) }}g</div><div class="text-[11px] text-white/55">{{ $fmt($snap['wax_on_hand_boxes'] ?? 0, 2) }} × 45lb</div></div>
            <div class="rounded-xl border border-amber-300/25 bg-amber-500/10 p-3"><div class="uppercase tracking-[0.16em] text-amber-100/70">Wax Low</div><div class="mt-1 text-lg font-semibold text-amber-50">{{ (int) ($snap['wax_low_count'] ?? 0) }}</div></div>
            <div class="rounded-xl border border-rose-300/25 bg-rose-500/10 p-3"><div class="uppercase tracking-[0.16em] text-rose-100/70">Wax Reorder</div><div class="mt-1 text-lg font-semibold text-rose-50">{{ (int) ($snap['wax_reorder_count'] ?? 0) }}</div></div>
          </div>
          <a href="{{ $data['urls']['inventory'] ?? '#' }}" wire:navigate class="mt-3 inline-flex items-center rounded-full border border-emerald-300/25 bg-emerald-500/10 px-3 py-1.5 text-xs font-semibold text-emerald-100 hover:bg-emerald-500/20">Open Inventory Maintenance</a>

        @elseif($id === 'demand_state_overview')
          <div class="overflow-x-auto rounded-xl border border-emerald-200/10">
            <table class="min-w-full text-sm">
              <thead class="bg-white/5 text-white/70">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">State</th>
                  <th class="px-3 py-2 text-right font-medium">Units</th>
                  <th class="px-3 py-2 text-right font-medium">Wax g</th>
                  <th class="px-3 py-2 text-right font-medium">Oil g</th>
                  <th class="px-3 py-2 text-right font-medium">Δ Units</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/10">
                @foreach(($data['state_totals'] ?? []) as $row)
                  <tr class="hover:bg-white/5">
                    <td class="px-3 py-2"><span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] {{ $stateBadge($row['state'] ?? '') }}">{{ $row['state'] ?? 'unknown' }}</span></td>
                    <td class="px-3 py-2 text-right text-white/85">{{ $fmt($row['units'] ?? 0) }}</td>
                    <td class="px-3 py-2 text-right text-white/85">{{ $fmt($row['wax_grams'] ?? 0, 1) }}</td>
                    <td class="px-3 py-2 text-right text-white/85">{{ $fmt($row['oil_grams'] ?? 0, 1) }}</td>
                    <td class="px-3 py-2 text-right text-white/75">{{ $deltaLabel($row['delta'] ?? null) }}</td>
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

  @if($showDrilldown && !empty($drilldown))
    @php($trendBars = function (array $series) {
      $max = max(1, (float) collect($series)->max('value'));
      return ['max' => $max, 'series' => $series];
    })
    <div class="fixed inset-0 z-[120]">
      <div class="absolute inset-0 bg-black/60" wire:click="closeDrilldown"></div>
      <aside class="absolute right-0 top-0 h-full w-full max-w-3xl overflow-y-auto border-l border-emerald-200/15 bg-[#0d1311]/95 p-6 shadow-[-30px_0_80px_-40px_rgba(0,0,0,0.95)]">
        <div class="flex items-start justify-between gap-4">
          <div>
            <div class="text-[11px] uppercase tracking-[0.3em] text-emerald-100/60">Drilldown Detail</div>
            <h3 class="mt-2 text-2xl font-['Fraunces'] font-semibold text-white">{{ $drilldown['title'] ?? 'Detail' }}</h3>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-emerald-100/75">
              @if(!empty($drilldown['state']) && !in_array($drilldown['state'], ['mixed', ''], true))
                <span class="inline-flex items-center rounded-full border px-2 py-0.5 font-semibold uppercase tracking-[0.16em] {{ $stateBadge($drilldown['state']) }}">State: {{ $drilldown['state'] }}</span>
              @endif
              <span>Primary: {{ data_get($drilldown, 'labels.primary') }}</span>
              @if(data_get($drilldown, 'labels.comparison'))
                <span class="text-white/35">|</span>
                <span>Compare: {{ data_get($drilldown, 'labels.comparison') }}</span>
              @endif
            </div>
          </div>
          <button type="button" wire:click="closeDrilldown" class="rounded-full border border-white/20 bg-white/10 px-4 py-2 text-sm font-semibold text-white hover:bg-white/20">Close</button>
        </div>

        @if(!empty($drilldown['actions']))
          <div class="mt-4 flex flex-wrap gap-2">
            @foreach(($drilldown['actions'] ?? []) as $action)
              <a href="{{ $action['url'] ?? '#' }}" wire:navigate class="inline-flex items-center rounded-full border border-emerald-300/30 bg-emerald-500/15 px-3 py-1.5 text-xs font-semibold text-emerald-100 hover:bg-emerald-500/25">{{ $action['label'] ?? 'Open' }}</a>
            @endforeach
          </div>
        @endif

        <div class="mt-6 space-y-6">
          @if(($drilldown['widget'] ?? '') === 'unmapped_exceptions')
            @php($summary = $drilldown['summary'] ?? [])
            @php($rows = data_get($drilldown, 'details.rows', []))
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div class="rounded-xl border border-amber-200/20 bg-amber-500/10 p-3">
                <div class="text-xs uppercase tracking-[0.2em] text-amber-100/75">Open Exceptions</div>
                <div class="mt-1 text-xl font-semibold text-white">{{ (int) ($summary['open_count'] ?? 0) }}</div>
              </div>
              @foreach(array_slice((array) ($summary['by_channel'] ?? []), 0, 2) as $channelRow)
                <div class="rounded-xl border border-white/10 bg-white/5 p-3">
                  <div class="text-xs uppercase tracking-[0.2em] text-white/65">{{ strtoupper((string) ($channelRow['channel'] ?? 'unknown')) }}</div>
                  <div class="mt-1 text-xl font-semibold text-white">{{ (int) ($channelRow['open_count'] ?? 0) }}</div>
                </div>
              @endforeach
            </div>

            <div class="overflow-x-auto rounded-xl border border-emerald-200/10">
              <table class="min-w-full text-sm">
                <thead class="bg-white/5 text-white/70">
                  <tr>
                    <th class="px-3 py-2 text-left font-medium">Raw Name</th>
                    <th class="px-3 py-2 text-left font-medium">Variant</th>
                    <th class="px-3 py-2 text-left font-medium">Account/Store</th>
                    <th class="px-3 py-2 text-left font-medium">First Seen</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                  @forelse($rows as $row)
                    <tr>
                      <td class="px-3 py-2 text-white/85">{{ $row['raw_name'] ?? 'Unknown' }}</td>
                      <td class="px-3 py-2 text-white/70">{{ $row['raw_variant'] ?: '—' }}</td>
                      <td class="px-3 py-2 text-white/70">{{ $row['account_name'] ?: ($row['store_key'] ?? '—') }}</td>
                      <td class="px-3 py-2 text-white/70">{{ $row['created_at'] ?? '—' }}</td>
                    </tr>
                  @empty
                    <tr><td class="px-3 py-4 text-white/55" colspan="4">No unresolved rows.</td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>

            @php($trend = $trendBars((array) ($drilldown['trend'] ?? [])))
            <div>
              <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/70">Unmapped Exceptions Trend</div>
              <div class="mt-3 space-y-2">
                @foreach($trend['series'] as $point)
                  <div class="flex items-center gap-3 text-xs">
                    <div class="w-32 shrink-0 text-white/65">{{ $point['label'] }}</div>
                    <div class="h-2 flex-1 rounded-full bg-white/10">
                      <div class="h-2 rounded-full bg-amber-400/70" style="width: {{ min(100, ((float) ($point['value'] ?? 0) / $trend['max']) * 100) }}%"></div>
                    </div>
                    <div class="w-10 text-right text-white/85">{{ (int) ($point['value'] ?? 0) }}</div>
                  </div>
                @endforeach
              </div>
            </div>

          @elseif(($drilldown['widget'] ?? '') === 'oil_reorder_risk')
            @php($bundle = $drilldown['bundle'] ?? [])
            @php($riskRows = data_get($bundle, 'primary.oil.rows', []))
            @php($contributors = collect(data_get($drilldown, 'contributors.primary.rows', []))->keyBy('base_oil_id'))
            <div class="overflow-x-auto rounded-xl border border-emerald-200/10">
              <table class="min-w-full text-sm">
                <thead class="bg-white/5 text-white/70">
                  <tr>
                    <th class="px-3 py-2 text-left font-medium">Oil</th>
                    <th class="px-3 py-2 text-right font-medium">Demand g</th>
                    <th class="px-3 py-2 text-right font-medium">On Hand g</th>
                    <th class="px-3 py-2 text-right font-medium">Projected g</th>
                    <th class="px-3 py-2 text-right font-medium">Threshold g</th>
                    <th class="px-3 py-2 text-right font-medium">Risk</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                  @forelse($riskRows as $row)
                    @php($status = data_get($row, 'state_after_demand.status', 'ok'))
                    @php($oilContrib = data_get($contributors->get((int) ($row['base_oil_id'] ?? 0), []), 'contributors', []))
                    <tr>
                      <td class="px-3 py-2 text-white/90">
                        <div>{{ $row['name'] ?? 'Unknown' }}</div>
                        @if(!empty($oilContrib))
                          <div class="mt-1 text-[11px] text-white/60">Top scent drivers:
                            {{ collect($oilContrib)->take(3)->map(fn($x) => ($x['scent_name'] ?? 'Unknown').' '.number_format((float)($x['grams'] ?? 0), 1).'g')->implode(' · ') }}
                          </div>
                        @endif
                      </td>
                      <td class="px-3 py-2 text-right text-white/80">{{ $fmt($row['demand_grams'] ?? 0, 1) }}</td>
                      <td class="px-3 py-2 text-right text-white/80">{{ $fmt($row['on_hand_grams'] ?? 0, 1) }}</td>
                      <td class="px-3 py-2 text-right text-white/80">{{ $fmt($row['projected_on_hand_grams'] ?? 0, 1) }}</td>
                      <td class="px-3 py-2 text-right text-white/80">{{ $fmt($row['reorder_threshold_grams'] ?? 0, 1) }}</td>
                      <td class="px-3 py-2 text-right"><span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $riskBadge($status) }}">{{ $status }}</span></td>
                    </tr>
                  @empty
                    <tr><td class="px-3 py-4 text-white/55" colspan="6">No oil risk rows.</td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>

            @php($trend = $trendBars((array) ($drilldown['trend'] ?? [])))
            <div>
              <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/70">Current Oil Demand Trend</div>
              <div class="mt-3 space-y-2">
                @foreach($trend['series'] as $point)
                  <div class="flex items-center gap-3 text-xs">
                    <div class="w-32 shrink-0 text-white/65">{{ $point['label'] }}</div>
                    <div class="h-2 flex-1 rounded-full bg-white/10">
                      <div class="h-2 rounded-full bg-emerald-400/70" style="width: {{ min(100, ((float) ($point['value'] ?? 0) / $trend['max']) * 100) }}%"></div>
                    </div>
                    <div class="w-14 text-right text-white/85">{{ $fmt($point['value'] ?? 0, 1) }}g</div>
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
                <div class="rounded-xl border border-emerald-200/12 bg-emerald-500/5 p-4">
                  <div class="flex items-start justify-between gap-3">
                    <div>
                      <div class="text-sm font-semibold text-white/90">{{ $row['name'] ?? 'Wax' }}</div>
                      <div class="mt-1 text-xs text-white/70">
                        On hand {{ $fmt($row['on_hand_grams'] ?? 0, 1) }}g ({{ $fmt(((float)($row['on_hand_grams'] ?? 0)) / $gramsPerBox, 2) }} boxes)
                        · Projected {{ $fmt($row['projected_on_hand_grams'] ?? 0, 1) }}g ({{ $fmt(((float)($row['projected_on_hand_grams'] ?? 0)) / $gramsPerBox, 2) }} boxes)
                      </div>
                      <div class="mt-1 text-xs text-white/60">
                        Threshold {{ $fmt($row['reorder_threshold_grams'] ?? 0, 1) }}g ({{ $fmt(((float)($row['reorder_threshold_grams'] ?? 0)) / $gramsPerBox, 2) }} boxes)
                        · Demand {{ $fmt($row['demand_grams'] ?? 0, 1) }}g
                      </div>
                    </div>
                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $riskBadge($status) }}">{{ $status }}</span>
                  </div>
                </div>
              @empty
                <div class="text-sm text-white/55">No wax risk rows.</div>
              @endforelse
            </div>

            @php($trend = $trendBars((array) ($drilldown['trend'] ?? [])))
            <div>
              <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/70">Current Wax Demand Trend</div>
              <div class="mt-3 space-y-2">
                @foreach($trend['series'] as $point)
                  <div class="flex items-center gap-3 text-xs">
                    <div class="w-32 shrink-0 text-white/65">{{ $point['label'] }}</div>
                    <div class="h-2 flex-1 rounded-full bg-white/10">
                      <div class="h-2 rounded-full bg-emerald-400/70" style="width: {{ min(100, ((float) ($point['value'] ?? 0) / $trend['max']) * 100) }}%"></div>
                    </div>
                    <div class="w-14 text-right text-white/85">{{ $fmt($point['value'] ?? 0, 1) }}g</div>
                  </div>
                @endforeach
              </div>
            </div>

          @elseif(in_array(($drilldown['widget'] ?? ''), ['top_scents_forecast','top_scents_current','top_scents_actual'], true))
            @php($bundle = $drilldown['bundle'] ?? [])
            @php($rows = data_get($bundle, 'primary.rows', []))
            <div class="mb-2 text-sm text-white/70">Δ Units {{ $deltaLabel(data_get($bundle, 'delta.metrics.units')) }}</div>
            <div class="overflow-x-auto rounded-xl border border-emerald-200/10">
              <table class="min-w-full text-sm">
                <thead class="bg-white/5 text-white/70">
                  <tr>
                    <th class="px-3 py-2 text-left font-medium">Scent</th>
                    <th class="px-3 py-2 text-left font-medium">Channel</th>
                    <th class="px-3 py-2 text-right font-medium">Units</th>
                    <th class="px-3 py-2 text-right font-medium">Wax g</th>
                    <th class="px-3 py-2 text-right font-medium">Oil g</th>
                    <th class="px-3 py-2 text-right font-medium">Rows</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                  @forelse($rows as $row)
                    <tr>
                      <td class="px-3 py-2 text-white/90">{{ $row['scent_name'] ?? 'Unknown' }}</td>
                      <td class="px-3 py-2 text-white/70">{{ $row['channel'] ?? '—' }}</td>
                      <td class="px-3 py-2 text-right text-white/80">{{ $fmt($row['units'] ?? 0) }}</td>
                      <td class="px-3 py-2 text-right text-white/80">{{ $fmt($row['wax_grams'] ?? 0, 1) }}</td>
                      <td class="px-3 py-2 text-right text-white/80">{{ $fmt($row['oil_grams'] ?? 0, 1) }}</td>
                      <td class="px-3 py-2 text-right text-white/80">{{ $fmt($row['order_count'] ?? 0) }}</td>
                    </tr>
                  @empty
                    <tr><td class="px-3 py-4 text-white/55" colspan="6">No scent rows.</td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>

            @php($trend = $trendBars((array) ($drilldown['trend'] ?? [])))
            <div>
              <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/70">Scent Demand Trend</div>
              <div class="mt-3 space-y-2">
                @foreach($trend['series'] as $point)
                  <div class="flex items-center gap-3 text-xs">
                    <div class="w-32 shrink-0 text-white/65">{{ $point['label'] }}</div>
                    <div class="h-2 flex-1 rounded-full bg-white/10">
                      <div class="h-2 rounded-full bg-cyan-400/70" style="width: {{ min(100, ((float) ($point['value'] ?? 0) / $trend['max']) * 100) }}%"></div>
                    </div>
                    <div class="w-14 text-right text-white/85">{{ $fmt($point['value'] ?? 0) }}</div>
                  </div>
                @endforeach
              </div>
            </div>

          @elseif(($drilldown['widget'] ?? '') === 'top_oils_forecast')
            @php($bundle = $drilldown['bundle'] ?? [])
            @php($rows = data_get($bundle, 'primary.rows', []))
            @php($contributors = data_get($drilldown, 'contributors.primary.rows', []))
            <div class="mb-2 text-sm text-white/70">Δ Oil {{ $deltaLabel(data_get($bundle, 'delta.metrics.oil_grams')) }}g</div>
            <div class="overflow-x-auto rounded-xl border border-emerald-200/10">
              <table class="min-w-full text-sm">
                <thead class="bg-white/5 text-white/70">
                  <tr>
                    <th class="px-3 py-2 text-left font-medium">Oil</th>
                    <th class="px-3 py-2 text-right font-medium">Grams</th>
                    <th class="px-3 py-2 text-right font-medium">% of Total</th>
                    <th class="px-3 py-2 text-right font-medium">Action</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                  @forelse($rows as $row)
                    <tr>
                      <td class="px-3 py-2 text-white/90">{{ $row['base_oil_name'] ?? 'Unknown' }}</td>
                      <td class="px-3 py-2 text-right text-white/80">{{ $fmt($row['grams'] ?? 0, 1) }}</td>
                      <td class="px-3 py-2 text-right text-white/80">{{ $fmt($row['percent_of_total'] ?? 0, 2) }}%</td>
                      <td class="px-3 py-2 text-right">
                        <button type="button" wire:click="openDrilldown('top_oils_forecast','forecast',{{ (int) ($row['base_oil_id'] ?? 0) }})" class="rounded-full border border-emerald-300/30 bg-emerald-500/10 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-100 hover:bg-emerald-500/20">Focus</button>
                      </td>
                    </tr>
                  @empty
                    <tr><td class="px-3 py-4 text-white/55" colspan="4">No oil rows.</td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>

            <div class="space-y-2">
              <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/70">Oil Contributors</div>
              @forelse($contributors as $oilRow)
                <div class="rounded-xl border border-emerald-200/12 bg-emerald-500/5 p-3">
                  <div class="text-sm font-semibold text-white/90">{{ $oilRow['base_oil_name'] ?? 'Oil' }} · {{ $fmt($oilRow['total_grams'] ?? 0, 1) }}g</div>
                  <div class="mt-1 text-xs text-white/70">
                    {{ collect($oilRow['contributors'] ?? [])->map(fn($x) => ($x['scent_name'] ?? 'Unknown').' '.number_format((float)($x['grams'] ?? 0), 1).'g')->implode(' · ') ?: 'No contributors' }}
                  </div>
                </div>
              @empty
                <div class="text-sm text-white/55">No contributor rows.</div>
              @endforelse
            </div>

            @php($trend = $trendBars((array) ($drilldown['trend'] ?? [])))
            <div>
              <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/70">Forecast Oil Trend</div>
              <div class="mt-3 space-y-2">
                @foreach($trend['series'] as $point)
                  <div class="flex items-center gap-3 text-xs">
                    <div class="w-32 shrink-0 text-white/65">{{ $point['label'] }}</div>
                    <div class="h-2 flex-1 rounded-full bg-white/10">
                      <div class="h-2 rounded-full bg-sky-400/70" style="width: {{ min(100, ((float) ($point['value'] ?? 0) / $trend['max']) * 100) }}%"></div>
                    </div>
                    <div class="w-14 text-right text-white/85">{{ $fmt($point['value'] ?? 0, 1) }}g</div>
                  </div>
                @endforeach
              </div>
            </div>

          @elseif(($drilldown['widget'] ?? '') === 'demand_state_overview')
            @php($states = $drilldown['states'] ?? [])
            <div class="overflow-x-auto rounded-xl border border-emerald-200/10">
              <table class="min-w-full text-sm">
                <thead class="bg-white/5 text-white/70">
                  <tr>
                    <th class="px-3 py-2 text-left font-medium">State</th>
                    <th class="px-3 py-2 text-right font-medium">Units</th>
                    <th class="px-3 py-2 text-right font-medium">Wax g</th>
                    <th class="px-3 py-2 text-right font-medium">Oil g</th>
                    <th class="px-3 py-2 text-right font-medium">Δ Units</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                  @foreach(['forecast','current','actual'] as $stateKey)
                    @php($bundle = $states[$stateKey] ?? [])
                    <tr>
                      <td class="px-3 py-2"><span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] {{ $stateBadge($stateKey) }}">{{ $stateKey }}</span></td>
                      <td class="px-3 py-2 text-right text-white/85">{{ $fmt(data_get($bundle, 'primary.totals.units', 0)) }}</td>
                      <td class="px-3 py-2 text-right text-white/85">{{ $fmt(data_get($bundle, 'primary.totals.wax_grams', 0), 1) }}</td>
                      <td class="px-3 py-2 text-right text-white/85">{{ $fmt(data_get($bundle, 'primary.totals.oil_grams', 0), 1) }}</td>
                      <td class="px-3 py-2 text-right text-white/75">{{ $deltaLabel(data_get($bundle, 'delta.metrics.units')) }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
              @foreach(['forecast','current','actual'] as $stateKey)
                @php($trend = $trendBars((array) data_get($drilldown, 'trend.'.$stateKey, [])))
                <div>
                  <div class="text-xs uppercase tracking-[0.2em] text-emerald-100/65">{{ $stateKey }} trend</div>
                  <div class="mt-2 space-y-2">
                    @foreach($trend['series'] as $point)
                      <div class="flex items-center gap-2 text-[11px]">
                        <div class="w-16 shrink-0 text-white/55">{{ $point['from'] }}</div>
                        <div class="h-2 flex-1 rounded-full bg-white/10">
                          <div class="h-2 rounded-full bg-emerald-400/70" style="width: {{ min(100, ((float) ($point['value'] ?? 0) / $trend['max']) * 100) }}%"></div>
                        </div>
                        <div class="w-8 text-right text-white/80">{{ (int) ($point['value'] ?? 0) }}</div>
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
