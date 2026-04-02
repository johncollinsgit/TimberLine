<div class="space-y-6 min-w-0">
  <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 shadow-[0_24px_60px_-42px_rgba(0,0,0,0.6)]">
    <div class="flex flex-col gap-4">
      <div>
        <div class="text-[11px] uppercase tracking-[0.32em] text-zinc-500">Markets</div>
        <h1 class="mt-2 text-2xl sm:text-3xl font-semibold text-zinc-950">Search + Browse Markets</h1>
        <p class="mt-2 text-sm text-zinc-600">Search by market name, event name, city, state, or venue. Browse history by market or by year.</p>
      </div>

      <div class="grid grid-cols-1 gap-3 md:grid-cols-12">
        <div class="md:col-span-6">
          <label class="sr-only" for="markets-search">Search markets</label>
          <input id="markets-search" type="text" wire:model.live.debounce.300ms="search"
            placeholder="Search markets, cities, venues, event names..."
            class="w-full rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-950 placeholder:text-zinc-500 focus:outline-none focus:ring-4 focus:ring-white/10" />
        </div>
        <div class="md:col-span-3">
          <label class="sr-only" for="markets-year">Year</label>
          <select id="markets-year" wire:model.live="year"
            class="w-full rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-950 focus:outline-none focus:ring-4 focus:ring-white/10">
            <option value="">All years</option>
            @foreach($years as $year)
              <option value="{{ $year }}">{{ $year }}</option>
            @endforeach
          </select>
        </div>
        <div class="md:col-span-2">
          <label class="sr-only" for="markets-state">State</label>
          <select id="markets-state" wire:model.live="state"
            class="w-full rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-950 focus:outline-none focus:ring-4 focus:ring-white/10">
            <option value="">All states</option>
            @foreach($states as $state)
              <option value="{{ $state }}">{{ $state }}</option>
            @endforeach
          </select>
        </div>
        <div class="md:col-span-1">
          <label class="sr-only" for="markets-sort">Sort</label>
          <select id="markets-sort" wire:model.live="sort"
            class="w-full rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-950 focus:outline-none focus:ring-4 focus:ring-white/10">
            <option value="market">Name</option>
            <option value="next_date">Date</option>
            <option value="occurrences">Count</option>
          </select>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <a href="{{ route('markets.browser.index') }}" class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-800 hover:bg-zinc-100">
          <div class="font-semibold text-zinc-950">Browse by Market</div>
          <div class="mt-1 text-xs text-zinc-500">Search + open a market to see all years of history.</div>
        </a>
        @php($latestYear = $years[0] ?? now()->year)
        <a href="{{ route('markets.browser.year', ['year' => $latestYear]) }}" class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-800 hover:bg-zinc-100">
          <div class="font-semibold text-zinc-950">Browse by Year</div>
          <div class="mt-1 text-xs text-zinc-500">Open a year view and drill into each market occurrence.</div>
          <div class="mt-3 flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700">Open {{ $latestYear }}</span>
            @if((auth()->user()?->role ?? null) === 'admin')
              <span class="inline-flex items-center rounded-full border border-emerald-300/20 bg-emerald-100 px-3 py-1.5 text-xs font-semibold text-emerald-800">+ Add New Event</span>
            @endif
          </div>
        </a>
      </div>

      @if((auth()->user()?->role ?? null) === 'admin')
        <div class="-mt-1 flex justify-end">
          <a href="{{ route('events.create') }}" class="inline-flex items-center rounded-full border border-emerald-300/20 bg-emerald-100 px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-100">
            Add New Event
          </a>
        </div>
      @endif
    </div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-4 sm:p-5">
    <div class="space-y-3">
      @forelse($rows as $row)
        @php($market = $row['market'])
        @php($next = $row['next'])
        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-4">
          <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <div class="text-base font-semibold text-zinc-950">{{ $row['title'] }}</div>
                @if(!empty($row['raw_market_key']))
                  <span class="rounded-full border border-amber-300/20 bg-amber-100 px-2 py-0.5 text-[11px] text-amber-800">Imported key: {{ $row['raw_market_key'] }}</span>
                @endif
              </div>
              <div class="mt-1 text-sm text-zinc-600">
                {{ $row['occurrences_count'] }} occurrence{{ $row['occurrences_count'] === 1 ? '' : 's' }}
                @if($next)
                  <span class="text-zinc-500">·</span>
                  Next / recent: {{ $next->starts_at?->format('M j, Y') ?? 'Date TBD' }}
                  @if($next->city || $next->state)
                    <span class="text-zinc-500">·</span>
                    {{ trim(collect([$next->city, $next->state])->filter()->implode(', ')) }}
                  @endif
                @endif
              </div>
              @if($next && ($next->name || $next->display_name))
                <div class="mt-1 text-sm text-zinc-700">
                  {{ $next->name ?: $next->display_name }}
                  <span class="text-zinc-500">·</span>
                  {{ $next->starts_at?->format('M j, Y') ?? 'Date TBD' }}
                </div>
              @endif
            </div>
            <div class="flex flex-col gap-2 lg:w-56 lg:shrink-0">
              <a href="{{ route('markets.browser.market', $market) }}" class="inline-flex w-full items-center justify-center rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">View market history</a>
              @if($next && ($next->year ?? null))
                <a href="{{ route('markets.browser.year', ['year' => $next->year]) }}" class="inline-flex w-full items-center justify-center rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">View year list</a>
              @else
                <div class="hidden lg:block h-[34px]"></div>
              @endif
            </div>
          </div>
        </div>
      @empty
        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-5 text-sm text-zinc-500">
          No markets matched your search/filter.
        </div>
      @endforelse
    </div>

    <div class="mt-4">
      {{ $rows->links() }}
    </div>
  </section>
</div>
