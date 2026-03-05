<div class="mt-4 space-y-3">
  @if(!$selectedEventId || !$event)
    <div class="rounded-2xl border border-dashed border-emerald-200/15 bg-emerald-500/5 p-4 text-sm text-emerald-50/70">
      Select an event first to view and edit boxes for this draft.
    </div>
  @elseif($rows->isEmpty())
    <div class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 p-4 text-sm text-emerald-50/70">
      No draft boxes for {{ $event->display_name ?: $event->name ?: 'selected event' }} yet. Add a scent to start building boxes.
    </div>
  @else
    <div class="text-xs text-emerald-100/65">
      Editing: {{ $event->display_name ?: $event->name ?: 'Event' }}
      @if($event->starts_at)
        · {{ $event->starts_at->format('M j, Y') }}
      @endif
    </div>

    @foreach($rows as $row)
      @php($rowId = (int) ($row['id'] ?? 0))
      @php($boxTier = (string) ($row['box_tier'] ?? 'standard'))
      @php($status = $rowStatus[$rowId] ?? null)
      @php($topShelf = (array) ($row['top_shelf'] ?? []))
      @php($notesOpen = (bool) ($openNotes[$rowId] ?? false))
      @php($detailsOpen = (bool) ($openDetails[$rowId] ?? false))

      <div wire:key="draft-row-{{ $rowId }}" class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 p-3">
        <div class="flex flex-col gap-2 xl:flex-row xl:items-center">
          <div class="grid flex-1 grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_7rem_auto]">
            @if($boxTier === 'top_shelf')
              <button
                type="button"
                wire:click="toggleDetails({{ $rowId }})"
                class="h-11 rounded-2xl border border-amber-300/20 bg-amber-500/10 px-3 text-left text-xs text-amber-50/85 hover:bg-amber-500/15"
              >
                {{ $this->topShelfDescription($row) }} · {{ $this->topShelfCompositionPreview($row, $scentLookup) ?: 'Open details to configure scents' }}
              </button>
            @else
              <select
                wire:model.live="draftRows.{{ $rowId }}.scent_id"
                class="h-11 w-full rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
              >
                <option value="">Select scent...</option>
                @foreach($scentOptions as $option)
                  <option value="{{ $option->id }}">{{ $option->display_name ?: $option->name }}</option>
                @endforeach
              </select>
            @endif

            <input
              type="number"
              min="{{ $boxTier === 'top_shelf' ? 1 : 0.5 }}"
              step="{{ $boxTier === 'top_shelf' ? 1 : 0.5 }}"
              wire:model.live.debounce.250ms="draftRows.{{ $rowId }}.box_count"
              class="h-11 w-full rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
              aria-label="{{ $boxTier === 'top_shelf' ? 'Top shelf boxes' : 'Boxes' }}"
            />

            <button
              type="button"
              wire:click="saveItem({{ $rowId }})"
              class="h-11 rounded-2xl border border-emerald-300/25 bg-emerald-500/12 px-4 text-sm font-semibold text-white"
            >
              Save
            </button>
          </div>

          <div class="flex flex-wrap items-center gap-2 xl:shrink-0 xl:justify-end">
            <button
              type="button"
              title="{{ $this->rowInfoText($row) }}"
              class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-white/12 bg-white/5 text-xs text-white/80 hover:bg-white/10"
              aria-label="Row info"
            >
              i
            </button>
            @if($boxTier !== 'top_shelf')
              <button
                type="button"
                wire:click="toggleNotes({{ $rowId }})"
                class="rounded-xl border border-white/12 bg-white/5 px-3 py-2 text-xs text-white/85 hover:bg-white/10"
              >
                {{ $notesOpen ? 'Hide Notes' : 'Notes' }}
              </button>
            @endif
            <button
              type="button"
              wire:click="toggleDetails({{ $rowId }})"
              class="rounded-xl border border-white/12 bg-white/5 px-3 py-2 text-xs text-white/85 hover:bg-white/10"
            >
              {{ $detailsOpen ? 'Hide Details' : 'Details' }}
            </button>
            <button
              type="button"
              wire:click="removeItem({{ $rowId }})"
              class="rounded-xl border border-red-400/20 bg-red-500/10 px-3 py-2 text-xs text-red-100"
            >
              Remove
            </button>
          </div>
        </div>

        @if($notesOpen && $boxTier !== 'top_shelf')
          <div class="mt-2">
            <textarea
              wire:model.live.debounce.300ms="draftRows.{{ $rowId }}.notes_text"
              rows="2"
              placeholder="Optional notes for this line..."
              class="w-full rounded-2xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90 placeholder:text-white/30"
            ></textarea>
          </div>
        @endif

        @if($detailsOpen)
          <div class="mt-2 rounded-2xl border border-white/8 bg-black/15 p-3 space-y-3">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
              <div class="space-y-1">
                <label class="text-[10px] uppercase tracking-[0.2em] text-emerald-100/55">Line Type</label>
                <select
                  wire:model.live="draftRows.{{ $rowId }}.box_tier"
                  class="h-10 w-full rounded-xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
                >
                  <option value="standard">Standard</option>
                  <option value="top_shelf">Top Shelf</option>
                </select>
              </div>

              @if($boxTier !== 'top_shelf')
                <div class="space-y-1">
                  <label class="text-[10px] uppercase tracking-[0.2em] text-emerald-100/55">Extra Size (Optional)</label>
                  <select
                    wire:model.live="draftRows.{{ $rowId }}.size_id"
                    class="h-10 w-full rounded-xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
                  >
                    <option value="">Default size</option>
                    @foreach($sizeOptions as $option)
                      <option value="{{ $option->id }}">{{ $option->label ?: $option->code }}</option>
                    @endforeach
                  </select>
                </div>
              @endif

              @if($boxTier === 'top_shelf')
                <div class="space-y-1">
                  <label class="text-[10px] uppercase tracking-[0.2em] text-emerald-100/55">Composition</label>
                  <select
                    wire:model.live="draftRows.{{ $rowId }}.top_shelf.preset"
                    class="h-10 w-full rounded-xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
                  >
                    @foreach($topShelfPresetOptions as $presetValue => $presetLabel)
                      <option value="{{ $presetValue }}">{{ $presetLabel }}</option>
                    @endforeach
                  </select>
                </div>

                <div class="space-y-1">
                  <label class="text-[10px] uppercase tracking-[0.2em] text-emerald-100/55">Fill Type</label>
                  <select
                    wire:model.live="draftRows.{{ $rowId }}.top_shelf.size_mode"
                    class="h-10 w-full rounded-xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
                  >
                    @foreach($topShelfSizeModes as $modeValue => $modeLabel)
                      <option value="{{ $modeValue }}">{{ $modeLabel }}</option>
                    @endforeach
                  </select>
                </div>
              @endif
            </div>

            @if($boxTier === 'top_shelf' && ($topShelf['size_mode'] ?? '16oz') === 'wax_melt')
              <div class="max-w-[12rem] space-y-1">
                <label class="text-[10px] uppercase tracking-[0.2em] text-emerald-100/55">Melts Per Box</label>
                <input
                  type="number"
                  min="1"
                  max="36"
                  step="1"
                  wire:model.live.debounce.300ms="draftRows.{{ $rowId }}.top_shelf.wax_melt_capacity"
                  class="h-10 w-full rounded-xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
                />
              </div>
            @endif

            @if($boxTier === 'top_shelf')
              <div class="rounded-2xl border border-amber-300/15 bg-amber-500/5 p-3">
                <div class="text-[10px] uppercase tracking-[0.2em] text-amber-100/70">Top Shelf Scents</div>
                <div class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                  @foreach(($topShelf['composition'] ?? []) as $slotIndex => $slot)
                    <div class="space-y-1 rounded-xl border border-white/8 bg-black/15 p-2.5">
                      <div class="flex items-center justify-between gap-2">
                        <div class="text-xs font-semibold text-white">Slot {{ (int) ($slot['slot'] ?? ($slotIndex + 1)) }}</div>
                        <div class="text-[11px] text-emerald-100/55">{{ (int) ($slot['units_per_box'] ?? 0) }} per box</div>
                      </div>
                      <select
                        wire:model.live="draftRows.{{ $rowId }}.top_shelf.slots.{{ $slotIndex }}"
                        class="h-10 w-full rounded-xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
                      >
                        <option value="">Choose scent...</option>
                        @foreach($scentOptions as $option)
                          <option value="{{ $option->id }}">{{ $option->display_name ?: $option->name }}</option>
                        @endforeach
                      </select>
                    </div>
                  @endforeach
                </div>
              </div>
            @endif
          </div>
        @endif

        @if(!empty($status['message']))
          <div class="mt-2 rounded-xl border px-3 py-2 text-xs {{ ($status['type'] ?? '') === 'error' ? 'border-rose-300/20 bg-rose-500/10 text-rose-100' : 'border-emerald-300/20 bg-emerald-500/10 text-emerald-50' }}">
            {{ $status['message'] }}
          </div>
        @endif
      </div>
    @endforeach

    @if(!$canSubmit)
      <div class="rounded-xl border border-amber-300/20 bg-amber-500/10 px-3 py-2 text-xs text-amber-50/90">
        Every standard row needs a scent and boxes quantity. Every Top Shelf row needs complete scent slots and quantity before review.
      </div>
    @endif
  @endif
</div>
