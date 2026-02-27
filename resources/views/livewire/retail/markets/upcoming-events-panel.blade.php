<div>
  @if($hasQueueEvents)
  <div class="rounded-2xl border border-emerald-200/10 bg-black/15 p-3">
    @livewire(
      \App\Livewire\Retail\MarketsSyncStatus::class,
      ['planId' => $planId, 'queue' => 'markets'],
      key('markets-sync-status-'.(int) $planId)
    )

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
          <div wire:key="upcoming-event-{{ (int)($event['id'] ?? 0) }}" class="rounded-xl border px-3 py-2 transition {{ $isSelected ? 'border-emerald-300/35 bg-emerald-500/12' : 'border-emerald-200/10 bg-black/10 hover:bg-white/5' }}">
            <button
              type="button"
              wire:click="selectEvent({{ (int)($event['id'] ?? 0) }})"
              class="w-full text-left"
            >
              <div class="text-xs text-emerald-100/60">{{ !empty($event['starts_at']) ? \Illuminate\Support\Carbon::parse($event['starts_at'])->format('M j, Y') : 'Date TBD' }}</div>
              <div class="mt-1 text-sm font-medium text-white">{{ $event['display_name'] ?: $event['name'] ?: 'Untitled Event' }}</div>
              @if((int)($event['draft_rows_count'] ?? 0) > 0)
                <div class="mt-1 text-[11px] text-emerald-100/55">{{ (int)$event['draft_rows_count'] }} draft rows</div>
              @endif
            </button>
            <div class="mt-2 flex justify-end">
              <button
                type="button"
                wire:click="matchEvent({{ (int)($event['id'] ?? 0) }})"
                class="rounded-full border border-emerald-300/20 bg-emerald-500/8 px-3 py-1 text-[11px] text-emerald-50/90 hover:bg-emerald-500/12"
              >
                Find Match
              </button>
            </div>
          </div>
        @empty
          <div class="rounded-xl border border-dashed border-emerald-200/15 bg-emerald-500/5 p-4 text-sm text-emerald-50/70">
            No events in this state.
          </div>
        @endforelse
      </div>
    </div>
  </div>
  @endif
</div>
