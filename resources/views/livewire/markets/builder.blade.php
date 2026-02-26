<div class="space-y-6">
  <section class="relative overflow-hidden rounded-3xl border border-emerald-200/10 bg-[#101513]/90 p-6 sm:p-7">
    <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(16,185,129,0.14),transparent_55%),radial-gradient(circle_at_bottom_left,rgba(255,255,255,0.04),transparent_45%)]"></div>
    <div class="relative flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
      <div class="space-y-2">
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Markets Planner</div>
        <h1 class="text-3xl font-['Fraunces'] font-semibold text-white sm:text-4xl">Build Market Boxes</h1>
        <p class="max-w-2xl text-sm text-emerald-50/70">
          Pull upcoming events from the Asana calendar, preload prior box plans, and publish only expanded candle quantities to the Pouring Room.
        </p>
      </div>

      <div class="grid w-full max-w-sm grid-cols-2 gap-3 rounded-2xl border border-white/5 bg-black/20 p-3">
        <div class="rounded-xl border border-white/5 bg-white/5 p-3">
          <div class="text-[10px] uppercase tracking-[0.25em] text-emerald-100/50">Draft Lines</div>
          <div class="mt-1 text-2xl font-semibold text-white">{{ count($draftEntryRows) }}</div>
        </div>
        <div class="rounded-xl border border-white/5 bg-white/5 p-3">
          <div class="text-[10px] uppercase tracking-[0.25em] text-emerald-100/50">Current Step</div>
          <div class="mt-1 text-sm font-semibold text-white">{{ $stepMeta[(string) $step] ?? 'Wizard' }}</div>
        </div>
      </div>
    </div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-4 sm:p-5">
    <div class="grid grid-cols-1 gap-2 md:grid-cols-5">
      @foreach($stepMeta as $stepNumber => $label)
        @php($stepInt = (int) $stepNumber)
        @php($isActive = $step === $stepInt)
        @php($isDone = $step > $stepInt)
        <button
          type="button"
          wire:click="goToStep({{ $stepInt }})"
          class="group rounded-2xl border px-4 py-3 text-left transition {{ $isActive ? 'border-emerald-300/35 bg-emerald-500/15 shadow-[0_8px_24px_rgba(16,185,129,0.08)]' : ($isDone ? 'border-emerald-200/15 bg-emerald-500/5 hover:bg-emerald-500/10' : 'border-white/5 bg-black/15 hover:bg-white/5') }}"
        >
          <div class="flex items-center gap-2">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold {{ $isActive ? 'bg-emerald-300/20 text-emerald-50 border border-emerald-200/35' : ($isDone ? 'bg-emerald-400/20 text-emerald-50 border border-emerald-200/20' : 'bg-white/5 text-emerald-100/60 border border-white/10') }}">
              {{ $stepInt }}
            </span>
            <span class="text-xs uppercase tracking-[0.22em] {{ $isActive ? 'text-emerald-100/85' : 'text-emerald-100/45' }}">Step {{ $stepInt }}</span>
          </div>
          <div class="mt-2 text-sm font-medium {{ $isActive ? 'text-white' : 'text-emerald-50/75' }}">{{ $label }}</div>
        </button>
      @endforeach
    </div>
  </section>

  @if(!empty($syncSummary))
    <section class="rounded-2xl border border-emerald-300/20 bg-emerald-500/10 p-4 text-sm text-emerald-50/90">
      <div class="font-semibold">Calendar sync complete</div>
      <div class="mt-1">
        Fetched {{ $syncSummary['fetched'] ?? 0 }} event(s), upserted {{ $syncSummary['upserted'] ?? 0 }} upcoming market event(s).
      </div>
    </section>
  @endif

  @if(!empty($matchSummary['matched']))
    <section class="rounded-2xl border border-emerald-300/15 bg-white/5 p-4 text-sm text-emerald-50/85">
      <div class="font-semibold text-white">Matched prior event for preload</div>
      <div class="mt-1">
        Loaded {{ $matchSummary['rows_loaded'] ?? 0 }} line(s) from
        <span class="font-medium text-white">{{ $matchSummary['source_event_title'] ?? 'previous market' }}</span>
        @if(!empty($matchSummary['source_event_date']))
          ({{ $matchSummary['source_event_date'] }})
        @endif
        at {{ $matchSummary['score_percent'] ?? 0 }}% similarity.
      </div>
    </section>
  @endif

  @if($step === 1)
    <section class="grid grid-cols-1 gap-6 xl:grid-cols-[340px_minmax(0,1fr)]">
      <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5 sm:p-6">
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Sync Upcoming</div>
        <h2 class="mt-2 text-2xl font-['Fraunces'] font-semibold text-white">Asana Calendar Feed</h2>
        <p class="mt-2 text-sm text-emerald-50/65">
          Pull upcoming events from Google Calendar and map them to stored markets before choosing one to plan.
        </p>

        <div class="mt-5 space-y-4">
          <div>
            <label class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">Weeks Ahead</label>
            <select
              wire:model.live="weeksAhead"
              class="mt-2 w-full rounded-xl border border-white/10 bg-black/25 px-3 py-2 text-sm text-white focus:border-emerald-300/30 focus:outline-none"
            >
              @foreach([2,4,6,8,12] as $weeks)
                <option value="{{ $weeks }}">{{ $weeks }} weeks</option>
              @endforeach
            </select>
          </div>

          <button
            type="button"
            wire:click="syncUpcomingEvents"
            class="inline-flex w-full items-center justify-center rounded-xl border border-emerald-300/30 bg-emerald-500/15 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-emerald-500/20"
          >
            Sync Upcoming Events
          </button>
        </div>
      </div>

      <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Step 1</div>
            <h2 class="mt-2 text-2xl font-['Fraunces'] font-semibold text-white">Choose Upcoming Event</h2>
          </div>
          <div class="rounded-full border border-white/10 bg-black/20 px-3 py-1 text-xs text-emerald-100/65">
            {{ $events->count() }} upcoming candidate{{ $events->count() === 1 ? '' : 's' }}
          </div>
        </div>

        <div class="mt-5 space-y-3">
          @forelse($events as $event)
            @php($isSelected = (int) $selectedEventId === (int) $event->id)
            <button
              type="button"
              wire:click="selectEvent({{ $event->id }})"
              class="w-full rounded-2xl border px-4 py-4 text-left transition {{ $isSelected ? 'border-emerald-300/35 bg-emerald-500/10' : 'border-white/8 bg-black/15 hover:bg-white/5' }}"
            >
              <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                  <div class="truncate text-base font-medium text-white">
                    {{ $event->display_name ?: $event->name ?: 'Untitled Market Event' }}
                  </div>
                  <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-emerald-100/60">
                    <span>
                      {{ $event->starts_at?->format('M j, Y') ?? 'Date TBD' }}
                      @if($event->ends_at && optional($event->ends_at)->toDateString() !== optional($event->starts_at)->toDateString())
                        – {{ $event->ends_at?->format('M j, Y') }}
                      @endif
                    </span>
                    @if($event->market?->name)
                      <span>{{ $event->market->name }}</span>
                    @endif
                    @if($event->city || $event->state)
                      <span>{{ trim(($event->city ? $event->city.', ' : '').($event->state ?? ''), ', ') }}</span>
                    @endif
                  </div>
                  @if($event->venue)
                    <div class="mt-2 text-xs text-emerald-100/50">{{ $event->venue }}</div>
                  @endif
                </div>
                <div class="shrink-0">
                  <span class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-emerald-50/75">
                    {{ $isSelected ? 'Selected' : 'Use Event' }}
                  </span>
                </div>
              </div>
            </button>
          @empty
            <div class="rounded-2xl border border-dashed border-white/10 bg-black/15 p-6 text-center text-sm text-emerald-50/65">
              No upcoming events found yet. Run a sync to pull from the Asana/Google Calendar feed.
            </div>
          @endforelse
        </div>
      </div>
    </section>
  @elseif($step === 2)
    <section class="grid grid-cols-1 gap-6 xl:grid-cols-[420px_minmax(0,1fr)]">
      <div class="space-y-6">
        <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5 sm:p-6">
          <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Selected Event</div>
          <h2 class="mt-2 text-2xl font-['Fraunces'] font-semibold text-white">
            {{ $selectedEvent?->display_name ?: $selectedEvent?->name ?: 'Choose an event' }}
          </h2>
          <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-emerald-100/60">
            <span>{{ $selectedEvent?->starts_at?->format('M j, Y') ?? 'Date TBD' }}</span>
            @if($selectedEvent?->market?->name)
              <span>{{ $selectedEvent->market->name }}</span>
            @endif
            @if($selectedEvent?->venue)
              <span>{{ $selectedEvent->venue }}</span>
            @endif
          </div>
        </div>

        <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5 sm:p-6">
          <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Step 2</div>
          <h3 class="mt-2 text-xl font-['Fraunces'] font-semibold text-white">Add Box Line</h3>
          <p class="mt-2 text-sm text-emerald-50/65">
            Add market box lines by scent. Expansion into candle counts happens in the service layer and is previewed below.
          </p>

          <div class="mt-5 space-y-4">
            <div>
              <label class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">Scent</label>
              <div class="mt-2">
                <livewire:components.scent-combobox
                  emit-key="market-plan-wizard"
                  :selected-id="(int)($entryScentId ?? 0)"
                  :allow-wholesale-custom="false"
                  wire:key="market-plan-scent-{{ (int)($selectedEventId ?? 0) }}-{{ count($draftEntries) }}"
                />
              </div>
              @error('entryScentId')
                <div class="mt-2 text-xs text-rose-200/90">{{ $message }}</div>
              @enderror
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">Box Type</label>
                <select
                  wire:model.live="entryBoxType"
                  class="mt-2 w-full rounded-xl border border-white/10 bg-black/25 px-3 py-2 text-sm text-white focus:border-emerald-300/30 focus:outline-none"
                >
                  <option value="full">Full Box</option>
                  <option value="half">Half Box</option>
                  <option value="top_shelf">Top Shelf Box</option>
                </select>
                @error('entryBoxType')
                  <div class="mt-2 text-xs text-rose-200/90">{{ $message }}</div>
                @enderror
              </div>

              <div>
                <label class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">Box Count</label>
                <input
                  type="number"
                  min="1"
                  max="500"
                  wire:model="entryBoxCount"
                  class="mt-2 w-full rounded-xl border border-white/10 bg-black/25 px-3 py-2 text-sm text-white focus:border-emerald-300/30 focus:outline-none"
                />
                @error('entryBoxCount')
                  <div class="mt-2 text-xs text-rose-200/90">{{ $message }}</div>
                @enderror
              </div>
            </div>

            @if($entryBoxType === 'top_shelf')
              <div class="rounded-2xl border border-emerald-300/15 bg-emerald-500/5 p-4">
                <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">Top Shelf Definition (Per Box)</div>
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                  <div>
                    <label class="text-xs text-emerald-100/60">16oz</label>
                    <input type="number" min="0" max="500" wire:model="entryTopShelf16oz" class="mt-1 w-full rounded-xl border border-white/10 bg-black/25 px-3 py-2 text-sm text-white" />
                    @error('entryTopShelf16oz') <div class="mt-1 text-xs text-rose-200/90">{{ $message }}</div> @enderror
                  </div>
                  <div>
                    <label class="text-xs text-emerald-100/60">8oz</label>
                    <input type="number" min="0" max="500" wire:model="entryTopShelf8oz" class="mt-1 w-full rounded-xl border border-white/10 bg-black/25 px-3 py-2 text-sm text-white" />
                    @error('entryTopShelf8oz') <div class="mt-1 text-xs text-rose-200/90">{{ $message }}</div> @enderror
                  </div>
                  <div>
                    <label class="text-xs text-emerald-100/60">Wax Melts</label>
                    <input type="number" min="0" max="500" wire:model="entryTopShelfWaxMelt" class="mt-1 w-full rounded-xl border border-white/10 bg-black/25 px-3 py-2 text-sm text-white" />
                    @error('entryTopShelfWaxMelt') <div class="mt-1 text-xs text-rose-200/90">{{ $message }}</div> @enderror
                  </div>
                </div>
              </div>
            @endif

            <button
              type="button"
              wire:click="addDraftEntry"
              class="inline-flex w-full items-center justify-center rounded-xl border border-emerald-300/30 bg-emerald-500/15 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-emerald-500/20"
            >
              Add Box Line
            </button>
          </div>
        </div>
      </div>

      <div class="space-y-6">
        <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5 sm:p-6">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Draft Box Lines</div>
              <h3 class="mt-2 text-xl font-['Fraunces'] font-semibold text-white">Planner Draft</h3>
            </div>
            <button
              type="button"
              wire:click="goToTopShelfStep"
              class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-medium text-white/90 transition hover:bg-white/10"
            >
              Continue to Top Shelf Review
            </button>
          </div>

          <div class="mt-5 space-y-3">
            @forelse($draftEntryRows as $row)
              <div class="rounded-2xl border border-white/8 bg-black/15 p-4">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                  <div class="space-y-2 min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                      <div class="truncate text-base font-medium text-white">{{ $row['scent_name'] }}</div>
                      <span class="inline-flex items-center rounded-full border border-emerald-200/20 bg-emerald-500/10 px-2.5 py-1 text-[11px] text-emerald-50/85">
                        {{ $row['box_type_label'] }}
                      </span>
                    </div>

                    <div class="flex flex-wrap gap-2">
                      @foreach(['full' => 'Full', 'half' => 'Half', 'top_shelf' => 'Top Shelf'] as $typeValue => $typeLabel)
                        <button
                          type="button"
                          wire:click="setDraftEntryBoxType('{{ $row['key'] }}', '{{ $typeValue }}')"
                          class="rounded-full border px-3 py-1 text-xs transition {{ ($row['box_type'] ?? '') === $typeValue ? 'border-emerald-300/35 bg-emerald-500/15 text-white' : 'border-white/10 bg-white/5 text-emerald-50/70 hover:bg-white/10' }}"
                        >
                          {{ $typeLabel }}
                        </button>
                      @endforeach
                    </div>

                    <div class="flex flex-wrap gap-4 text-xs text-emerald-100/65">
                      <span>16oz: {{ $row['expanded']['16oz'] ?? 0 }}</span>
                      <span>8oz: {{ $row['expanded']['8oz'] ?? 0 }}</span>
                      <span>Melts: {{ $row['expanded']['wax_melt'] ?? 0 }}</span>
                    </div>

                    @if(($row['box_type'] ?? '') === 'top_shelf')
                      <div class="text-xs text-emerald-100/55">
                        Per box recipe:
                        {{ $row['top_shelf_definition']['16oz'] ?? 0 }} x 16oz,
                        {{ $row['top_shelf_definition']['8oz'] ?? 0 }} x 8oz,
                        {{ $row['top_shelf_definition']['wax_melt'] ?? 0 }} x melts
                      </div>
                    @endif
                  </div>

                  <div class="flex items-center gap-2">
                    <button type="button" wire:click="decrementDraftEntryCount('{{ $row['key'] }}')" class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/10 bg-white/5 text-white/90 hover:bg-white/10">-</button>
                    <div class="min-w-12 rounded-xl border border-white/10 bg-black/25 px-3 py-2 text-center text-sm font-semibold text-white">
                      {{ $row['box_count'] }}
                    </div>
                    <button type="button" wire:click="incrementDraftEntryCount('{{ $row['key'] }}')" class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/10 bg-white/5 text-white/90 hover:bg-white/10">+</button>
                    <button type="button" wire:click="removeDraftEntry('{{ $row['key'] }}')" class="ml-1 inline-flex items-center rounded-full border border-rose-300/20 bg-rose-500/10 px-3 py-2 text-xs text-rose-100/90 hover:bg-rose-500/15">
                      Remove
                    </button>
                  </div>
                </div>
              </div>
            @empty
              <div class="rounded-2xl border border-dashed border-white/10 bg-black/15 p-6 text-center text-sm text-emerald-50/65">
                No box lines yet. Add a scent, choose box type, and click <span class="font-medium text-white">Add Box Line</span>.
              </div>
            @endforelse
          </div>
        </div>

        <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5 sm:p-6">
          <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Expanded Preview</div>
          <h3 class="mt-2 text-xl font-['Fraunces'] font-semibold text-white">Totals by Scent (Live)</h3>
          <div class="mt-4 space-y-2">
            @forelse($previewByScent as $row)
              <div class="grid grid-cols-[minmax(0,1fr)_repeat(3,minmax(0,78px))] items-center gap-2 rounded-xl border border-white/8 bg-black/15 px-3 py-2 text-sm">
                <div class="truncate text-white">{{ $row['scent_name'] }}</div>
                <div class="text-right text-emerald-50/80">{{ $row['16oz'] }}</div>
                <div class="text-right text-emerald-50/80">{{ $row['8oz'] }}</div>
                <div class="text-right text-emerald-50/80">{{ $row['wax_melt'] }}</div>
              </div>
            @empty
              <div class="text-sm text-emerald-50/60">Expanded totals will appear here after you add box lines.</div>
            @endforelse
          </div>
          <div class="mt-4 grid grid-cols-3 gap-2 text-sm">
            <div class="rounded-xl border border-white/8 bg-black/20 px-3 py-2 text-center text-emerald-50/85">16oz: {{ $grandTotals['16oz'] ?? 0 }}</div>
            <div class="rounded-xl border border-white/8 bg-black/20 px-3 py-2 text-center text-emerald-50/85">8oz: {{ $grandTotals['8oz'] ?? 0 }}</div>
            <div class="rounded-xl border border-white/8 bg-black/20 px-3 py-2 text-center text-emerald-50/85">Melts: {{ $grandTotals['wax_melt'] ?? 0 }}</div>
          </div>
        </div>
      </div>
    </section>
  @elseif($step === 3)
    <section class="space-y-6">
      <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Step 3</div>
            <h2 class="mt-2 text-2xl font-['Fraunces'] font-semibold text-white">Top Shelf Box Definitions</h2>
            <p class="mt-2 text-sm text-emerald-50/65">
              Define per-box quantities for any Top Shelf lines. Full and Half boxes already use fixed rules.
            </p>
          </div>
          <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="goToStep(2)" class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs text-white/90 hover:bg-white/10">Back to Boxes</button>
            <button type="button" wire:click="continueToReview" class="rounded-full border border-emerald-300/30 bg-emerald-500/15 px-4 py-2 text-xs font-medium text-white hover:bg-emerald-500/20">Continue to Review</button>
          </div>
        </div>
      </section>

      @php($topShelfCount = 0)
      <div class="space-y-4">
        @foreach($draftEntryRows as $index => $row)
          @if(($row['box_type'] ?? '') === 'top_shelf')
            @php($topShelfCount++)
            <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5 sm:p-6">
              <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                  <div class="text-[11px] uppercase tracking-[0.3em] text-emerald-100/60">Top Shelf Line {{ $topShelfCount }}</div>
                  <h3 class="mt-2 text-xl font-['Fraunces'] font-semibold text-white">{{ $row['scent_name'] }}</h3>
                  <div class="mt-2 text-sm text-emerald-50/70">{{ $row['box_count'] }} box{{ (int) $row['box_count'] === 1 ? '' : 'es' }}</div>
                </div>
                <div class="grid grid-cols-3 gap-2 text-xs">
                  <div class="rounded-xl border border-white/8 bg-black/20 px-3 py-2 text-center text-emerald-50/75">16oz total: {{ $row['expanded']['16oz'] ?? 0 }}</div>
                  <div class="rounded-xl border border-white/8 bg-black/20 px-3 py-2 text-center text-emerald-50/75">8oz total: {{ $row['expanded']['8oz'] ?? 0 }}</div>
                  <div class="rounded-xl border border-white/8 bg-black/20 px-3 py-2 text-center text-emerald-50/75">Melts total: {{ $row['expanded']['wax_melt'] ?? 0 }}</div>
                </div>
              </div>

              <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div>
                  <label class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">16oz per box</label>
                  <input
                    type="number"
                    min="0"
                    max="500"
                    wire:model.live.debounce.250ms="draftEntries.{{ $index }}.top_shelf_definition.16oz"
                    class="mt-2 w-full rounded-xl border border-white/10 bg-black/25 px-3 py-2 text-sm text-white focus:border-emerald-300/30 focus:outline-none"
                  />
                </div>
                <div>
                  <label class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">8oz per box</label>
                  <input
                    type="number"
                    min="0"
                    max="500"
                    wire:model.live.debounce.250ms="draftEntries.{{ $index }}.top_shelf_definition.8oz"
                    class="mt-2 w-full rounded-xl border border-white/10 bg-black/25 px-3 py-2 text-sm text-white focus:border-emerald-300/30 focus:outline-none"
                  />
                </div>
                <div>
                  <label class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">Wax melts per box</label>
                  <input
                    type="number"
                    min="0"
                    max="500"
                    wire:model.live.debounce.250ms="draftEntries.{{ $index }}.top_shelf_definition.wax_melt"
                    class="mt-2 w-full rounded-xl border border-white/10 bg-black/25 px-3 py-2 text-sm text-white focus:border-emerald-300/30 focus:outline-none"
                  />
                </div>
              </div>
            </section>
          @endif
        @endforeach

        @if($topShelfCount === 0)
          <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 text-center">
            <div class="text-lg font-['Fraunces'] font-semibold text-white">No Top Shelf lines to configure</div>
            <p class="mt-2 text-sm text-emerald-50/65">
              Your current draft only contains Full and/or Half boxes. You can continue directly to confirmation.
            </p>
            <div class="mt-4 flex flex-wrap justify-center gap-2">
              <button type="button" wire:click="goToStep(2)" class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs text-white/90 hover:bg-white/10">Back to Boxes</button>
              <button type="button" wire:click="continueToReview" class="rounded-full border border-emerald-300/30 bg-emerald-500/15 px-4 py-2 text-xs font-medium text-white hover:bg-emerald-500/20">Continue to Review</button>
            </div>
          </section>
        @endif
      </div>
    </section>
  @elseif($step === 4)
    <section class="space-y-6">
      <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Step 4</div>
            <h2 class="mt-2 text-2xl font-['Fraunces'] font-semibold text-white">Confirm Expanded Totals</h2>
            <p class="mt-2 text-sm text-emerald-50/65">
              Review the exact candle quantities that will be sent to the Pouring Room. The queue will not receive box terminology.
            </p>
          </div>
          <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="goToStep(3)" class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs text-white/90 hover:bg-white/10">Back</button>
            <button type="button" wire:click="publish" class="rounded-full border border-emerald-300/30 bg-emerald-500/15 px-4 py-2 text-xs font-medium text-white hover:bg-emerald-500/20">Publish to Pouring Room</button>
          </div>
        </div>
      </section>

      <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5 sm:p-6">
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Expanded By Scent</div>
        <h3 class="mt-2 text-xl font-['Fraunces'] font-semibold text-white">Pouring Room Preview</h3>

        <div class="mt-4 overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="text-left text-emerald-100/55">
                <th class="px-3 py-2 font-medium">Scent</th>
                <th class="px-3 py-2 text-right font-medium">16oz</th>
                <th class="px-3 py-2 text-right font-medium">8oz</th>
                <th class="px-3 py-2 text-right font-medium">Wax Melt</th>
              </tr>
            </thead>
            <tbody>
              @forelse($previewByScent as $row)
                <tr class="border-t border-white/5">
                  <td class="px-3 py-2 text-white">{{ $row['scent_name'] }}</td>
                  <td class="px-3 py-2 text-right text-emerald-50/85">{{ $row['16oz'] }}</td>
                  <td class="px-3 py-2 text-right text-emerald-50/85">{{ $row['8oz'] }}</td>
                  <td class="px-3 py-2 text-right text-emerald-50/85">{{ $row['wax_melt'] }}</td>
                </tr>
              @empty
                <tr>
                  <td colspan="4" class="px-3 py-5 text-center text-emerald-50/60">No draft lines available.</td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div class="rounded-2xl border border-white/8 bg-black/20 p-4">
            <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">16oz Total</div>
            <div class="mt-2 text-2xl font-semibold text-white">{{ $grandTotals['16oz'] ?? 0 }}</div>
          </div>
          <div class="rounded-2xl border border-white/8 bg-black/20 p-4">
            <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">8oz Total</div>
            <div class="mt-2 text-2xl font-semibold text-white">{{ $grandTotals['8oz'] ?? 0 }}</div>
          </div>
          <div class="rounded-2xl border border-white/8 bg-black/20 p-4">
            <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">Wax Melt Total</div>
            <div class="mt-2 text-2xl font-semibold text-white">{{ $grandTotals['wax_melt'] ?? 0 }}</div>
          </div>
        </div>
      </section>

      <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5 sm:p-6">
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Draft Line Detail</div>
        <h3 class="mt-2 text-xl font-['Fraunces'] font-semibold text-white">Box-to-Quantity Expansion</h3>
        <div class="mt-4 space-y-3">
          @foreach($draftEntryRows as $row)
            <div class="rounded-2xl border border-white/8 bg-black/15 p-4">
              <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                  <div class="text-white">{{ $row['scent_name'] }}</div>
                  <div class="mt-1 text-xs text-emerald-100/60">
                    {{ $row['box_count'] }} × {{ $row['box_type_label'] }}
                  </div>
                </div>
                <div class="flex flex-wrap gap-2 text-xs">
                  <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-emerald-50/80">16oz: {{ $row['expanded']['16oz'] ?? 0 }}</span>
                  <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-emerald-50/80">8oz: {{ $row['expanded']['8oz'] ?? 0 }}</span>
                  <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-emerald-50/80">Melts: {{ $row['expanded']['wax_melt'] ?? 0 }}</span>
                </div>
              </div>
            </div>
          @endforeach
        </div>
      </section>
    </section>
  @else
    <section class="space-y-6">
      <section class="rounded-3xl border border-emerald-300/20 bg-emerald-500/10 p-5 sm:p-6">
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Step 5</div>
        <h2 class="mt-2 text-2xl font-['Fraunces'] font-semibold text-white">Published to Pouring Room</h2>
        <p class="mt-2 text-sm text-emerald-50/75">
          The markets plan was published using expanded candle quantities only.
        </p>

        @if(!empty($publishSummary))
          <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl border border-white/10 bg-black/20 p-4">
              <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">Order ID</div>
              <div class="mt-2 text-lg font-semibold text-white">{{ $publishSummary['order_id'] ?? '—' }}</div>
            </div>
            <div class="rounded-2xl border border-white/10 bg-black/20 p-4">
              <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">Order Number</div>
              <div class="mt-2 text-sm font-semibold text-white break-all">{{ $publishSummary['order_number'] ?? '—' }}</div>
            </div>
            <div class="rounded-2xl border border-white/10 bg-black/20 p-4">
              <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">Event</div>
              <div class="mt-2 text-sm font-semibold text-white">{{ $publishSummary['event_title'] ?? '—' }}</div>
            </div>
            <div class="rounded-2xl border border-white/10 bg-black/20 p-4">
              <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">Event Date</div>
              <div class="mt-2 text-sm font-semibold text-white">{{ $publishSummary['event_date'] ?? '—' }}</div>
            </div>
          </div>
        @endif
      </section>

      <section class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5 sm:p-6">
          <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Published Totals</div>
          <h3 class="mt-2 text-xl font-['Fraunces'] font-semibold text-white">Expanded Candle Quantities</h3>

          @php($publishedByScent = $publishSummary['by_scent'] ?? [])
          <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead>
                <tr class="text-left text-emerald-100/55">
                  <th class="px-3 py-2 font-medium">Scent</th>
                  <th class="px-3 py-2 text-right font-medium">16oz</th>
                  <th class="px-3 py-2 text-right font-medium">8oz</th>
                  <th class="px-3 py-2 text-right font-medium">Wax Melt</th>
                </tr>
              </thead>
              <tbody>
                @forelse($publishedByScent as $row)
                  <tr class="border-t border-white/5">
                    <td class="px-3 py-2 text-white">{{ $row['scent_name'] }}</td>
                    <td class="px-3 py-2 text-right text-emerald-50/85">{{ $row['16oz'] }}</td>
                    <td class="px-3 py-2 text-right text-emerald-50/85">{{ $row['8oz'] }}</td>
                    <td class="px-3 py-2 text-right text-emerald-50/85">{{ $row['wax_melt'] }}</td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="4" class="px-3 py-5 text-center text-emerald-50/60">No totals available.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>

        <div class="space-y-6">
          @php($publishedTotals = $publishSummary['grand_totals'] ?? ['16oz' => 0, '8oz' => 0, 'wax_melt' => 0])
          <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5 sm:p-6">
            <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Grand Totals</div>
            <div class="mt-4 space-y-3">
              <div class="rounded-2xl border border-white/8 bg-black/20 p-4">
                <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">16oz</div>
                <div class="mt-1 text-2xl font-semibold text-white">{{ $publishedTotals['16oz'] ?? 0 }}</div>
              </div>
              <div class="rounded-2xl border border-white/8 bg-black/20 p-4">
                <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">8oz</div>
                <div class="mt-1 text-2xl font-semibold text-white">{{ $publishedTotals['8oz'] ?? 0 }}</div>
              </div>
              <div class="rounded-2xl border border-white/8 bg-black/20 p-4">
                <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/55">Wax Melts</div>
                <div class="mt-1 text-2xl font-semibold text-white">{{ $publishedTotals['wax_melt'] ?? 0 }}</div>
              </div>
            </div>
          </div>

          <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5 sm:p-6">
            <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Next Actions</div>
            <div class="mt-4 space-y-2">
              <button type="button" wire:click="goToStep(1)" class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-sm text-white/90 hover:bg-white/10">Plan Another Event</button>
              <button type="button" wire:click="goToStep(2)" class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-sm text-white/90 hover:bg-white/10">Reopen Current Draft</button>
            </div>
          </div>
        </div>
      </section>
    </section>
  @endif
</div>
