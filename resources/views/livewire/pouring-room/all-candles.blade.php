@php
  $channelLabels = [
    'event' => 'Market',
    'retail' => 'Retail',
    'wholesale' => 'Wholesale',
  ];
  $statusStyle = [
    'queued' => 'border-zinc-300 bg-zinc-50 text-zinc-700',
    'laid_out' => 'border-cyan-300/35 bg-cyan-500/20 text-cyan-50',
    'first_pour' => 'border-indigo-300/35 bg-indigo-500/20 text-indigo-50',
    'second_pour' => 'border-violet-300/35 bg-violet-500/20 text-violet-50',
    'waiting_on_oil' => 'border-amber-300/35 bg-amber-100 text-amber-900',
    'brought_down' => 'border-zinc-300 bg-emerald-100 text-emerald-900',
    'mixed' => 'border-fuchsia-300/35 bg-fuchsia-500/20 text-fuchsia-50',
  ];
  $stateLabels = [
    'all' => 'All states',
    'current' => 'Current',
    'actual' => 'Actual',
  ];
@endphp

<div class="space-y-5">
  <div class="flex flex-wrap items-center gap-2 text-[11px] text-emerald-800">
    <a href="{{ route('pouring.index') }}" class="rounded-full border border-zinc-200 bg-emerald-100 px-3 py-1 hover:bg-emerald-100">Pouring Room</a>
    <span class="rounded-full border border-zinc-200 bg-emerald-100 px-3 py-1 text-zinc-800">All Candles</span>
  </div>
  <livewire:pouring-room.dashboard-bar />

  <section class="rounded-3xl border border-zinc-200 bg-white p-5">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Factory Batch Planner</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">Aggregate Pouring by Scent</div>
    <div class="mt-2 text-sm text-zinc-600">Merged production scope with warnings, queue priority, and scent-level drilldown.</div>

    <div class="mt-4 grid gap-3 xl:grid-cols-[1.4fr_1fr]">
      <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3">
        <div class="text-[11px] uppercase tracking-[0.25em] text-emerald-800">Batch Mode</div>
        <div class="mt-2 flex flex-wrap gap-2">
          <button
            type="button"
            wire:click="$set('batchMode','by_market')"
            class="rounded-full border px-3 py-1.5 text-xs {{ $batchMode==='by_market' ? 'border-zinc-300 bg-emerald-400/25 text-emerald-900' : 'border-emerald-400/15 bg-emerald-50 text-zinc-700' }}"
          >
            By Market
          </button>
          <button
            type="button"
            wire:click="$set('batchMode','all_markets_combined')"
            class="rounded-full border px-3 py-1.5 text-xs {{ $batchMode==='all_markets_combined' ? 'border-zinc-300 bg-emerald-400/25 text-emerald-900' : 'border-emerald-400/15 bg-emerald-50 text-zinc-700' }}"
          >
            All Markets Combined
          </button>
        </div>
      </div>

      <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3">
        <label for="pouring-sort" class="text-[11px] uppercase tracking-[0.25em] text-emerald-800">Sort By</label>
        <select
          id="pouring-sort"
          wire:model.live="sortBy"
          class="mt-2 h-10 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 text-sm text-zinc-900"
        >
          <option value="most_wax">Most Wax</option>
          <option value="most_pitchers">Most Pitchers</option>
          <option value="most_units">Most Units</option>
          <option value="earliest_due">Earliest Due</option>
          <option value="markets_first">Markets First</option>
          <option value="retail_first">Retail First</option>
          <option value="wholesale_first">Wholesale First</option>
        </select>
      </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-2">
      @foreach(['all' => 'All', 'retail' => 'Retail', 'wholesale' => 'Wholesale', 'event' => 'Market'] as $key => $label)
        <button wire:click="$set('channel','{{ $key }}')" class="rounded-full border px-3 py-1.5 text-xs {{ $channel===$key ? 'border-zinc-300 bg-emerald-400/25 text-emerald-900' : 'border-emerald-400/15 bg-emerald-50 text-zinc-700' }}">{{ $label }}</button>
      @endforeach
      @foreach($stateLabels as $key => $label)
        <button wire:click="$set('state','{{ $key }}')" class="rounded-full border px-3 py-1.5 text-xs {{ $state===$key ? 'border-sky-300/35 bg-sky-500/25 text-sky-900' : 'border-sky-400/15 bg-sky-500/5 text-zinc-700' }}">{{ $label }}</button>
      @endforeach
      @foreach(['3' => 'Next 3 days', '7' => 'Next 7 days', '14' => 'Next 14 days', 'all' => 'All Due'] as $key => $label)
        <button wire:click="$set('dueWindow','{{ $key }}')" class="rounded-full border px-3 py-1.5 text-xs {{ $dueWindow===$key ? 'border-zinc-300 bg-emerald-400/25 text-emerald-900' : 'border-emerald-400/15 bg-emerald-50 text-zinc-700' }}">{{ $label }}</button>
      @endforeach
    </div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-white p-5">
    <div class="text-[11px] uppercase tracking-[0.3em] text-emerald-800">Next Pour Queue</div>
    <div class="mt-2 text-sm text-zinc-600">Auto-prioritized by due date, then wax load, then pitcher load.</div>
    <div class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-5">
      @forelse($nextQueue as $index => $queue)
        <button
          type="button"
          wire:click="selectRow('{{ $queue['key'] }}')"
          class="rounded-xl border px-3 py-2 text-left transition {{ $selectedRowKey === $queue['key'] ? 'border-emerald-300/30 bg-emerald-100' : 'border-zinc-200 bg-zinc-50 hover:bg-zinc-50' }}"
        >
          <div class="text-[10px] uppercase tracking-[0.2em] text-emerald-800">#{{ $index + 1 }}</div>
          <div class="mt-1 truncate text-sm font-semibold text-zinc-950">{{ $queue['scent_label'] }}</div>
          <div class="mt-1 text-[11px] text-emerald-800">{{ (int)($queue['units'] ?? 0) }} units · {{ (int)($queue['pitchers'] ?? 0) }} pitchers</div>
          <div class="text-[11px] text-emerald-800">{{ rtrim(rtrim(number_format((float)($queue['wax_grams'] ?? 0), 1), '0'), '.') }}g wax</div>
        </button>
      @empty
        <div class="col-span-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-3 text-xs text-zinc-600">No queue recommendations for current filters.</div>
      @endforelse
    </div>
  </section>

  <section class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_350px]">
    <div class="rounded-3xl border border-zinc-200 bg-white p-4">
      <div class="rounded-2xl border border-zinc-200 overflow-hidden">
        <div class="grid grid-cols-[92px_minmax(0,1.8fr)_68px_minmax(0,1.6fr)_88px_88px_74px_96px] gap-2 border-b border-zinc-200 bg-zinc-50 px-3 py-2 text-[10px] uppercase tracking-[0.2em] text-zinc-500">
          <div>Status</div>
          <div>Scent</div>
          <div>Units</div>
          <div>Sizes</div>
          <div>Wax g</div>
          <div>Oil g</div>
          <div>Pitchers</div>
          <div>Earliest Due</div>
        </div>

        <div class="divide-y divide-emerald-200/10">
          @forelse($rows as $row)
            @php
              $status = (string)($row['status'] ?? 'queued');
              $isSelected = (string)($selectedRowKey ?? '') === (string)($row['key'] ?? '');
              $marketLabel = trim((string)($row['market_label'] ?? ''));
              $due = $row['earliest_due'] ?? null;
            @endphp
            <button
              type="button"
              wire:click="selectRow('{{ $row['key'] }}')"
              class="grid w-full grid-cols-[92px_minmax(0,1.8fr)_68px_minmax(0,1.6fr)_88px_88px_74px_96px] gap-2 px-3 py-1.5 text-left text-xs transition {{ $isSelected ? 'bg-emerald-100' : 'hover:bg-zinc-50' }}"
            >
              <div class="flex items-center">
                <span class="inline-flex rounded-full border px-2 py-0.5 text-[10px] leading-tight {{ $statusStyle[$status] ?? $statusStyle['mixed'] }}">{{ $row['status_label'] }}</span>
              </div>

              <div class="min-w-0">
                <div class="truncate font-semibold text-zinc-950">{{ $row['scent_label'] }}</div>
                <div class="truncate text-[10px] text-emerald-800">
                  {{ $channelLabels[$row['primary_channel'] ?? 'retail'] ?? 'Retail' }}
                  @if($batchMode === 'by_market' && $marketLabel !== '') · {{ $marketLabel }} @endif
                </div>
                @if(!empty($row['warnings']))
                  <div class="mt-0.5 flex flex-wrap items-center gap-1 text-[10px] text-amber-800">
                    @foreach($row['warnings'] as $warning)
                      <span title="{{ $warning['label'] ?? '' }}">{{ $warning['icon'] ?? '⚠' }}</span>
                    @endforeach
                  </div>
                @endif
              </div>

              <div class="self-center font-semibold text-zinc-950">{{ (int)($row['units'] ?? 0) }}</div>
              <div class="truncate self-center text-zinc-700">{{ $row['size_summary'] ?: '—' }}</div>
              <div class="self-center font-medium text-zinc-900">{{ rtrim(rtrim(number_format((float)($row['wax_grams'] ?? 0), 1), '0'), '.') }}</div>
              <div class="self-center font-medium text-zinc-900">{{ rtrim(rtrim(number_format((float)($row['oil_grams'] ?? 0), 1), '0'), '.') }}</div>
              <div class="self-center font-medium text-zinc-900">{{ (int)($row['pitchers'] ?? 0) }}</div>
              <div class="self-center text-zinc-700">{{ $due ? $due->format('M j') : '—' }}</div>
            </button>
          @empty
            <div class="px-3 py-4 text-xs text-zinc-500">No scent rows found for these filters.</div>
          @endforelse
        </div>
      </div>
    </div>

    <aside class="rounded-3xl border border-zinc-200 bg-white p-4">
      <div class="text-[11px] uppercase tracking-[0.25em] text-emerald-800">Batch Detail</div>

      @if($selectedRow)
        <div class="mt-2 text-xl font-['Fraunces'] font-semibold text-zinc-950">{{ $selectedRow['scent_label'] }}</div>
        @if($batchMode === 'by_market' && !empty($selectedRow['market_label']))
          <div class="text-xs text-emerald-800">{{ $selectedRow['market_label'] }}</div>
        @endif

        <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
          <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
            <div class="text-emerald-800">Total Wax</div>
            <div class="text-base font-semibold text-zinc-950">{{ rtrim(rtrim(number_format((float)($selectedRow['wax_grams'] ?? 0), 1), '0'), '.') }}g</div>
          </div>
          <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
            <div class="text-emerald-800">Total Oil</div>
            <div class="text-base font-semibold text-zinc-950">{{ rtrim(rtrim(number_format((float)($selectedRow['oil_grams'] ?? 0), 1), '0'), '.') }}g</div>
          </div>
          <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
            <div class="text-emerald-800">Total Pitchers</div>
            <div class="text-base font-semibold text-zinc-950">{{ (int)($selectedRow['pitchers'] ?? 0) }}</div>
          </div>
          <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
            <div class="text-emerald-800">Total Units</div>
            <div class="text-base font-semibold text-zinc-950">{{ (int)($selectedRow['units'] ?? 0) }}</div>
          </div>
        </div>

        <div class="mt-4 text-[10px] uppercase tracking-[0.2em] text-emerald-800">Size Breakdown</div>
        <div class="mt-2 rounded-xl border border-zinc-200 overflow-hidden">
          <div class="grid grid-cols-[1.2fr_56px_86px_74px] gap-2 border-b border-zinc-200 bg-zinc-50 px-3 py-1.5 text-[10px] uppercase tracking-[0.16em] text-zinc-500">
            <div>Size</div>
            <div>Qty</div>
            <div>Wax/Oil</div>
            <div>Pitchers</div>
          </div>
          <div class="divide-y divide-emerald-200/10">
            @foreach(($selectedRow['size_rows'] ?? []) as $sizeRow)
              <div class="grid grid-cols-[1.2fr_56px_86px_74px] gap-2 px-3 py-1.5 text-xs text-zinc-800">
                <div class="truncate">{{ $sizeRow['size_label'] ?? 'Unknown' }}</div>
                <div>{{ (int)($sizeRow['qty'] ?? 0) }}</div>
                <div class="text-[11px]">{{ rtrim(rtrim(number_format((float)($sizeRow['wax_grams'] ?? 0), 1), '0'), '.') }}/{{ rtrim(rtrim(number_format((float)($sizeRow['oil_grams'] ?? 0), 1), '0'), '.') }}g</div>
                <div>{{ (int)($sizeRow['pitchers'] ?? 0) }}</div>
              </div>
            @endforeach
          </div>
        </div>

        <div class="mt-4 text-[10px] uppercase tracking-[0.2em] text-emerald-800">Oil Info</div>
        <div class="mt-2 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-800">
          <div class="font-semibold text-zinc-950">{{ $selectedRow['oil_name'] ?? '—' }}</div>
          <div class="mt-1 text-emerald-800">Oil grams: {{ rtrim(rtrim(number_format((float)($selectedRow['oil_grams'] ?? 0), 1), '0'), '.') }}g</div>

          @if(count($selectedRow['recipe_components'] ?? []) > 1)
            <details class="mt-2 rounded-lg border border-zinc-200 bg-zinc-50 px-2.5 py-2">
              <summary class="cursor-pointer text-[11px] text-emerald-800">Blend breakdown</summary>
              <div class="mt-2 space-y-1 text-[11px] text-zinc-600">
                @foreach(($selectedRow['recipe_components'] ?? []) as $component)
                  <div>{{ $component['oil'] ?? 'Oil' }} · {{ $component['ratio'] ?? '—' }}</div>
                @endforeach
              </div>
            </details>
          @endif
        </div>
      @else
        <div class="mt-3 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-3 text-xs text-zinc-600">Select a scent row to inspect batch details.</div>
      @endif
    </aside>
  </section>
</div>
