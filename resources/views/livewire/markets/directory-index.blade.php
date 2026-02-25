<div class="space-y-6 min-w-0">
  <section class="rounded-3xl border border-white/10 bg-white/5 p-5 sm:p-6 shadow-[0_24px_60px_-42px_rgba(0,0,0,0.6)]">
    <div class="flex flex-col gap-4">
      <div>
        <div class="text-[11px] uppercase tracking-[0.32em] text-white/55">Markets</div>
        <h1 class="mt-2 text-2xl sm:text-3xl font-semibold text-white">Search + Browse Markets</h1>
        <p class="mt-2 text-sm text-white/65">Search by market name, event name, city, state, or venue. Browse history by market or by year.</p>
      </div>

      <div class="grid grid-cols-1 gap-3 md:grid-cols-12">
        <div class="md:col-span-6">
          <label class="sr-only" for="markets-search">Search markets</label>
          <input id="markets-search" type="text" wire:model.live.debounce.300ms="search"
            placeholder="Search markets, cities, venues, event names..."
            class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white placeholder:text-white/45 focus:outline-none focus:ring-4 focus:ring-white/10" />
        </div>
        <div class="md:col-span-3">
          <label class="sr-only" for="markets-year">Year</label>
          <select id="markets-year" wire:model.live="year"
            class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white focus:outline-none focus:ring-4 focus:ring-white/10">
            <option value="">All years</option>
            @foreach($years as $year)
              <option value="{{ $year }}">{{ $year }}</option>
            @endforeach
          </select>
        </div>
        <div class="md:col-span-2">
          <label class="sr-only" for="markets-state">State</label>
          <select id="markets-state" wire:model.live="state"
            class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white focus:outline-none focus:ring-4 focus:ring-white/10">
            <option value="">All states</option>
            @foreach($states as $state)
              <option value="{{ $state }}">{{ $state }}</option>
            @endforeach
          </select>
        </div>
        <div class="md:col-span-1">
          <label class="sr-only" for="markets-sort">Sort</label>
          <select id="markets-sort" wire:model.live="sort"
            class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white focus:outline-none focus:ring-4 focus:ring-white/10">
            <option value="market">Name</option>
            <option value="next_date">Date</option>
            <option value="occurrences">Count</option>
          </select>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <a href="{{ route('markets.browser.index') }}" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white/85 hover:bg-white/10">
          <div class="font-semibold text-white">Browse by Market</div>
          <div class="mt-1 text-xs text-white/60">Search + open a market to see all years of history.</div>
        </a>
        @php($latestYear = $years[0] ?? now()->year)
        <a href="{{ route('markets.browser.year', ['year' => $latestYear]) }}" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white/85 hover:bg-white/10">
          <div class="font-semibold text-white">Browse by Year</div>
          <div class="mt-1 text-xs text-white/60">Open a year view and drill into each market occurrence.</div>
          <div class="mt-3 flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80">Open {{ $latestYear }}</span>
            @if((auth()->user()?->role ?? null) === 'admin')
              <span class="inline-flex items-center rounded-full border border-emerald-300/20 bg-emerald-500/10 px-3 py-1.5 text-xs font-semibold text-emerald-100/90">+ Add New Event</span>
            @endif
          </div>
        </a>
      </div>

      @if((auth()->user()?->role ?? null) === 'admin')
        <div class="-mt-1 flex justify-end">
          <a href="{{ route('events.create') }}" class="inline-flex items-center rounded-full border border-emerald-300/20 bg-emerald-500/10 px-3 py-1.5 text-xs font-semibold text-emerald-100/90 hover:bg-emerald-500/15">
            Add New Event
          </a>
        </div>
      @endif
    </div>
  </section>

  <section class="rounded-3xl border border-white/10 bg-white/5 p-4 sm:p-5">
    <div class="space-y-3">
      @forelse($rows as $row)
        @php($market = $row['market'])
        @php($next = $row['next'])
        <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-4">
          <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <div class="text-base font-semibold text-white">{{ $row['title'] }}</div>
                @if(!empty($row['raw_market_key']))
                  <span class="rounded-full border border-amber-300/20 bg-amber-400/10 px-2 py-0.5 text-[11px] text-amber-100/90">Imported key: {{ $row['raw_market_key'] }}</span>
                @endif
              </div>
              <div class="mt-1 text-sm text-white/65">
                {{ $row['occurrences_count'] }} occurrence{{ $row['occurrences_count'] === 1 ? '' : 's' }}
                @if($next)
                  <span class="text-white/35">·</span>
                  Next / recent: {{ $next->starts_at?->format('M j, Y') ?? 'Date TBD' }}
                  @if(($next->display_name ?: $next->name) && ($next->display_name ?: $next->name) !== $row['title'])
                    <span class="text-white/35">·</span>
                    {{ $next->display_name ?: $next->name }}
                  @endif
                  @if($next->city || $next->state)
                    <span class="text-white/35">·</span>
                    {{ trim(collect([$next->city, $next->state])->filter()->implode(', ')) }}
                  @endif
                @endif
              </div>
            </div>
            <div class="flex flex-col gap-2 lg:w-56 lg:shrink-0">
              <a href="{{ route('markets.browser.market', $market) }}" class="inline-flex w-full items-center justify-center rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80 hover:bg-white/10">View market history</a>
              @if($next && ($next->year ?? null))
                <a href="{{ route('markets.browser.year', ['year' => $next->year]) }}" class="inline-flex w-full items-center justify-center rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80 hover:bg-white/10">View year list</a>
              @else
                <div class="hidden lg:block h-[34px]"></div>
              @endif
            </div>
          </div>
        </div>
      @empty
        <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-5 text-sm text-white/60">
          No markets matched your search/filter.
        </div>
      @endforelse
    </div>

    <div class="mt-4">
      {{ $rows->links() }}
    </div>
  </section>
</div>
