@php
  $channelLabels = [
    'event' => 'Market',
    'retail' => 'Retail',
    'wholesale' => 'Wholesale',
  ];
  $statusStyle = [
    'queued' => 'border-white/15 bg-white/5 text-white/80',
    'laid_out' => 'border-cyan-300/35 bg-cyan-500/20 text-cyan-50',
    'first_pour' => 'border-indigo-300/35 bg-indigo-500/20 text-indigo-50',
    'second_pour' => 'border-violet-300/35 bg-violet-500/20 text-violet-50',
    'waiting_on_oil' => 'border-amber-300/35 bg-amber-500/20 text-amber-50',
    'brought_down' => 'border-emerald-300/35 bg-emerald-500/20 text-emerald-50',
    'mixed' => 'border-fuchsia-300/35 bg-fuchsia-500/20 text-fuchsia-50',
  ];
  $stateLabels = [
    'all' => 'All states',
    'current' => 'Current',
    'actual' => 'Actual',
  ];
@endphp

<div class="space-y-5">
  <div class="flex flex-wrap items-center gap-2 text-[11px] text-emerald-100/70">
    <a href="{{ route('pouring.index') }}" class="rounded-full border border-emerald-200/15 bg-emerald-500/10 px-3 py-1 hover:bg-emerald-500/20">Pouring Room</a>
    <span class="rounded-full border border-emerald-200/15 bg-emerald-500/20 px-3 py-1 text-white/85">All Candles</span>
  </div>
  <livewire:pouring-room.dashboard-bar />

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Factory Batch Planner</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Aggregate Pouring by Scent</div>
    <div class="mt-2 text-sm text-emerald-50/70">Merged production scope with warnings, queue priority, and scent-level drilldown.</div>

    <div class="mt-4 grid gap-3 xl:grid-cols-[1.4fr_1fr]">
      <div class="rounded-2xl border border-emerald-200/10 bg-black/20 p-3">
        <div class="text-[11px] uppercase tracking-[0.25em] text-emerald-100/65">Batch Mode</div>
        <div class="mt-2 flex flex-wrap gap-2">
          <button
            type="button"
            wire:click="$set('batchMode','by_market')"
            class="rounded-full border px-3 py-1.5 text-xs {{ $batchMode==='by_market' ? 'border-emerald-300/35 bg-emerald-400/25 text-emerald-50' : 'border-emerald-400/15 bg-emerald-500/5 text-white/80' }}"
          >
            By Market
          </button>
          <button
            type="button"
            wire:click="$set('batchMode','all_markets_combined')"
            class="rounded-full border px-3 py-1.5 text-xs {{ $batchMode==='all_markets_combined' ? 'border-emerald-300/35 bg-emerald-400/25 text-emerald-50' : 'border-emerald-400/15 bg-emerald-500/5 text-white/80' }}"
          >
            All Markets Combined
          </button>
        </div>
      </div>

      <div class="rounded-2xl border border-emerald-200/10 bg-black/20 p-3">
        <label for="pouring-sort" class="text-[11px] uppercase tracking-[0.25em] text-emerald-100/65">Sort By</label>
        <select
          id="pouring-sort"
          wire:model.live="sortBy"
          class="mt-2 h-10 w-full rounded-xl border border-emerald-200/20 bg-black/30 px-3 text-sm text-white/90"
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
        <button wire:click="$set('channel','{{ $key }}')" class="rounded-full border px-3 py-1.5 text-xs {{ $channel===$key ? 'border-emerald-300/35 bg-emerald-400/25 text-emerald-50' : 'border-emerald-400/15 bg-emerald-500/5 text-white/80' }}">{{ $label }}</button>
      @endforeach
      @foreach($stateLabels as $key => $label)
        <button wire:click="$set('state','{{ $key }}')" class="rounded-full border px-3 py-1.5 text-xs {{ $state===$key ? 'border-sky-300/35 bg-sky-500/25 text-sky-50' : 'border-sky-400/15 bg-sky-500/5 text-white/80' }}">{{ $label }}</button>
      @endforeach
      @foreach(['3' => 'Next 3 days', '7' => 'Next 7 days', '14' => 'Next 14 days', 'all' => 'All Due'] as $key => $label)
        <button wire:click="$set('dueWindow','{{ $key }}')" class="rounded-full border px-3 py-1.5 text-xs {{ $dueWindow===$key ? 'border-emerald-300/35 bg-emerald-400/25 text-emerald-50' : 'border-emerald-400/15 bg-emerald-500/5 text-white/80' }}">{{ $label }}</button>
      @endforeach
    </div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5">
    <div class="text-[11px] uppercase tracking-[0.3em] text-emerald-100/60">Next Pour Queue</div>
    <div class="mt-2 text-sm text-emerald-50/75">Auto-prioritized by due date, then wax load, then pitcher load.</div>
    <div class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-5">
      @forelse($nextQueue as $index => $queue)
        <button
          type="button"
          wire:click="selectRow('{{ $queue['key'] }}')"
          class="rounded-xl border px-3 py-2 text-left transition {{ $selectedRowKey === $queue['key'] ? 'border-emerald-300/30 bg-emerald-500/15' : 'border-emerald-200/10 bg-black/20 hover:bg-black/30' }}"
        >
          <div class="text-[10px] uppercase tracking-[0.2em] text-emerald-100/55">#{{ $index + 1 }}</div>
          <div class="mt-1 truncate text-sm font-semibold text-white">{{ $queue['scent_label'] }}</div>
          <div class="mt-1 text-[11px] text-emerald-100/75">{{ (int)($queue['units'] ?? 0) }} units · {{ (int)($queue['pitchers'] ?? 0) }} pitchers</div>
          <div class="text-[11px] text-emerald-100/65">{{ rtrim(rtrim(number_format((float)($queue['wax_grams'] ?? 0), 1), '0'), '.') }}g wax</div>
        </button>
      @empty
        <div class="col-span-full rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-3 text-xs text-emerald-50/70">No queue recommendations for current filters.</div>
      @endforelse
    </div>
  </section>

  <section class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_350px]">
    <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-4">
      <div class="rounded-2xl border border-emerald-200/10 overflow-hidden">
        <div class="grid grid-cols-[92px_minmax(0,1.8fr)_68px_minmax(0,1.6fr)_88px_88px_74px_96px] gap-2 border-b border-emerald-200/10 bg-black/30 px-3 py-2 text-[10px] uppercase tracking-[0.2em] text-white/50">
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
              class="grid w-full grid-cols-[92px_minmax(0,1.8fr)_68px_minmax(0,1.6fr)_88px_88px_74px_96px] gap-2 px-3 py-1.5 text-left text-xs transition {{ $isSelected ? 'bg-emerald-500/12' : 'hover:bg-white/5' }}"
            >
              <div class="flex items-center">
                <span class="inline-flex rounded-full border px-2 py-0.5 text-[10px] leading-tight {{ $statusStyle[$status] ?? $statusStyle['mixed'] }}">{{ $row['status_label'] }}</span>
              </div>

              <div class="min-w-0">
                <div class="truncate font-semibold text-white">{{ $row['scent_label'] }}</div>
                <div class="truncate text-[10px] text-emerald-100/60">
                  {{ $channelLabels[$row['primary_channel'] ?? 'retail'] ?? 'Retail' }}
                  @if($batchMode === 'by_market' && $marketLabel !== '') · {{ $marketLabel }} @endif
                </div>
                @if(!empty($row['warnings']))
                  <div class="mt-0.5 flex flex-wrap items-center gap-1 text-[10px] text-amber-100/90">
                    @foreach($row['warnings'] as $warning)
                      <span title="{{ $warning['label'] ?? '' }}">{{ $warning['icon'] ?? '⚠' }}</span>
                    @endforeach
                  </div>
                @endif
              </div>

              <div class="self-center font-semibold text-white">{{ (int)($row['units'] ?? 0) }}</div>
              <div class="truncate self-center text-white/80">{{ $row['size_summary'] ?: '—' }}</div>
              <div class="self-center font-medium text-white/90">{{ rtrim(rtrim(number_format((float)($row['wax_grams'] ?? 0), 1), '0'), '.') }}</div>
              <div class="self-center font-medium text-white/90">{{ rtrim(rtrim(number_format((float)($row['oil_grams'] ?? 0), 1), '0'), '.') }}</div>
              <div class="self-center font-medium text-white/90">{{ (int)($row['pitchers'] ?? 0) }}</div>
              <div class="self-center text-white/80">{{ $due ? $due->format('M j') : '—' }}</div>
            </button>
          @empty
            <div class="px-3 py-4 text-xs text-white/60">No scent rows found for these filters.</div>
          @endforelse
        </div>
      </div>
    </div>

    <aside class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-4">
      <div class="text-[11px] uppercase tracking-[0.25em] text-emerald-100/60">Batch Detail</div>

      @if($selectedRow)
        <div class="mt-2 text-xl font-['Fraunces'] font-semibold text-white">{{ $selectedRow['scent_label'] }}</div>
        @if($batchMode === 'by_market' && !empty($selectedRow['market_label']))
          <div class="text-xs text-emerald-100/70">{{ $selectedRow['market_label'] }}</div>
        @endif

        <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
          <div class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2">
            <div class="text-emerald-100/60">Total Wax</div>
            <div class="text-base font-semibold text-white">{{ rtrim(rtrim(number_format((float)($selectedRow['wax_grams'] ?? 0), 1), '0'), '.') }}g</div>
          </div>
          <div class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2">
            <div class="text-emerald-100/60">Total Oil</div>
            <div class="text-base font-semibold text-white">{{ rtrim(rtrim(number_format((float)($selectedRow['oil_grams'] ?? 0), 1), '0'), '.') }}g</div>
          </div>
          <div class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2">
            <div class="text-emerald-100/60">Total Pitchers</div>
            <div class="text-base font-semibold text-white">{{ (int)($selectedRow['pitchers'] ?? 0) }}</div>
          </div>
          <div class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2">
            <div class="text-emerald-100/60">Total Units</div>
            <div class="text-base font-semibold text-white">{{ (int)($selectedRow['units'] ?? 0) }}</div>
          </div>
        </div>

        <div class="mt-4 text-[10px] uppercase tracking-[0.2em] text-emerald-100/55">Size Breakdown</div>
        <div class="mt-2 rounded-xl border border-emerald-200/10 overflow-hidden">
          <div class="grid grid-cols-[1.2fr_56px_86px_74px] gap-2 border-b border-emerald-200/10 bg-black/25 px-3 py-1.5 text-[10px] uppercase tracking-[0.16em] text-white/55">
            <div>Size</div>
            <div>Qty</div>
            <div>Wax/Oil</div>
            <div>Pitchers</div>
          </div>
          <div class="divide-y divide-emerald-200/10">
            @foreach(($selectedRow['size_rows'] ?? []) as $sizeRow)
              <div class="grid grid-cols-[1.2fr_56px_86px_74px] gap-2 px-3 py-1.5 text-xs text-white/85">
                <div class="truncate">{{ $sizeRow['size_label'] ?? 'Unknown' }}</div>
                <div>{{ (int)($sizeRow['qty'] ?? 0) }}</div>
                <div class="text-[11px]">{{ rtrim(rtrim(number_format((float)($sizeRow['wax_grams'] ?? 0), 1), '0'), '.') }}/{{ rtrim(rtrim(number_format((float)($sizeRow['oil_grams'] ?? 0), 1), '0'), '.') }}g</div>
                <div>{{ (int)($sizeRow['pitchers'] ?? 0) }}</div>
              </div>
            @endforeach
          </div>
        </div>

        <div class="mt-4 text-[10px] uppercase tracking-[0.2em] text-emerald-100/55">Oil Info</div>
        <div class="mt-2 rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-xs text-white/85">
          <div class="font-semibold text-white">{{ $selectedRow['oil_name'] ?? '—' }}</div>
          <div class="mt-1 text-emerald-100/70">Oil grams: {{ rtrim(rtrim(number_format((float)($selectedRow['oil_grams'] ?? 0), 1), '0'), '.') }}g</div>

          @if(count($selectedRow['recipe_components'] ?? []) > 1)
            <details class="mt-2 rounded-lg border border-emerald-200/10 bg-black/20 px-2.5 py-2">
              <summary class="cursor-pointer text-[11px] text-emerald-100/75">Blend breakdown</summary>
              <div class="mt-2 space-y-1 text-[11px] text-emerald-50/80">
                @foreach(($selectedRow['recipe_components'] ?? []) as $component)
                  <div>{{ $component['oil'] ?? 'Oil' }} · {{ $component['ratio'] ?? '—' }}</div>
                @endforeach
              </div>
            </details>
          @endif
        </div>
      @else
        <div class="mt-3 rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-3 text-xs text-emerald-50/70">Select a scent row to inspect batch details.</div>
      @endif
    </aside>
  </section>
</div>
