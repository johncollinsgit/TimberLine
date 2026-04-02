<div>
  <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <div class="text-[11px] uppercase tracking-[0.22em] text-emerald-800">Step 1</div>
        <div class="mt-1 text-sm font-semibold text-zinc-950">Choose Upcoming Event</div>
      </div>
      <div class="text-[11px] text-emerald-800">Stored events only. Select one event to continue.</div>
    </div>

    <div class="mt-3 rounded-2xl border border-zinc-200 bg-zinc-50 p-3 text-[11px] text-emerald-800">
      <div class="flex flex-col gap-1 md:flex-row md:flex-wrap md:items-center md:gap-x-4">
        <div><span class="text-zinc-600">Source:</span> {{ $sourceLabel }}</div>
        <div><span class="text-zinc-600">Window:</span> {{ $windowLabel }}</div>
        @if(!empty($lastSyncAt))
          <div><span class="text-zinc-600">Last sync:</span> {{ \Illuminate\Support\Carbon::parse($lastSyncAt)->format('Y-m-d H:i') }}</div>
        @endif
        <div><span class="text-zinc-600">Limit:</span> {{ (int)($pickerLimit ?? 0) }} events</div>
      </div>
    </div>

    <div class="mt-3 grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
      <div class="grid gap-3 md:grid-cols-[auto_minmax(0,1fr)]">
        <div>
          <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-800">Scope</div>
          <div class="mt-2 flex flex-wrap items-center gap-2">
            <button type="button" wire:click="setDateMode('future')"
              class="rounded-full border px-3 py-1 text-xs {{ $dateMode === 'future' ? 'border-zinc-300 bg-emerald-100 text-zinc-950' : 'border-zinc-200 text-emerald-800' }}">
              Future
            </button>
            <button type="button" wire:click="setDateMode('past')"
              class="rounded-full border px-3 py-1 text-xs {{ $dateMode === 'past' ? 'border-zinc-300 bg-emerald-100 text-zinc-950' : 'border-zinc-200 text-emerald-800' }}">
              Past
            </button>
            <button type="button" wire:click="setDateMode('all')"
              class="rounded-full border px-3 py-1 text-xs {{ $dateMode === 'all' ? 'border-zinc-300 bg-emerald-100 text-zinc-950' : 'border-zinc-200 text-emerald-800' }}">
              All
            </button>
          </div>
        </div>
        <div>
          <label for="markets-event-search" class="text-[10px] uppercase tracking-[0.22em] text-emerald-800">Search Events</label>
          <input
            id="markets-event-search"
            type="text"
            wire:model.live.debounce.300ms="searchTerm"
            placeholder="Search events or location..."
            class="mt-2 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 placeholder:text-emerald-800 focus:border-emerald-300/30 focus:outline-none"
          >
        </div>
      </div>
      <div class="grid gap-3 sm:grid-cols-2">
        <div>
          <label for="markets-event-from" class="text-[10px] uppercase tracking-[0.22em] text-emerald-800">From</label>
          <input
            id="markets-event-from"
            type="date"
            wire:model.live="fromDate"
            class="mt-2 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 focus:border-emerald-300/30 focus:outline-none"
          >
        </div>
        <div>
          <label for="markets-event-to" class="text-[10px] uppercase tracking-[0.22em] text-emerald-800">To</label>
          <input
            id="markets-event-to"
            type="date"
            wire:model.live="toDate"
            class="mt-2 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 focus:border-emerald-300/30 focus:outline-none"
          >
        </div>
      </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-2">
      <button type="button" wire:click="setStateTab('needs_mapping')"
        class="rounded-full border px-3 py-1 text-xs {{ $stateTab === 'needs_mapping' ? 'border-zinc-300 bg-emerald-100 text-zinc-950' : 'border-zinc-200 text-emerald-800' }}">
        Needs Mapping ({{ (int)($counts['needs_mapping'] ?? 0) }})
      </button>
      <button type="button" wire:click="setStateTab('mapped')"
        class="rounded-full border px-3 py-1 text-xs {{ $stateTab === 'mapped' ? 'border-zinc-300 bg-emerald-100 text-zinc-950' : 'border-zinc-200 text-emerald-800' }}">
        Mapped ({{ (int)($counts['mapped'] ?? 0) }})
      </button>
      <button type="button" wire:click="setStateTab('drafted')"
        class="rounded-full border px-3 py-1 text-xs {{ $stateTab === 'drafted' ? 'border-zinc-300 bg-emerald-100 text-zinc-950' : 'border-zinc-200 text-emerald-800' }}">
        Drafted ({{ (int)($counts['drafted'] ?? 0) }})
      </button>
      <button type="button" wire:click="setStateTab('submitted')"
        class="rounded-full border px-3 py-1 text-xs {{ $stateTab === 'submitted' ? 'border-zinc-300 bg-emerald-100 text-zinc-950' : 'border-zinc-200 text-emerald-800' }}">
        Submitted ({{ (int)($counts['submitted'] ?? 0) }})
      </button>
    </div>

    <div class="mt-3 rounded-2xl border border-zinc-200 bg-zinc-50 p-3">
      <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
        <div>
          <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-800">Quick Start Templates</div>
          <div class="mt-1 text-xs text-emerald-800">
            Fixed 1, 2, and 3-day starter drafts built from historical average box volume and the top 15 scents overall.
          </div>
          @if((int)($selectedEventDurationDays ?? 0) > 0)
            <div class="mt-1 text-[11px] text-zinc-600">
              Selected event length: {{ (int)$selectedEventDurationDays }} day{{ (int)$selectedEventDurationDays === 1 ? '' : 's' }}.
            </div>
          @endif
        </div>
        <div class="text-[11px] text-emerald-800">
          @if(!$selectedEventId)
            Select an event first to apply a starter.
          @else
            Choose a starter to jump straight into a draft.
          @endif
        </div>
      </div>

      <div class="mt-3 grid gap-3 lg:grid-cols-3">
        @foreach(($durationTemplates ?? []) as $template)
          @php($dayCount = (int)($template['day_count'] ?? 0))
          <div class="rounded-2xl border px-3 py-3 {{ !empty($template['recommended']) ? 'border-emerald-300/30 bg-emerald-100' : 'border-zinc-200 bg-zinc-50' }}">
            <div class="flex items-start justify-between gap-2">
              <div>
                <div class="text-sm font-semibold text-zinc-950">{{ $template['label'] ?? ($dayCount.'-Day Starter') }}</div>
                <div class="mt-1 text-[11px] text-emerald-800">
                  Avg {{ rtrim(rtrim(number_format((float)($template['average_boxes'] ?? 0), 1), '0'), '.') }} boxes
                  · {{ (int)($template['scent_count'] ?? 0) }} scents
                </div>
              </div>
              @if(!empty($template['recommended']))
                <span class="rounded-full border border-emerald-300/20 bg-emerald-100 px-2 py-0.5 text-[10px] text-emerald-900">Recommended</span>
              @endif
            </div>
            <button
              type="button"
              wire:click="applyDurationTemplate({{ $dayCount }})"
              @disabled(!$selectedEventId || empty($template['available']))
              class="mt-3 w-full rounded-xl border border-emerald-300/25 bg-emerald-100 px-3 py-2 text-sm font-semibold text-zinc-950 disabled:cursor-not-allowed disabled:opacity-45"
            >
              Use {{ $dayCount }}-Day Starter
            </button>
          </div>
        @endforeach
      </div>

      @if(!empty($starterTemplateNotice))
        <div class="mt-3 rounded-2xl border border-emerald-300/20 bg-emerald-100 px-4 py-3">
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-sm text-emerald-900">{{ $starterTemplateNotice }}</div>
            <button
              type="button"
              wire:click="continueWithStarterDraft"
              class="w-full rounded-xl border border-emerald-300/25 bg-emerald-100 px-4 py-2 text-sm font-semibold text-zinc-950 sm:w-auto"
            >
              Continue
            </button>
          </div>
        </div>
      @endif
    </div>

    <div class="mt-3 max-h-[24rem] overflow-y-auto pr-1">
      <div class="space-y-2">
        @forelse($events as $event)
          @php($isSelected = (int)($selectedEventId ?? 0) === (int)($event['id'] ?? 0))
          <div
            wire:key="upcoming-event-{{ (int)($event['id'] ?? 0) }}"
            x-data="{ selecting: false }"
            x-on:markets-upcoming-event-selected.window="selecting = false"
            class="rounded-xl border px-3 py-2 transition {{ $isSelected ? 'border-zinc-300 bg-emerald-500/14 shadow-[0_0_0_1px_rgba(110,231,183,0.08)]' : 'border-zinc-200 bg-zinc-50 hover:border-emerald-300/25 hover:bg-emerald-100' }}"
          >
            <button
              type="button"
              @click="selecting = true"
              wire:click="selectEvent({{ (int)($event['id'] ?? 0) }})"
              wire:loading.attr="disabled"
              wire:target="selectEvent({{ (int)($event['id'] ?? 0) }})"
              class="w-full cursor-pointer text-left disabled:cursor-progress"
            >
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <div class="text-xs text-emerald-800">{{ !empty($event['starts_at']) ? \Illuminate\Support\Carbon::parse($event['starts_at'])->format('M j, Y') : 'Date TBD' }}</div>
                  <div class="mt-1 text-sm font-medium text-zinc-950">{{ $event['display_name'] ?: $event['name'] ?: 'Untitled Event' }}</div>
                </div>
                <span
                  x-show="selecting"
                  wire:loading.remove
                  wire:target="selectEvent({{ (int)($event['id'] ?? 0) }})"
                  class="shrink-0 text-[11px] text-emerald-800"
                >
                  Selecting...
                </span>
                <span wire:loading wire:target="selectEvent({{ (int)($event['id'] ?? 0) }})" class="shrink-0 text-[11px] text-emerald-800">
                  Loading...
                </span>
              </div>
              @if((int)($event['draft_rows_count'] ?? 0) > 0)
                <div class="mt-1 text-[11px] text-emerald-800">{{ (int)$event['draft_rows_count'] }} draft rows</div>
              @endif
            </button>
          </div>
        @empty
          <div class="rounded-xl border border-dashed border-zinc-200 bg-emerald-50 p-4 text-sm text-zinc-600">
            No events match this state and date window. Try Past or All, widen the dates, or search by title/location.
          </div>
        @endforelse
      </div>
    </div>
  </div>
</div>
