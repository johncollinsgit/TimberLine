<div class="rounded-2xl border border-emerald-200/10 bg-black/15 p-3">
  <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">Event Match Wizard</div>

  @if(!$upcomingEventId || empty($upcomingEvent))
    <div class="mt-3 rounded-xl border border-dashed border-emerald-200/15 bg-black/10 p-4 text-sm text-emerald-50/70">
      Step 1: Select an upcoming event from the left panel.
    </div>
  @else
    <div class="mt-3 rounded-xl border border-emerald-200/10 bg-black/10 p-3">
      <div class="text-xs uppercase tracking-[0.2em] text-emerald-100/55">Step 1 · Selected Upcoming Event</div>
      <div class="mt-1 text-sm font-semibold text-white">{{ $upcomingEvent['display_name'] ?: $upcomingEvent['name'] ?: 'Untitled Event' }}</div>
      <div class="mt-1 text-xs text-emerald-100/60">
        {{ !empty($upcomingEvent['starts_at']) ? \Illuminate\Support\Carbon::parse($upcomingEvent['starts_at'])->format('M j, Y') : 'Date TBD' }}
      </div>
    </div>

    <div class="mt-3 rounded-xl border border-emerald-200/10 bg-black/10 p-3">
      <div class="text-xs uppercase tracking-[0.2em] text-emerald-100/55">Step 2 · Find Historical Match</div>
      <div class="mt-2">
        @livewire(
          \App\Livewire\Retail\Markets\CandidateMatchList::class,
          [
            'upcomingEventId' => $upcomingEventId,
            'selectedCandidateEventId' => $selectedCandidateEventId,
            'matchWindowDays' => $matchWindowDays,
          ],
          key('candidate-match-list-'.(int)($upcomingEventId ?? 0).'-'.(int)$matchWindowDays)
        )
      </div>
    </div>

    @if($selectedCandidateEventId)
      <div class="mt-3 rounded-xl border border-emerald-200/10 bg-black/10 p-3">
        <div class="text-xs uppercase tracking-[0.2em] text-emerald-100/55">Step 3 · Preview Historical Boxes</div>
        <div class="mt-2">
          @livewire(
            \App\Livewire\Retail\Markets\PrefillPreviewPanel::class,
            ['candidateEventId' => $selectedCandidateEventId],
            key('prefill-preview-panel-'.(int)$selectedCandidateEventId)
          )
        </div>
      </div>

      <div class="mt-3 rounded-xl border border-emerald-200/10 bg-black/10 p-3">
        <div class="text-xs uppercase tracking-[0.2em] text-emerald-100/55">Step 4 · Confirm Mapping</div>
        <div class="mt-2 text-xs text-emerald-100/70">
          This saves a baseline mapping for future years and creates a draft pour plan for this event.
        </div>
        <div class="mt-3 flex flex-wrap justify-end gap-2">
          @if($step < 4)
            <button type="button" wire:click="goToConfirm"
              class="rounded-xl border border-emerald-300/25 bg-emerald-500/10 px-3 py-2 text-xs text-white/90">
              Continue
            </button>
          @endif
          @if($step >= 4)
            <button type="button" wire:click="confirmAndCreateDraft"
              class="rounded-xl border border-emerald-300/30 bg-emerald-500/20 px-3 py-2 text-xs font-semibold text-white">
              Confirm & Create Draft
            </button>
          @endif
        </div>
      </div>
    @endif

    @if($step === 5)
      <div class="mt-3 rounded-xl border border-emerald-300/20 bg-emerald-500/10 p-3 text-xs text-emerald-50/90">
        Step 5 complete: draft created. Use “Candles to be poured” below for this selected event.
      </div>
    @endif
  @endif
</div>
