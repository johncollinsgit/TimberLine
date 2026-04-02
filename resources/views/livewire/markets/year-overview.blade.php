<div class="space-y-6 min-w-0">
  <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <div class="text-[11px] uppercase tracking-[0.3em] text-zinc-500">Markets by Year</div>
        <h1 class="mt-2 text-2xl sm:text-3xl font-semibold text-zinc-950">Markets in {{ $year }}</h1>
        <p class="mt-2 text-sm text-zinc-600">Browse all market occurrences for this year and open any event to review box lines and draft pour planning.</p>
      </div>
      <a href="{{ route('markets.browser.index', ['year' => $year]) }}" class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Back to Markets</a>
    </div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-4 sm:p-5">
    <div class="space-y-2">
      @forelse($events as $event)
        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
          <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
              <div class="text-sm font-semibold text-zinc-950">{{ $event->market?->name ?? $event->name }}</div>
              <div class="mt-1 text-xs text-zinc-600">
                {{ $event->display_name ?: $event->name }}
                <span class="text-zinc-500">·</span>
                {{ $event->starts_at?->format('M j, Y') ?? 'Date TBD' }}
                @if($event->city || $event->state)
                  <span class="text-zinc-500">·</span>
                  {{ trim(collect([$event->city, $event->state])->filter()->implode(', ')) }}
                @endif
              </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              @if($event->market)
                <a href="{{ route('markets.browser.market', $event->market) }}" class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">View market history</a>
              @endif
              <a href="{{ route('markets.browser.event', $event) }}" class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Open event</a>
            </div>
          </div>
        </div>
      @empty
        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-5 text-sm text-zinc-500">No market events recorded for this year.</div>
      @endforelse
    </div>
  </section>
</div>

