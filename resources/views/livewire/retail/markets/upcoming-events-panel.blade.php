<div>
  <div class="rounded-2xl border border-emerald-200/10 bg-black/15 p-3">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <div class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Step 1</div>
        <div class="mt-1 text-sm font-semibold text-white">Choose Upcoming Event</div>
      </div>
      <div class="text-[11px] text-emerald-100/55">Stored events only. Select one event to continue.</div>
    </div>

    <div class="mt-3 rounded-2xl border border-emerald-200/10 bg-black/20 p-3 text-[11px] text-emerald-100/60">
      <div class="flex flex-col gap-1 md:flex-row md:flex-wrap md:items-center md:gap-x-4">
        <div><span class="text-emerald-50/85">Source:</span> {{ $sourceLabel }}</div>
        <div><span class="text-emerald-50/85">Window:</span> {{ $windowLabel }}</div>
        @if(!empty($lastSyncAt))
          <div><span class="text-emerald-50/85">Last sync:</span> {{ \Illuminate\Support\Carbon::parse($lastSyncAt)->format('Y-m-d H:i') }}</div>
        @endif
        <div><span class="text-emerald-50/85">Limit:</span> {{ (int)($pickerLimit ?? 0) }} events</div>
      </div>
    </div>

    <div class="mt-3 grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
      <div class="grid gap-3 md:grid-cols-[auto_minmax(0,1fr)]">
        <div>
          <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/45">Scope</div>
          <div class="mt-2 flex flex-wrap items-center gap-2">
            <button type="button" wire:click="setDateMode('future')"
              class="rounded-full border px-3 py-1 text-xs {{ $dateMode === 'future' ? 'border-emerald-300/35 bg-emerald-500/15 text-white' : 'border-white/10 text-emerald-100/70' }}">
              Future
            </button>
            <button type="button" wire:click="setDateMode('past')"
              class="rounded-full border px-3 py-1 text-xs {{ $dateMode === 'past' ? 'border-emerald-300/35 bg-emerald-500/15 text-white' : 'border-white/10 text-emerald-100/70' }}">
              Past
            </button>
            <button type="button" wire:click="setDateMode('all')"
              class="rounded-full border px-3 py-1 text-xs {{ $dateMode === 'all' ? 'border-emerald-300/35 bg-emerald-500/15 text-white' : 'border-white/10 text-emerald-100/70' }}">
              All
            </button>
          </div>
        </div>
        <div>
          <label for="markets-event-search" class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/45">Search Events</label>
          <input
            id="markets-event-search"
            type="text"
            wire:model.live.debounce.300ms="searchTerm"
            placeholder="Search events or location..."
            class="mt-2 w-full rounded-xl border border-emerald-200/10 bg-black/25 px-3 py-2 text-sm text-white placeholder:text-emerald-100/35 focus:border-emerald-300/30 focus:outline-none"
          >
        </div>
      </div>
      <div class="grid gap-3 sm:grid-cols-2">
        <div>
          <label for="markets-event-from" class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/45">From</label>
          <input
            id="markets-event-from"
            type="date"
            wire:model.live="fromDate"
            class="mt-2 w-full rounded-xl border border-emerald-200/10 bg-black/25 px-3 py-2 text-sm text-white focus:border-emerald-300/30 focus:outline-none"
          >
        </div>
        <div>
          <label for="markets-event-to" class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/45">To</label>
          <input
            id="markets-event-to"
            type="date"
            wire:model.live="toDate"
            class="mt-2 w-full rounded-xl border border-emerald-200/10 bg-black/25 px-3 py-2 text-sm text-white focus:border-emerald-300/30 focus:outline-none"
          >
        </div>
      </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-2">
      <button type="button" wire:click="setStateTab('needs_mapping')"
        class="rounded-full border px-3 py-1 text-xs {{ $stateTab === 'needs_mapping' ? 'border-emerald-300/35 bg-emerald-500/15 text-white' : 'border-white/10 text-emerald-100/70' }}">
        Needs Mapping ({{ (int)($counts['needs_mapping'] ?? 0) }})
      </button>
      <button type="button" wire:click="setStateTab('mapped')"
        class="rounded-full border px-3 py-1 text-xs {{ $stateTab === 'mapped' ? 'border-emerald-300/35 bg-emerald-500/15 text-white' : 'border-white/10 text-emerald-100/70' }}">
        Mapped ({{ (int)($counts['mapped'] ?? 0) }})
      </button>
      <button type="button" wire:click="setStateTab('drafted')"
        class="rounded-full border px-3 py-1 text-xs {{ $stateTab === 'drafted' ? 'border-emerald-300/35 bg-emerald-500/15 text-white' : 'border-white/10 text-emerald-100/70' }}">
        Drafted ({{ (int)($counts['drafted'] ?? 0) }})
      </button>
      <button type="button" wire:click="setStateTab('submitted')"
        class="rounded-full border px-3 py-1 text-xs {{ $stateTab === 'submitted' ? 'border-emerald-300/35 bg-emerald-500/15 text-white' : 'border-white/10 text-emerald-100/70' }}">
        Submitted ({{ (int)($counts['submitted'] ?? 0) }})
      </button>
    </div>

    <div class="mt-3 max-h-[24rem] overflow-y-auto pr-1">
      <div class="space-y-2">
        @forelse($events as $event)
          @php($isSelected = (int)($selectedEventId ?? 0) === (int)($event['id'] ?? 0))
          <div
            wire:key="upcoming-event-{{ (int)($event['id'] ?? 0) }}"
            x-data="{ selecting: false }"
            x-on:markets-upcoming-event-selected.window="selecting = false"
            class="rounded-xl border px-3 py-2 transition {{ $isSelected ? 'border-emerald-300/35 bg-emerald-500/12' : 'border-emerald-200/10 bg-black/10 hover:bg-white/5' }}"
          >
            <button
              type="button"
              @click="selecting = true"
              wire:click="selectEvent({{ (int)($event['id'] ?? 0) }})"
              wire:loading.attr="disabled"
              wire:target="selectEvent({{ (int)($event['id'] ?? 0) }})"
              class="w-full text-left"
            >
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <div class="text-xs text-emerald-100/60">{{ !empty($event['starts_at']) ? \Illuminate\Support\Carbon::parse($event['starts_at'])->format('M j, Y') : 'Date TBD' }}</div>
                  <div class="mt-1 text-sm font-medium text-white">{{ $event['display_name'] ?: $event['name'] ?: 'Untitled Event' }}</div>
                </div>
                <span
                  x-show="selecting"
                  wire:loading.remove
                  wire:target="selectEvent({{ (int)($event['id'] ?? 0) }})"
                  class="shrink-0 text-[11px] text-emerald-100/55"
                >
                  Selecting...
                </span>
                <span wire:loading wire:target="selectEvent({{ (int)($event['id'] ?? 0) }})" class="shrink-0 text-[11px] text-emerald-100/55">
                  Loading...
                </span>
              </div>
              @if((int)($event['draft_rows_count'] ?? 0) > 0)
                <div class="mt-1 text-[11px] text-emerald-100/55">{{ (int)$event['draft_rows_count'] }} draft rows</div>
              @endif
            </button>
          </div>
        @empty
          <div class="rounded-xl border border-dashed border-emerald-200/15 bg-emerald-500/5 p-4 text-sm text-emerald-50/70">
            No events match this state and date window. Try Past or All, widen the dates, or search by title/location.
          </div>
        @endforelse
      </div>
    </div>
  </div>
</div>
