<div class="space-y-2">
  @if(!empty($error))
    <div class="rounded-xl border border-rose-300/20 bg-rose-100 px-3 py-2 text-xs text-rose-900">
      {{ $error }}
    </div>
  @endif

  @if(!$hasMatchRun)
    <div class="rounded-xl border border-dashed border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
      Run the local match scan to rank historical box-plan templates within {{ (int) $matchWindowDays }} days of this upcoming date.
    </div>
  @elseif(empty($candidates))
    <div class="rounded-xl border border-dashed border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
      No prior-year box-plan candidates found within {{ (int) $matchWindowDays }} days.
    </div>
  @else
    <div class="max-h-72 overflow-y-auto pr-1 space-y-2">
      @foreach($candidates as $candidate)
        @php($isSelected = (int)($selectedCandidateEventId ?? 0) === (int)($candidate['event_id'] ?? 0))
        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <div class="text-xs text-emerald-800">{{ (int)($candidate['match_score_percent'] ?? 0) }}% match</div>
              <div class="mt-1 text-sm font-medium text-zinc-950 break-words">{{ $candidate['title'] ?? 'Historical event' }}</div>
              <div class="mt-1 text-[11px] text-emerald-800">
                {{ !empty($candidate['starts_at']) ? \Illuminate\Support\Carbon::parse($candidate['starts_at'])->format('M j, Y') : 'Date TBD' }}
                @if(!empty($candidate['ends_at']) && $candidate['ends_at'] !== $candidate['starts_at'])
                  – {{ \Illuminate\Support\Carbon::parse($candidate['ends_at'])->format('M j, Y') }}
                @endif
                @if(!empty($candidate['state']))
                  · {{ $candidate['state'] }}
                @endif
              </div>
              @if(!empty($candidate['notes_snippet']))
                <div class="mt-2 text-[11px] text-emerald-800">{{ $candidate['notes_snippet'] }}</div>
              @endif
              @if(!empty($candidate['box_preview']))
                <div class="mt-2 space-y-1 text-[11px] text-zinc-600">
                  @foreach($candidate['box_preview'] as $line)
                    <div class="flex items-center justify-between gap-3">
                      <span class="truncate">{{ $line['scent_raw'] }}</span>
                      <span class="shrink-0">
                        {{ $line['box_count_sent'] !== null ? rtrim(rtrim(number_format((float) $line['box_count_sent'], 2), '0'), '.') : '—' }}
                        @if(!empty($line['is_split_box']))
                          <span class="text-amber-800">split</span>
                        @endif
                      </span>
                    </div>
                  @endforeach
                  @if((int)($candidate['box_plan_count'] ?? 0) > count($candidate['box_preview']))
                    <div class="text-[10px] text-emerald-800">+{{ (int)($candidate['box_plan_count'] ?? 0) - count($candidate['box_preview']) }} more lines</div>
                  @endif
                </div>
              @endif
              <div class="mt-2 text-[10px] text-emerald-800">
                Title {{ (int)($candidate['title_score_percent'] ?? 0) }} · Date {{ (int)($candidate['date_score_percent'] ?? 0) }} · Location {{ (int)($candidate['location_score_percent'] ?? 0) }}
              </div>
            </div>
            <button
              type="button"
              wire:click="selectCandidate({{ (int)($candidate['event_id'] ?? 0) }})"
              class="shrink-0 rounded-full border px-3 py-1 text-[11px] transition {{ $isSelected ? 'border-zinc-300 bg-emerald-100 text-zinc-950' : 'border-emerald-300/20 bg-emerald-100 text-zinc-600 hover:bg-emerald-100' }}"
            >
              {{ $isSelected ? 'Selected' : 'Choose' }}
            </button>
          </div>
        </div>
      @endforeach
    </div>
  @endif
</div>
