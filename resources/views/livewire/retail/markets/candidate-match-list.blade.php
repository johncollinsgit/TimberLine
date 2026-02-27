<div class="space-y-2">
  @if(!empty($error))
    <div class="rounded-xl border border-rose-300/20 bg-rose-500/10 px-3 py-2 text-xs text-rose-100">
      {{ $error }}
    </div>
  @endif

  @if(!$hasMatchRun)
    <div class="rounded-xl border border-dashed border-emerald-200/10 bg-black/10 p-4 text-sm text-emerald-50/70">
      Run the local match scan to rank historical events for this upcoming date.
    </div>
  @elseif(empty($candidates))
    <div class="rounded-xl border border-dashed border-emerald-200/10 bg-black/10 p-4 text-sm text-emerald-50/70">
      No prior-year candidates found in this window.
    </div>
  @else
    <div class="max-h-72 overflow-y-auto pr-1 space-y-2">
      @foreach($candidates as $candidate)
        @php($isSelected = (int)($selectedCandidateEventId ?? 0) === (int)($candidate['event_id'] ?? 0))
        <div class="rounded-xl border border-emerald-200/10 bg-black/10 p-3">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <div class="text-xs text-emerald-100/60">{{ (int)($candidate['match_score_percent'] ?? 0) }}% match</div>
              <div class="mt-1 text-sm font-medium text-white break-words">{{ $candidate['title'] ?? 'Historical event' }}</div>
              <div class="mt-1 text-[11px] text-emerald-100/55">
                {{ !empty($candidate['starts_at']) ? \Illuminate\Support\Carbon::parse($candidate['starts_at'])->format('M j, Y') : 'Date TBD' }}
                @if(!empty($candidate['city']) || !empty($candidate['state']))
                  · {{ trim(($candidate['city'] ?? '').', '.($candidate['state'] ?? ''), ' ,') }}
                @endif
              </div>
              <div class="mt-2 text-[10px] text-emerald-100/45">
                Title {{ (int)($candidate['title_score_percent'] ?? 0) }} · Date {{ (int)($candidate['date_score_percent'] ?? 0) }} · Location {{ (int)($candidate['location_score_percent'] ?? 0) }}
              </div>
            </div>
            <button
              type="button"
              wire:click="selectCandidate({{ (int)($candidate['event_id'] ?? 0) }})"
              class="shrink-0 rounded-full border px-3 py-1 text-[11px] transition {{ $isSelected ? 'border-emerald-300/35 bg-emerald-500/15 text-white' : 'border-emerald-300/20 bg-emerald-500/8 text-emerald-50/90 hover:bg-emerald-500/12' }}"
            >
              {{ $isSelected ? 'Selected' : 'Choose' }}
            </button>
          </div>
        </div>
      @endforeach
    </div>
  @endif
</div>
