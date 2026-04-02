<div class="space-y-6 min-w-0">
  <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <div class="text-[11px] uppercase tracking-[0.3em] text-zinc-500">Market History</div>
        <h1 class="mt-2 text-2xl sm:text-3xl font-semibold text-zinc-950">{{ $displayTitle }}</h1>
        @if(!blank($rawTitleKey))
          <div class="mt-2 inline-flex items-center rounded-full border border-amber-300/20 bg-amber-100 px-3 py-1 text-[11px] text-amber-800">
            Imported key: {{ $rawTitleKey }}
          </div>
        @endif
        <p class="mt-2 text-sm text-zinc-600">Occurrences grouped by year. Open any event to review imported box lines and draft pour list status.</p>
      </div>
      <a href="{{ route('markets.browser.index') }}" class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Back to Markets</a>
    </div>
  </section>

  @forelse($groupedEvents as $year => $events)
    <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-4 sm:p-5">
      <div class="text-lg font-semibold text-zinc-950">{{ $year }}</div>
      <div class="mt-3 space-y-2">
        @foreach($events as $event)
          <div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
            <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
              <div class="min-w-0">
                <div class="text-sm font-semibold text-zinc-950">{{ $event->name ?: ($event->display_name ?: 'Untitled Event') }}</div>
                <div class="mt-1 text-xs text-zinc-600">
                  @if(($event->display_name ?: null) && $event->display_name !== $event->name)
                    {{ $event->display_name }}
                    <span class="text-zinc-500">·</span>
                  @endif
                  {{ $event->starts_at?->format('M j, Y') ?? 'Date TBD' }}
                  @if($event->ends_at && !$event->ends_at->isSameDay($event->starts_at))
                    - {{ $event->ends_at->format('M j, Y') }}
                  @endif
                  @if($event->city || $event->state)
                    <span class="text-zinc-500">·</span>
                    {{ trim(collect([$event->city, $event->state])->filter()->implode(', ')) }}
                  @endif
                  @if($event->venue)
                    <span class="text-zinc-500">·</span>{{ $event->venue }}
                  @endif
                </div>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-[11px] text-zinc-600">{{ $event->boxShipments->count() }} box line{{ $event->boxShipments->count() === 1 ? '' : 's' }}</span>
                @if($event->marketPourList)
                  <span class="rounded-full border border-emerald-300/20 bg-emerald-100 px-3 py-1 text-[11px] text-emerald-800">Pour List: {{ ucfirst($event->marketPourList->status) }}</span>
                @endif
                <a href="{{ route('markets.browser.event', $event) }}" class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Open event</a>
              </div>
            </div>
          </div>
        @endforeach
      </div>
    </section>
  @empty
    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-5 text-sm text-zinc-500">No occurrences found for this market yet.</div>
  @endforelse
</div>
