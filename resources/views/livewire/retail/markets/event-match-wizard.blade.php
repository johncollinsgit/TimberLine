<div class="rounded-3xl border border-zinc-200 bg-white p-4 shadow-sm min-w-0 sm:p-6">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
    <div class="min-w-0">
      <div class="text-[11px] uppercase tracking-[0.35em] text-zinc-600">Markets Planner</div>
      <div class="mt-2 text-2xl font-['Fraunces'] font-semibold text-zinc-950 sm:text-3xl">Choose Event to Match</div>
      <div class="mt-2 max-w-2xl text-sm text-zinc-600">
        Stored events only. Pick an upcoming event, choose a historical match or starter template, build the draft, then publish.
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
        class="rounded-2xl border px-3 py-3 text-left transition disabled:cursor-not-allowed disabled:opacity-45 {{ $active ? 'border-zinc-300 bg-emerald-100 text-zinc-950' : 'border-zinc-200 bg-zinc-50 text-zinc-600 hover:bg-zinc-50' }}"
      >
        <div class="text-[10px] uppercase tracking-[0.28em] {{ $active ? 'text-zinc-600' : 'text-zinc-600' }}">Step {{ $number }}</div>
        <div class="mt-1 text-sm font-semibold">{{ $meta['label'] }}</div>
      </button>
    @endforeach
  </div>

  @if($step === 1)
    <div class="mt-5 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
      <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div class="text-[11px] uppercase tracking-[0.22em] text-zinc-600">Step 1</div>
          <div class="mt-1 text-lg font-semibold text-zinc-950">Choose Upcoming Event</div>
        </div>
        <div class="text-[11px] text-zinc-600">Showing the next 30 days of stored events.</div>
      </div>

      <div class="mt-4 space-y-3">
        @forelse($upcomingEvents as $event)
          @php($isSelected = (int)($selectedUpcomingEventId ?? 0) === (int)($event['id'] ?? 0))
          <button
            type="button"
            wire:key="wizard-upcoming-event-{{ (int)($event['id'] ?? 0) }}"
            wire:click="selectUpcomingEvent({{ (int)($event['id'] ?? 0) }})"
            class="w-full rounded-xl border px-5 py-4 text-left transition {{ $isSelected ? 'border-emerald-400/70 bg-emerald-100 shadow-[0_0_0_2px_rgba(16,185,129,0.25)]' : 'border-zinc-200 bg-zinc-50 hover:border-zinc-300 hover:bg-zinc-100' }}"
          >
            <div class="flex items-start justify-between gap-4">
              <div class="min-w-0">
                <div class="text-sm text-zinc-500">
                  {{ !empty($event['starts_at']) ? \Illuminate\Support\Carbon::parse($event['starts_at'])->format('M j, Y') : 'Date TBD' }}
                  @if(!empty($event['ends_at']) && $event['ends_at'] !== $event['starts_at'])
                    <span>– {{ \Illuminate\Support\Carbon::parse($event['ends_at'])->format('M j, Y') }}</span>
                  @endif
                </div>
                <div class="mt-1 text-lg text-zinc-950">{{ $event['display_name'] ?: $event['name'] ?: 'Untitled Event' }}</div>
                @if(!empty($event['city']) || !empty($event['state']) || !empty($event['venue']))
                  <div class="mt-2 text-sm text-zinc-500">
                    {{ trim(($event['city'] ?? '').', '.($event['state'] ?? ''), ' ,') ?: 'Location TBD' }}
                    @if(!empty($event['venue']))
                      <span class="block text-xs text-zinc-500">{{ $event['venue'] }}</span>
                    @endif
                  </div>
                @endif
              </div>
              @if((int)($event['draft_rows_count'] ?? 0) > 0)
                <div class="shrink-0 rounded-full border border-emerald-300/20 bg-emerald-100 px-3 py-1 text-xs text-zinc-600">
                  {{ (int)($event['draft_rows_count'] ?? 0) }} draft rows
                </div>
              @endif
            </div>

            @if($isSelected)
              <div class="mt-3 inline-flex items-center gap-2 text-xs text-emerald-200/90">
                <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                Selected
              </div>
            @endif
          </button>
        @empty
          <div class="rounded-2xl border border-dashed border-zinc-200 bg-emerald-50 p-4 text-sm text-zinc-600">
            No upcoming events were found in the current window.
          </div>
        @endforelse
      </div>
    </div>
  @endif

  @if($step === 2)
    <div class="mt-5 grid grid-cols-1 items-start gap-4 xl:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]">
      <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
        <div class="text-[11px] uppercase tracking-[0.22em] text-zinc-600">Selected Event</div>
        @if(!empty($upcomingEvent))
          <div class="mt-2 text-lg font-semibold text-zinc-950">{{ $upcomingEvent['display_name'] ?: $upcomingEvent['name'] ?: 'Untitled Event' }}</div>
          <div class="mt-2 text-xs text-zinc-600">
            {{ !empty($upcomingEvent['starts_at']) ? \Illuminate\Support\Carbon::parse($upcomingEvent['starts_at'])->format('M j, Y') : 'Date TBD' }}
            @if(!empty($upcomingEvent['ends_at']) && $upcomingEvent['ends_at'] !== $upcomingEvent['starts_at'])
              – {{ \Illuminate\Support\Carbon::parse($upcomingEvent['ends_at'])->format('M j, Y') }}
            @endif
          </div>
          @if(!empty($upcomingEvent['city']) || !empty($upcomingEvent['state']) || !empty($upcomingEvent['venue']))
            <div class="mt-2 text-xs text-zinc-600">
              {{ trim(($upcomingEvent['city'] ?? '').', '.($upcomingEvent['state'] ?? ''), ' ,') }}
              @if(!empty($upcomingEvent['venue']))
                <span class="mt-1 block">{{ $upcomingEvent['venue'] }}</span>
              @endif
            </div>
          @endif
        @else
          <div class="mt-2 text-sm text-zinc-600">Selected upcoming event ID: {{ (int)($upcomingEventId ?? 0) }}</div>
          <div class="mt-2 text-xs text-zinc-600">Event details are still loading or unavailable.</div>
        @endif

        <div class="mt-3 text-zinc-600">
          @if($selectedMatchId)
            <div class="text-sm">Match: <span class="text-zinc-950">{{ $this->selectedMatchLabel() }}</span></div>
          @elseif($selectedTemplateKey)
            <div class="text-sm">Template: <span class="text-zinc-950">{{ $this->selectedTemplateLabel() }}</span></div>
          @else
            <div class="text-sm text-zinc-500">No match/template selected yet.</div>
          @endif

          <div class="mt-1 text-sm">
            Draft size: <span class="font-semibold text-zinc-950">{{ (int)($draftBoxTotal ?? 0) }}</span> boxes
          </div>
        </div>

        @if(!empty($selectedCandidateEvent))
          <div class="mt-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
            <div class="text-[10px] uppercase tracking-[0.22em] text-zinc-600">Selected Match</div>
            <div class="mt-2 text-sm font-semibold text-zinc-950">{{ $selectedCandidateEvent['title'] }}</div>
            <div class="mt-1 text-[11px] text-zinc-600">
              {{ !empty($selectedCandidateEvent['starts_at']) ? \Illuminate\Support\Carbon::parse($selectedCandidateEvent['starts_at'])->format('M j, Y') : 'Date TBD' }}
              @if(!empty($selectedCandidateEvent['ends_at']) && $selectedCandidateEvent['ends_at'] !== $selectedCandidateEvent['starts_at'])
                – {{ \Illuminate\Support\Carbon::parse($selectedCandidateEvent['ends_at'])->format('M j, Y') }}
              @endif
              @if(!empty($selectedCandidateEvent['state']))
                · {{ $selectedCandidateEvent['state'] }}
              @endif
            </div>
          </div>
        @endif

        @if($uiError)
          <div class="mt-4 rounded-xl border border-red-500/30 bg-red-500/10 p-4 text-sm text-red-100">
            {{ $uiError }}
          </div>
        @endif
      </div>

      <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <div class="text-[11px] uppercase tracking-[0.22em] text-zinc-600">Historical Match</div>
            <div class="mt-1 text-sm text-zinc-600">Scan prior-year events, then choose a historical match or a starter template.</div>
          </div>
          <div class="grid w-full grid-cols-1 gap-2 sm:w-auto sm:min-w-[18rem] sm:grid-cols-2">
            <button
              type="button"
              wire:click="scanHistoricalMatches"
              wire:loading.attr="disabled"
              class="w-full rounded-xl border border-zinc-300 bg-zinc-50 px-5 py-3 text-sm text-zinc-950 disabled:cursor-not-allowed disabled:opacity-50 hover:bg-zinc-100"
            >
              <span wire:loading.remove wire:target="scanHistoricalMatches">Scan historical matches</span>
              <span wire:loading wire:target="scanHistoricalMatches">Scanning...</span>
            </button>

            <button
              type="button"
              wire:click="$toggle('templatesOpen')"
              class="w-full rounded-xl border border-zinc-300 bg-zinc-50 px-5 py-3 text-sm text-zinc-950 hover:bg-zinc-100"
            >
              Templates
            </button>
          </div>
        </div>

        @if(!empty($matches) && (int)($matches[0]['match_score_percent'] ?? 0) < 70)
          <div class="mt-4 rounded-xl border border-amber-300/20 bg-amber-100 px-4 py-3 text-sm text-amber-900">
            These matches look weak. Use a starter template if the suggested history is not useful.
          </div>
        @endif

        <div class="mt-4">
          @if(!$matchScanRan)
            <div class="rounded-xl border border-dashed border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
              Run the scan to rank historical box-plan templates within {{ (int) $matchWindowDays }} days of this event.
            </div>
          @elseif(empty($matches))
            <div class="rounded-xl border border-dashed border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
              No prior-year box-plan candidates were found. Open Templates to use a starter.
            </div>
          @else
            <div class="max-h-[34rem] overflow-y-auto pr-1">
              <div class="grid grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-3">
                @foreach($matches as $match)
                  @php($isSelected = (int)($selectedMatchId ?? 0) === (int)($match['event_id'] ?? 0))
                  <button
                    type="button"
                    wire:key="wizard-match-{{ (int)($match['event_id'] ?? 0) }}"
                    wire:click="selectMatch({{ (int)($match['event_id'] ?? 0) }})"
                    class="h-full w-full rounded-xl border p-4 text-left transition {{ $isSelected ? 'border-emerald-400/70 bg-emerald-100 shadow-[0_0_0_2px_rgba(16,185,129,0.25)]' : 'border-zinc-200 bg-zinc-50 hover:border-emerald-300/25 hover:bg-emerald-100' }}"
                  >
                    <div class="flex h-full flex-col gap-3">
                      <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                          <div class="text-xs text-zinc-600">{{ (int)($match['match_score_percent'] ?? 0) }}% match</div>
                          <div class="mt-1 text-sm font-medium text-zinc-950 break-words">{{ $match['title'] ?? 'Historical event' }}</div>
                          <div class="mt-1 text-[11px] text-zinc-600">
                            {{ !empty($match['starts_at']) ? \Illuminate\Support\Carbon::parse($match['starts_at'])->format('M j, Y') : 'Date TBD' }}
                            @if(!empty($match['ends_at']) && $match['ends_at'] !== $match['starts_at'])
                              – {{ \Illuminate\Support\Carbon::parse($match['ends_at'])->format('M j, Y') }}
                            @endif
                            @if(!empty($match['state']))
                              · {{ $match['state'] }}
                            @endif
                          </div>
                          <div class="mt-1 text-[11px] text-zinc-600">
                            Draft size {{ (int)($match['draft_box_total'] ?? 0) }} boxes
                          </div>
                          @if(!empty($match['notes_snippet']))
                            <div class="mt-2 text-[11px] text-zinc-600 break-words">{{ $match['notes_snippet'] }}</div>
                          @endif
                        </div>

                        @if($isSelected)
                          <div class="shrink-0 text-xs text-emerald-900">Selected</div>
                        @endif
                      </div>

                      @if(!empty($match['box_preview']))
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3">
                          <div class="mb-2 text-[10px] uppercase tracking-[0.2em] text-zinc-600">
                            {{ count($match['box_preview']) }} candle line{{ count($match['box_preview']) === 1 ? '' : 's' }}
                          </div>
                          <div class="max-h-40 space-y-1 overflow-y-auto pr-1 text-[11px] text-zinc-600">
                            @foreach($match['box_preview'] as $line)
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
                          </div>
                        </div>
                      @endif
                    </div>
                  </button>
                @endforeach
              </div>
            </div>
          @endif
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
          @if((int)($draftSummary['line_count'] ?? 0) > 0)
            <button
              type="button"
              wire:click="resetDraft"
              onclick="return confirm('Reset this event draft and remove all current rows?');"
              wire:loading.attr="disabled"
              wire:target="resetDraft"
              class="rounded-xl border border-rose-300/25 bg-rose-100 px-4 py-3 text-sm font-semibold text-rose-900 transition hover:bg-rose-500/16 disabled:cursor-not-allowed disabled:opacity-50"
            >
              Reset Draft
            </button>
          @endif

          <button
            type="button"
            wire:click="applyMatchAndBuildDraft"
            wire:loading.attr="disabled"
            wire:target="applyMatchAndBuildDraft"
            @if((int)($draftSummary['line_count'] ?? 0) > 0)
              onclick="return confirm('This will overwrite existing draft lines for this event. Continue?');"
            @endif
            @disabled(!$selectedMatchId && !$selectedTemplateKey)
            class="rounded-xl px-6 py-3 text-base font-semibold transition {{ ($selectedMatchId || $selectedTemplateKey) ? 'border border-emerald-300/30 bg-emerald-100 text-zinc-950 hover:bg-emerald-500/28' : 'border border-zinc-200 bg-zinc-50 text-zinc-500' }}"
          >
            <span wire:loading.remove wire:target="applyMatchAndBuildDraft">Apply Match And Build Draft</span>
            <span wire:loading wire:target="applyMatchAndBuildDraft">Applying...</span>
          </button>
        </div>
      </div>
    </div>
  @endif

  @if($step === 3)
    <div class="mt-5 space-y-4">
      <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
        <div class="min-w-0">
          <div class="text-[11px] uppercase tracking-[0.22em] text-zinc-600">Build Draft</div>
          <div class="mt-1 text-lg font-semibold text-zinc-950">{{ $upcomingEvent['display_name'] ?? $upcomingEvent['name'] ?? 'Select an event first' }}</div>
          <div class="mt-1 text-xs text-zinc-600">
            @if($selectedMatchId)
              Using {{ $this->selectedMatchLabel() }} as the historical box-plan source.
            @elseif($selectedTemplateKey)
              Using {{ $this->selectedTemplateLabel() }} as the starter template.
            @elseif((int)($draftSummary['line_count'] ?? 0) > 0)
              Using a starter template or existing draft for this event.
            @elseif($startFresh)
              Starting with an empty draft for this event.
            @else
              Select a historical match or template first.
            @endif
          </div>
        </div>

        <div class="mt-4 grid grid-cols-12 items-end gap-4">
          <div class="col-span-12 lg:col-span-8">
            <label class="text-xs uppercase tracking-[0.22em] text-zinc-600">Scent</label>
            <div class="mt-2 min-w-[20rem] max-w-full">
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
            <div class="mt-2 text-[11px] text-zinc-600">
              @if(!$selectedScentId)
                Select a scent to add boxes.
              @else
                Add 0.5 box, 1 box, or a Top Shelf line.
              @endif
            </div>
          </div>

          <div class="col-span-12 lg:col-span-4 lg:min-w-[15rem]">
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-1">
              <button
                type="button"
                wire:click="addHalfBox"
                wire:loading.attr="disabled"
                wire:target="addHalfBox"
                @disabled(!$upcomingEventId || !$selectedScentId)
                class="h-12 w-full whitespace-nowrap rounded-2xl border border-emerald-300/25 bg-emerald-100 px-4 text-sm font-semibold text-zinc-950 disabled:cursor-not-allowed disabled:opacity-50"
              >
                Add 0.5 Box
              </button>
              <button
                type="button"
                wire:click="addFullBox"
                wire:loading.attr="disabled"
                wire:target="addFullBox"
                @disabled(!$upcomingEventId || !$selectedScentId)
                class="h-12 w-full whitespace-nowrap rounded-2xl border border-zinc-300 bg-emerald-500/22 px-4 text-sm font-semibold text-zinc-950 disabled:cursor-not-allowed disabled:opacity-50"
              >
                Add 1 Box
              </button>
              <button
                type="button"
                wire:click="addTopShelf"
                wire:loading.attr="disabled"
                wire:target="addTopShelf"
                @disabled(!$upcomingEventId)
                class="h-12 w-full whitespace-nowrap rounded-2xl border border-amber-300/25 bg-amber-100 px-4 text-sm font-semibold text-amber-900 shadow-[0_8px_30px_-18px_rgba(251,191,36,0.7)] disabled:cursor-not-allowed disabled:opacity-50 sm:col-span-2 lg:col-span-1"
              >
                Add Top Shelf (16oz)
              </button>
            </div>
          </div>
        </div>
      </div>

      <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
        <div class="text-[11px] uppercase tracking-[0.22em] text-zinc-600">Draft Contents</div>
        <div class="mt-1 text-xs text-zinc-600">Everything below is scoped to the selected upcoming event.</div>
        @if(!empty($prefillStatus['message']))
          @php($prefillState = (string)($prefillStatus['state'] ?? 'info'))
          <div class="mt-4 rounded-2xl border px-4 py-3 text-sm
            {{ in_array($prefillState, ['missing_mappings', 'error'], true)
              ? 'border-rose-300/20 bg-rose-100 text-rose-900'
              : (in_array($prefillState, ['no_history_rows', 'start_fresh'], true)
                  ? 'border-amber-300/20 bg-amber-100 text-amber-900'
                  : 'border-emerald-300/20 bg-emerald-100 text-emerald-900') }}">
            {{ $prefillStatus['message'] }}
            @if($prefillState === 'applied' && (int)($prefillStatus['template_row_count'] ?? 0) > 0)
              <div class="mt-1 text-xs text-zinc-600">
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

        <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
          <button
            type="button"
            wire:click="resetDraft"
            onclick="return confirm('Reset this event draft and remove all current rows?');"
            wire:loading.attr="disabled"
            wire:target="resetDraft"
            @disabled((int)($draftSummary['line_count'] ?? 0) <= 0)
            class="rounded-xl border border-rose-300/25 bg-rose-100 px-4 py-2.5 text-xs font-semibold text-rose-900 transition hover:bg-rose-500/16 disabled:cursor-not-allowed disabled:opacity-40"
          >
            Reset Draft
          </button>

          <button
            type="button"
            wire:click="goToStep(4)"
            @disabled(!$this->canAccessStep(4))
            class="rounded-xl border px-6 py-3 text-sm font-semibold transition {{ $this->canAccessStep(4) ? 'border-emerald-300/30 bg-emerald-100 text-zinc-950 hover:bg-emerald-500/28' : 'border-zinc-200 bg-zinc-50 text-zinc-500' }}"
          >
            Submit To Review
          </button>
        </div>
      </div>
    </div>
  @endif

  @if($step === 4)
    <div class="mt-5 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
      <div class="text-[11px] uppercase tracking-[0.22em] text-zinc-600">Review</div>
      <div class="mt-2 text-lg font-semibold text-zinc-950">{{ $upcomingEvent['display_name'] ?? $upcomingEvent['name'] ?? 'No event selected' }}</div>
      <div class="mt-2 text-sm text-zinc-600">
        Match source:
        @if($selectedMatchId)
          <span class="text-zinc-950">{{ $this->selectedMatchLabel() }}</span>
        @elseif($selectedTemplateKey)
          <span class="text-zinc-950">{{ $this->selectedTemplateLabel() }}</span>
        @elseif((int)($draftSummary['line_count'] ?? 0) > 0)
          <span class="text-zinc-950">Existing event draft</span>
        @elseif($startFresh)
          <span class="text-zinc-950">None, built from scratch</span>
        @else
          <span class="text-zinc-950">Not selected</span>
        @endif
      </div>

      <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
          <div class="text-[10px] uppercase tracking-[0.22em] text-zinc-600">Draft Lines</div>
          <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ (int)($draftSummary['line_count'] ?? 0) }}</div>
        </div>
        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
          <div class="text-[10px] uppercase tracking-[0.22em] text-zinc-600">Total Boxes</div>
          @php($totalBoxes = (float)($draftSummary['total_boxes'] ?? 0))
          <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ rtrim(rtrim(number_format($totalBoxes, 1), '0'), '.') }}</div>
        </div>
        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
          <div class="text-[10px] uppercase tracking-[0.22em] text-zinc-600">Standard Lines</div>
          <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ (int)($draftSummary['standard_line_count'] ?? 0) }}</div>
        </div>
        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
          <div class="text-[10px] uppercase tracking-[0.22em] text-zinc-600">Top Shelf Lines</div>
          <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ (int)($draftSummary['top_shelf_line_count'] ?? 0) }}</div>
        </div>
      </div>

      <div class="mt-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
        <div class="text-[10px] uppercase tracking-[0.22em] text-zinc-600">Candle Breakdown</div>
        @if(empty($draftSummary['breakdown']))
          <div class="mt-2 text-sm text-zinc-600">No candle rows available yet.</div>
        @else
          <div class="mt-3 space-y-2">
            @foreach(($draftSummary['breakdown'] ?? []) as $row)
              @php($standardBoxes = (float)($row['standard_boxes'] ?? 0))
              @php($topShelfUnits = (int)($row['top_shelf_units'] ?? 0))
              <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-600">
                <div class="font-medium text-zinc-950">{{ $row['name'] ?? 'Unknown scent' }}</div>
                <div class="flex items-center gap-3 text-xs text-zinc-600">
                  <span>Standard: {{ rtrim(rtrim(number_format($standardBoxes, 1), '0'), '.') }} boxes</span>
                  <span>Top Shelf: {{ $topShelfUnits }} units</span>
                </div>
              </div>
            @endforeach
          </div>
        @endif
      </div>

      <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-wrap items-center gap-2">
          <button
            type="button"
            wire:click="goToStep(2)"
            class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-2.5 text-xs font-semibold text-zinc-900 hover:bg-zinc-100"
          >
            Back To Match
          </button>
          <button
            type="button"
            wire:click="goToStep(3)"
            class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-2.5 text-xs font-semibold text-zinc-900 hover:bg-zinc-100"
          >
            Back To Build Boxes
          </button>
        </div>

        <button
          type="button"
          wire:click="publish"
          onclick="return confirm('Publish this draft to the pouring room?');"
          wire:loading.attr="disabled"
          wire:target="publish"
          @disabled(!$upcomingEventId || !$this->canAccessStep(4))
          class="self-end rounded-2xl border border-emerald-400/40 bg-emerald-500/25 px-6 py-3 text-sm font-semibold text-zinc-950 disabled:cursor-not-allowed disabled:opacity-50"
        >
          Publish To Pouring Room
        </button>
      </div>
    </div>
  @endif

  @if($step === 1)
    <div class="sticky bottom-0 mt-6 flex items-center justify-between rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
      <button
        type="button"
        wire:click="goBack"
        @disabled($step <= 1)
        class="rounded-xl border border-zinc-300 bg-zinc-50 px-6 py-3 text-base text-zinc-950 disabled:cursor-not-allowed disabled:opacity-45 hover:bg-zinc-100"
      >
        Back
      </button>

      <button
        type="button"
        wire:click="goNextFromStep1"
        @disabled(!$selectedUpcomingEventId)
        class="rounded-xl px-8 py-3 text-base font-semibold {{ $selectedUpcomingEventId ? 'bg-emerald-600 text-zinc-950 hover:bg-emerald-500' : 'bg-zinc-100 text-zinc-500 cursor-not-allowed' }}"
      >
        Next →
      </button>
    </div>
  @else
    <div class="mt-5 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <button
        type="button"
        wire:click="goBack"
        @disabled($step <= 1)
        class="rounded-xl border border-zinc-200 bg-zinc-50 px-5 py-3 text-base text-zinc-600 disabled:cursor-not-allowed disabled:opacity-45"
      >
        Back
      </button>

      @if($step === 2)
        <div class="text-[11px] text-zinc-600">
          Scan history, choose a match or template, then build the draft.
        </div>
      @elseif($step === 3 && !$this->canAccessStep(4))
        <div class="text-[11px] text-zinc-600">
          Step 4 unlocks after every row has quantity plus complete scent setup (Top Shelf must be fully filled).
        </div>
      @endif
    </div>
  @endif

  @if($templatesOpen)
    <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center">
      <div class="absolute inset-0 fb-overlay-subtle" wire:click="$set('templatesOpen', false)"></div>

      <div class="relative mx-auto w-full rounded-2xl border border-zinc-200 bg-white p-6 shadow-md sm:max-w-3xl">
        <div class="mb-4 flex items-center justify-between gap-4">
          <div class="text-lg text-zinc-950">Quick Start Templates</div>
          <button type="button" class="text-zinc-500 hover:text-zinc-950" wire:click="$set('templatesOpen', false)">X</button>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          @foreach($templates as $tpl)
            @php($sel = $selectedTemplateKey === ($tpl['key'] ?? null))
            <button
              type="button"
              wire:key="wizard-template-{{ (string)($tpl['key'] ?? '') }}"
              wire:click="selectTemplate('{{ (string)($tpl['key'] ?? '') }}')"
              class="rounded-xl border p-4 text-left transition {{ $sel ? 'border-emerald-400/70 bg-emerald-100' : 'border-zinc-200 bg-zinc-50 hover:bg-zinc-100' }}"
            >
              <div class="font-semibold text-zinc-950">{{ $tpl['title'] ?? 'Starter Template' }}</div>
              <div class="mt-1 text-sm text-zinc-500">{{ $tpl['meta'] ?? '' }}</div>
              <div class="mt-2 text-xs text-zinc-600">{{ (int)($tpl['draft_box_total'] ?? 0) }} box draft target</div>
            </button>
          @endforeach
        </div>
      </div>
    </div>
  @endif
</div>
