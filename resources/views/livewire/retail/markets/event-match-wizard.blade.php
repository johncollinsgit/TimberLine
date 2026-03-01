<div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-4 sm:p-6 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)] min-w-0">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
    <div class="min-w-0">
      <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Markets Planner</div>
      <div class="mt-2 text-2xl sm:text-3xl font-['Fraunces'] font-semibold text-white">One Event At A Time</div>
      <div class="mt-2 max-w-2xl text-sm text-emerald-50/70">
        Stored events only. Pick an upcoming event, choose a historical match if you want one, build the draft, then publish.
      </div>
    </div>
    <div class="w-full max-w-md">
      @livewire(
        \App\Livewire\Retail\MarketsSyncStatus::class,
        ['planId' => $planId, 'queue' => 'markets'],
        key('markets-sync-status-stepper-'.(int) $planId)
      )
    </div>
  </div>

  <div class="mt-5 grid grid-cols-2 gap-2 lg:grid-cols-4">
    @foreach($steps as $number => $meta)
      @php($active = $step === $number)
      <button
        type="button"
        wire:click="goToStep({{ $number }})"
        @disabled(!($meta['ready'] ?? false))
        class="rounded-2xl border px-3 py-3 text-left transition disabled:cursor-not-allowed disabled:opacity-45 {{ $active ? 'border-emerald-300/35 bg-emerald-500/16 text-white' : 'border-emerald-200/10 bg-black/15 text-emerald-100/70 hover:bg-white/5' }}"
      >
        <div class="text-[10px] uppercase tracking-[0.28em] {{ $active ? 'text-emerald-50/90' : 'text-emerald-100/45' }}">Step {{ $number }}</div>
        <div class="mt-1 text-sm font-semibold">{{ $meta['label'] }}</div>
      </button>
    @endforeach
  </div>

  @if($step === 1)
    <div class="mt-5">
      @livewire(
        \App\Livewire\Retail\Markets\UpcomingEventsPanel::class,
        [
          'planId' => $planId,
          'selectedEventId' => $upcomingEventId,
          'stateTab' => 'needs_mapping',
          'lookaheadDays' => 30,
        ],
        key('markets-upcoming-events-stepper-'.(int)$planId.'-'.(int)($upcomingEventId ?? 0))
      )
    </div>
  @endif

  @if($step === 2)
    <div class="mt-5 grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,18rem)_minmax(0,1fr)]">
      <div class="rounded-2xl border border-emerald-200/10 bg-black/15 p-4">
        <div class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Selected Event</div>
        @if(!empty($upcomingEvent))
          <div class="mt-2 text-lg font-semibold text-white">{{ $upcomingEvent['display_name'] ?: $upcomingEvent['name'] ?: 'Untitled Event' }}</div>
          <div class="mt-2 text-xs text-emerald-100/60">
            {{ !empty($upcomingEvent['starts_at']) ? \Illuminate\Support\Carbon::parse($upcomingEvent['starts_at'])->format('M j, Y') : 'Date TBD' }}
            @if(!empty($upcomingEvent['ends_at']) && $upcomingEvent['ends_at'] !== $upcomingEvent['starts_at'])
              – {{ \Illuminate\Support\Carbon::parse($upcomingEvent['ends_at'])->format('M j, Y') }}
            @endif
          </div>
          @if(!empty($upcomingEvent['city']) || !empty($upcomingEvent['state']) || !empty($upcomingEvent['venue']))
            <div class="mt-2 text-xs text-emerald-100/55">
              {{ trim(($upcomingEvent['city'] ?? '').', '.($upcomingEvent['state'] ?? ''), ' ,') }}
              @if(!empty($upcomingEvent['venue']))
                <span class="block mt-1">{{ $upcomingEvent['venue'] }}</span>
              @endif
            </div>
          @endif
        @else
          <div class="mt-2 text-sm text-emerald-50/70">Selected upcoming event ID: {{ (int)($upcomingEventId ?? 0) }}</div>
          <div class="mt-2 text-xs text-emerald-100/55">Event details are still loading or unavailable. You can still scan historical matches.</div>
        @endif
      </div>

      <div class="rounded-2xl border border-emerald-200/10 bg-black/15 p-4">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
          <div>
            <div class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Historical Match</div>
            <div class="mt-1 text-sm text-emerald-50/70">Run a local match scan across a 45-day prior-year window, then load the closest historical box-plan template or start clean.</div>
          </div>
          <button
            type="button"
            wire:click="runMatchSearch"
            @disabled(!$upcomingEventId)
            class="rounded-xl border border-emerald-300/25 bg-emerald-500/10 px-4 py-2 text-sm text-white/90 disabled:cursor-not-allowed disabled:opacity-50"
          >
            Scan Historical Matches
          </button>
        </div>

        <div class="mt-4">
          @livewire(
            \App\Livewire\Retail\Markets\CandidateMatchList::class,
            [
              'upcomingEventId' => $upcomingEventId,
              'selectedCandidateEventId' => $selectedCandidateEventId,
              'matchWindowDays' => $matchWindowDays,
            ],
            key('candidate-match-list-stepper-'.(int)($upcomingEventId ?? 0).'-'.(int)$matchWindowDays)
          )
        </div>

        @if(!empty($selectedCandidateEvent))
          <div class="mt-4 rounded-2xl border border-emerald-200/10 bg-black/20 p-4">
            <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/45">Selected Match</div>
            <div class="mt-2 text-sm font-semibold text-white">{{ $selectedCandidateEvent['title'] }}</div>
            <div class="mt-1 text-[11px] text-emerald-100/55">
              {{ !empty($selectedCandidateEvent['starts_at']) ? \Illuminate\Support\Carbon::parse($selectedCandidateEvent['starts_at'])->format('M j, Y') : 'Date TBD' }}
              @if(!empty($selectedCandidateEvent['ends_at']) && $selectedCandidateEvent['ends_at'] !== $selectedCandidateEvent['starts_at'])
                – {{ \Illuminate\Support\Carbon::parse($selectedCandidateEvent['ends_at'])->format('M j, Y') }}
              @endif
              @if(!empty($selectedCandidateEvent['state']))
                · {{ $selectedCandidateEvent['state'] }}
              @endif
            </div>
            @if(!empty($selectedCandidateEvent['notes_snippet']))
              <div class="mt-2 text-[11px] text-emerald-100/55">{{ $selectedCandidateEvent['notes_snippet'] }}</div>
            @endif
            @if(!empty($selectedCandidateEvent['box_preview']))
              <div class="mt-3 space-y-1 rounded-xl border border-emerald-200/10 bg-black/10 p-3 text-[11px] text-emerald-50/80">
                @foreach($selectedCandidateEvent['box_preview'] as $line)
                  <div class="flex items-center justify-between gap-3">
                    <span class="truncate">{{ $line['scent_raw'] }}</span>
                    <span class="shrink-0">
                      {{ $line['box_count_sent'] !== null ? rtrim(rtrim(number_format((float) $line['box_count_sent'], 2), '0'), '.') : '—' }}
                      @if(!empty($line['is_split_box']))
                        <span class="text-amber-100/70">split</span>
                      @endif
                    </span>
                  </div>
                @endforeach
                @if((int)($selectedCandidateEvent['box_plan_count'] ?? 0) > count($selectedCandidateEvent['box_preview']))
                  <div class="text-[10px] text-emerald-100/45">+{{ (int)($selectedCandidateEvent['box_plan_count'] ?? 0) - count($selectedCandidateEvent['box_preview']) }} more lines</div>
                @endif
              </div>
            @endif
          </div>
        @endif

        <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:justify-end">
          <button
            type="button"
            wire:click="startFreshDraft"
            @disabled(!$upcomingEventId)
            class="rounded-xl border border-white/10 bg-black/20 px-4 py-2 text-sm text-emerald-50/85 disabled:cursor-not-allowed disabled:opacity-50"
          >
            No Match, Start Fresh
          </button>
          <button
            type="button"
            wire:click="useSelectedMatch"
            @if((int)($draftSummary['line_count'] ?? 0) > 0)
              onclick="return confirm('This will overwrite existing draft lines for this event. Continue?');"
            @endif
            @disabled(!$selectedCandidateEventId)
            class="rounded-xl border border-emerald-300/30 bg-emerald-500/20 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
          >
            Apply Match & Build Draft
          </button>
        </div>
      </div>
    </div>
  @endif

  @if($step === 3)
    <div class="mt-5 space-y-4">
      <div class="rounded-2xl border border-emerald-200/10 bg-black/15 p-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <div class="min-w-0">
            <div class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Build Draft</div>
            <div class="mt-1 text-lg font-semibold text-white">{{ $upcomingEvent['display_name'] ?? $upcomingEvent['name'] ?? 'Select an event first' }}</div>
            <div class="mt-1 text-xs text-emerald-100/60">
              @if(!empty($selectedCandidateEvent))
                Using {{ $selectedCandidateEvent['title'] }} as the historical box-plan source.
              @elseif((int)($draftSummary['line_count'] ?? 0) > 0)
                Using a starter template or existing draft for this event.
              @elseif($startFresh)
                Starting with an empty draft for this event.
              @else
                Select a historical match or start fresh first.
              @endif
            </div>
          </div>
          <div class="flex w-full flex-col gap-3 lg:w-auto lg:min-w-[52rem]">
            <div class="md:flex md:flex-row md:items-end md:gap-4">
              <div class="w-full flex-1 min-w-[16rem] md:min-w-[22rem]">
              <label class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">Scent</label>
              <div class="mt-2">
                @livewire(
                  \App\Livewire\Components\ScentCombobox::class,
                  [
                    'emitKey' => 'markets-stepper',
                    'selectedId' => (int)($selectedScentId ?? 0),
                    'allowWholesaleCustom' => false,
                    'placeholder' => 'Search scents for this draft…',
                  ],
                  key('markets-stepper-scent-'.(int)$planId.'-'.(int)($upcomingEventId ?? 0))
                )
              </div>
              @if(!$selectedScentId)
                <div class="mt-2 text-[11px] text-emerald-100/50">Select a scent to add boxes.</div>
              @endif
              </div>
              <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap md:justify-end md:shrink-0">
                <button
                  type="button"
                  wire:click="addHalfBox"
                  @disabled(!$upcomingEventId || !$selectedScentId)
                  class="w-full rounded-2xl border border-emerald-300/25 bg-emerald-500/10 px-4 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                >
                  Add Half Box
                </button>
                <button
                  type="button"
                  wire:click="addFullBox"
                  @disabled(!$upcomingEventId || !$selectedScentId)
                  class="w-full rounded-2xl border border-emerald-300/35 bg-emerald-500/22 px-4 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                >
                  Add Full Box
                </button>
                <button
                  type="button"
                  wire:click="addTopShelf"
                  @disabled(!$upcomingEventId)
                  class="w-full rounded-2xl border border-amber-300/25 bg-amber-500/15 px-4 py-3 text-sm font-semibold text-amber-50 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                >
                  Add Top Shelf
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="rounded-2xl border border-emerald-200/10 bg-black/15 p-4">
        <div class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Draft Contents</div>
        <div class="mt-1 text-xs text-emerald-100/60">Everything below is scoped to the selected upcoming event.</div>
        @if(!empty($prefillStatus['message']))
          @php($prefillState = (string)($prefillStatus['state'] ?? 'info'))
          <div class="mt-4 rounded-2xl border px-4 py-3 text-sm
            {{ in_array($prefillState, ['missing_mappings', 'error'], true)
              ? 'border-rose-300/20 bg-rose-500/10 text-rose-50'
              : (in_array($prefillState, ['no_history_rows', 'start_fresh'], true)
                  ? 'border-amber-300/20 bg-amber-500/10 text-amber-50'
                  : 'border-emerald-300/20 bg-emerald-500/10 text-emerald-50') }}">
            {{ $prefillStatus['message'] }}
            @if($prefillState === 'applied' && (int)($prefillStatus['template_row_count'] ?? 0) > 0)
              <div class="mt-1 text-xs text-emerald-100/75">
                {{ (int)($prefillStatus['template_row_count'] ?? 0) }} historical template row{{ (int)($prefillStatus['template_row_count'] ?? 0) === 1 ? '' : 's' }} copied into this draft.
              </div>
            @endif
          </div>
        @endif
        <div class="mt-4">
          @livewire(
            \App\Livewire\Retail\Markets\DraftEventEditor::class,
            [
              'planId' => $planId,
              'selectedEventId' => $upcomingEventId,
            ],
            key('markets-stepper-draft-editor-'.(int)$planId.'-'.(int)($upcomingEventId ?? 0))
          )
        </div>
      </div>
    </div>
  @endif

  @if($step === 4)
    <div class="mt-5 grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
      <div class="rounded-2xl border border-emerald-200/10 bg-black/15 p-4">
        <div class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Review</div>
        <div class="mt-2 text-lg font-semibold text-white">{{ $upcomingEvent['display_name'] ?? $upcomingEvent['name'] ?? 'No event selected' }}</div>
        <div class="mt-2 text-sm text-emerald-50/75">
          Match source:
          @if(!empty($selectedCandidateEvent))
            <span class="text-white">{{ $selectedCandidateEvent['title'] }}</span>
          @elseif((int)($draftSummary['line_count'] ?? 0) > 0)
            <span class="text-white">Starter template or existing draft</span>
          @elseif($startFresh)
            <span class="text-white">None, built from scratch</span>
          @else
            <span class="text-white">Not selected</span>
          @endif
        </div>

        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div class="rounded-2xl border border-white/8 bg-black/20 p-4">
            <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/45">Draft Lines</div>
            <div class="mt-2 text-2xl font-semibold text-white">{{ (int)($draftSummary['line_count'] ?? 0) }}</div>
          </div>
          <div class="rounded-2xl border border-white/8 bg-black/20 p-4">
            <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/45">Half-Box Units</div>
            <div class="mt-2 text-2xl font-semibold text-white">{{ (int)($draftSummary['unit_count'] ?? 0) }}</div>
          </div>
          <div class="rounded-2xl border border-white/8 bg-black/20 p-4">
            <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/45">Top Scents</div>
            <div class="mt-2 text-sm text-white">
              @if(empty($draftSummary['top_scents']))
                No scents added yet
              @else
                {{ collect($draftSummary['top_scents'])->pluck('name')->implode(', ') }}
              @endif
            </div>
          </div>
        </div>

        @if(!empty($draftSummary['top_scents']))
          <div class="mt-4 rounded-2xl border border-emerald-200/10 bg-black/20 p-4">
            <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/45">Top Scent Mix</div>
            <div class="mt-3 grid gap-2 sm:grid-cols-2">
              @foreach($draftSummary['top_scents'] as $row)
                <div class="rounded-xl border border-white/8 bg-white/5 px-3 py-2 text-sm text-emerald-50/85">
                  {{ $row['name'] }} <span class="text-emerald-100/45">· {{ (int)($row['units'] ?? 0) }} units</span>
                </div>
              @endforeach
            </div>
          </div>
        @endif
      </div>

      <div class="rounded-2xl border border-emerald-200/10 bg-black/15 p-4">
        <div class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Publish</div>
        <div class="mt-2 text-sm text-emerald-50/70">
          Publish only when the draft looks correct. This moves the selected event into the pouring flow and resets the planner for the next event.
        </div>
        <button
          type="button"
          wire:click="publish"
          onclick="return confirm('Publish this draft to the pouring room?');"
          @disabled(!$upcomingEventId || (int)($draftSummary['line_count'] ?? 0) <= 0)
          class="mt-4 w-full rounded-2xl border border-emerald-400/40 bg-emerald-500/25 px-4 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
        >
          Publish To Pouring Room
        </button>
      </div>
    </div>
  @endif

  <div class="mt-5 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
    <button
      type="button"
      wire:click="back"
      @disabled($step <= 1)
      class="rounded-xl border border-white/10 bg-black/20 px-4 py-2 text-sm text-emerald-50/85 disabled:cursor-not-allowed disabled:opacity-45"
    >
      Back
    </button>

    @if($step === 1)
      <div class="text-[11px] text-emerald-100/50">
        Select an upcoming event to continue.
      </div>
    @elseif($step === 2)
      <div class="text-[11px] text-emerald-100/50">
        Choose a match or start fresh to build the draft.
      </div>
    @elseif($step === 3 && !$this->canAccessStep(4))
      <div class="text-[11px] text-emerald-100/50">
        Step 4 unlocks once this event has at least one draft line.
      </div>
    @endif
  </div>
</div>
