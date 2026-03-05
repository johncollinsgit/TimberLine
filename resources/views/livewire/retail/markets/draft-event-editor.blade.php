<div class="mt-4 space-y-2">
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
      @php
        $rowId = (int) ($row['id'] ?? 0);
        $boxTier = (string) ($row['box_tier'] ?? 'standard');
        $status = $rowStatus[$rowId] ?? null;
        $topShelf = (array) ($row['top_shelf'] ?? []);
        $notesOpen = (bool) ($openNotes[$rowId] ?? false);
        $detailsOpen = (bool) ($openDetails[$rowId] ?? false);
        $topShelfSlots = (array) ($topShelf['composition'] ?? []);
        $topShelfSlotCount = count($topShelfSlots);
        $topShelfFilledCount = 0;
        foreach ($topShelfSlots as $topShelfSlotRow) {
          if ((int)($topShelfSlotRow['scent_id'] ?? 0) > 0) {
            $topShelfFilledCount++;
          }
        }
      @endphp

      <div class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 p-2.5">
        <div class="flex min-w-0 flex-nowrap items-center gap-2">
          <div class="min-w-0 flex-1">
            @if($boxTier === 'top_shelf')
              <button
                type="button"
                wire:click="openTopShelfConfigurator({{ $rowId }})"
                class="h-10 w-full rounded-2xl border border-amber-300/20 bg-amber-500/10 px-3 text-left text-sm text-amber-50/95 hover:bg-amber-500/15"
                title="Top Shelf configuration"
                aria-label="Open Top Shelf configuration"
              >
                <span class="block truncate">Top Shelf · {{ $topShelfFilledCount }}/{{ $topShelfSlotCount }} slots · {{ $this->topShelfDescription($row) }}</span>
              </button>
            @else
              <select
                wire:model.live="draftRows.{{ $rowId }}.scent_id"
                class="h-10 w-full min-w-0 truncate rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
              >
                <option value="">Select scent...</option>
                @foreach($scentOptions as $option)
                  <option value="{{ $option->id }}">{{ $option->display_name ?: $option->name }}</option>
                @endforeach
              </select>
            @endif
          </div>

          <input
            type="number"
            min="{{ $boxTier === 'top_shelf' ? 1 : 0.5 }}"
            step="{{ $boxTier === 'top_shelf' ? 1 : 0.5 }}"
            wire:model.live.debounce.250ms="draftRows.{{ $rowId }}.box_count"
            class="h-10 w-[5.25rem] shrink-0 rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90 sm:w-[6rem]"
            aria-label="{{ $boxTier === 'top_shelf' ? 'Top shelf boxes' : 'Boxes' }}"
          />

          <div class="flex shrink-0 items-center gap-1">
            @if($boxTier !== 'top_shelf')
              <button
                type="button"
                wire:click="toggleNotes({{ $rowId }})"
                title="{{ $notesOpen ? 'Hide Notes' : 'Notes' }}"
                aria-label="{{ $notesOpen ? 'Hide Notes' : 'Notes' }}"
                class="h-10 w-10 rounded-xl border border-white/12 bg-white/5 text-xs text-white/85 hover:bg-white/10 md:w-auto md:px-3"
              >
                <span class="md:hidden" aria-hidden="true">
                  <svg viewBox="0 0 20 20" fill="currentColor" class="mx-auto h-4 w-4">
                    <path d="M4 3a1 1 0 0 0-1 1v12a1 1 0 0 0 1.447.894L10 14.118l5.553 2.776A1 1 0 0 0 17 16V4a1 1 0 0 0-1-1H4Z"/>
                  </svg>
                </span>
                <span class="hidden md:inline">{{ $notesOpen ? 'Hide Notes' : 'Notes' }}</span>
                <span class="sr-only md:hidden">{{ $notesOpen ? 'Hide Notes' : 'Notes' }}</span>
              </button>
            @endif

            @if($boxTier === 'top_shelf')
              <button
                type="button"
                wire:click="openTopShelfConfigurator({{ $rowId }})"
                title="Top Shelf"
                aria-label="Top Shelf"
                class="h-10 w-10 rounded-xl border border-white/12 bg-white/5 text-xs text-white/85 hover:bg-white/10 md:w-auto md:px-3"
              >
                <span class="md:hidden" aria-hidden="true">
                  <svg viewBox="0 0 20 20" fill="currentColor" class="mx-auto h-4 w-4">
                    <path d="M4 4.75A1.75 1.75 0 0 1 5.75 3h8.5A1.75 1.75 0 0 1 16 4.75v10.5A1.75 1.75 0 0 1 14.25 17h-8.5A1.75 1.75 0 0 1 4 15.25V4.75Zm2 .75a.75.75 0 0 0 0 1.5h8a.75.75 0 0 0 0-1.5H6Z"/>
                  </svg>
                </span>
                <span class="hidden md:inline">Top Shelf</span>
                <span class="sr-only md:hidden">Top Shelf</span>
              </button>
            @else
              <button
                type="button"
                wire:click="toggleDetails({{ $rowId }})"
                title="{{ $detailsOpen ? 'Hide Details' : 'Details' }}"
                aria-label="{{ $detailsOpen ? 'Hide Details' : 'Details' }}"
                class="h-10 w-10 rounded-xl border border-white/12 bg-white/5 text-xs text-white/85 hover:bg-white/10 md:w-auto md:px-3"
              >
                <span class="md:hidden" aria-hidden="true">
                  <svg viewBox="0 0 20 20" fill="currentColor" class="mx-auto h-4 w-4">
                    <path fill-rule="evenodd" d="M18 10A8 8 0 1 1 2 10a8 8 0 0 1 16 0ZM9.25 8.75a.75.75 0 0 1 1.5 0v4a.75.75 0 0 1-1.5 0v-4Zm.75-3a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z" clip-rule="evenodd"/>
                  </svg>
                </span>
                <span class="hidden md:inline">{{ $detailsOpen ? 'Hide Details' : 'Details' }}</span>
                <span class="sr-only md:hidden">{{ $detailsOpen ? 'Hide Details' : 'Details' }}</span>
              </button>
            @endif

            <button
              type="button"
              wire:click="removeItem({{ $rowId }})"
              wire:loading.attr="disabled"
              wire:target="removeItem"
              title="Remove"
              aria-label="Remove"
              class="h-10 w-10 rounded-xl border border-red-400/20 bg-red-500/10 text-xs text-red-100 disabled:cursor-not-allowed disabled:opacity-50 md:w-auto md:px-3"
            >
              <span class="md:hidden" aria-hidden="true">
                <svg viewBox="0 0 20 20" fill="currentColor" class="mx-auto h-4 w-4">
                  <path fill-rule="evenodd" d="M8.75 3A1.75 1.75 0 0 0 7 4.75V5H4.5a.75.75 0 0 0 0 1.5h.568l.85 9.065A2 2 0 0 0 7.91 17.5h4.18a2 2 0 0 0 1.992-1.935l.85-9.065h.568a.75.75 0 0 0 0-1.5H13v-.25A1.75 1.75 0 0 0 11.25 3h-2.5ZM8.5 5v-.25a.25.25 0 0 1 .25-.25h2.5a.25.25 0 0 1 .25.25V5h-3Z" clip-rule="evenodd"/>
                </svg>
              </span>
              <span class="hidden md:inline">Remove</span>
              <span class="sr-only md:hidden">Remove</span>
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

        @if($detailsOpen && $boxTier !== 'top_shelf')
          <div class="mt-2 rounded-2xl border border-white/8 bg-black/15 p-3 space-y-3">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
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
            </div>
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

    <div class="flex justify-end">
      <button
        type="button"
        wire:click="saveAllRows"
        @disabled($rows->isEmpty())
        class="rounded-xl border border-emerald-300/25 bg-emerald-500/12 px-5 py-2.5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
      >
        Save All Changes
      </button>
    </div>

    @if($activeTopShelfRowId && isset($draftRows[$activeTopShelfRowId]))
      @php($activeTopShelfRow = $draftRows[$activeTopShelfRowId])
      @php($activeTopShelf = (array) ($activeTopShelfRow['top_shelf'] ?? []))
      <div class="mt-3 rounded-2xl border border-amber-300/15 bg-amber-500/5 p-4">
        <div class="mb-4 flex items-center justify-between gap-3">
          <div>
            <div class="text-[11px] uppercase tracking-[0.2em] text-amber-100/65">Top Shelf Builder</div>
            <div class="mt-1 text-lg font-semibold text-white">Configure Top Shelf Line</div>
          </div>
          <button
            type="button"
            wire:click="closeTopShelfConfigurator"
            class="rounded-xl border border-white/12 bg-white/5 px-3 py-2 text-xs text-white/85 hover:bg-white/10"
          >
            Close
          </button>
        </div>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          <div class="space-y-1">
            <label class="text-[10px] uppercase tracking-[0.2em] text-emerald-100/55">Top Shelf Boxes</label>
            <input
              type="number"
              min="1"
              step="1"
              wire:model.live.debounce.250ms="draftRows.{{ $activeTopShelfRowId }}.box_count"
              class="h-10 w-full rounded-xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
            />
          </div>

          <div class="space-y-1">
            <label class="text-[10px] uppercase tracking-[0.2em] text-emerald-100/55">Composition</label>
            <select
              wire:model.live="draftRows.{{ $activeTopShelfRowId }}.top_shelf.preset"
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
              wire:model.live="draftRows.{{ $activeTopShelfRowId }}.top_shelf.size_mode"
              class="h-10 w-full rounded-xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
            >
              @foreach($topShelfSizeModes as $modeValue => $modeLabel)
                <option value="{{ $modeValue }}">{{ $modeLabel }}</option>
              @endforeach
            </select>
          </div>

          <div class="space-y-1">
            <label class="text-[10px] uppercase tracking-[0.2em] text-emerald-100/55">Line Type</label>
            <select
              wire:model.live="draftRows.{{ $activeTopShelfRowId }}.box_tier"
              class="h-10 w-full rounded-xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
            >
              <option value="top_shelf">Top Shelf</option>
              <option value="standard">Standard</option>
            </select>
          </div>
        </div>

        @if(($activeTopShelf['size_mode'] ?? '16oz') === 'wax_melt')
          <div class="mt-3 max-w-[12rem] space-y-1">
            <label class="text-[10px] uppercase tracking-[0.2em] text-emerald-100/55">Melts Per Box</label>
            <input
              type="number"
              min="1"
              max="36"
              step="1"
              wire:model.live.debounce.300ms="draftRows.{{ $activeTopShelfRowId }}.top_shelf.wax_melt_capacity"
              class="h-10 w-full rounded-xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
            />
          </div>
        @endif

        <div class="mt-4 rounded-2xl border border-amber-300/15 bg-black/15 p-3">
          <div class="text-[10px] uppercase tracking-[0.2em] text-amber-100/70">Top Shelf Scents</div>
          <div class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
            @foreach(($activeTopShelf['composition'] ?? []) as $slotIndex => $slot)
              <div class="space-y-1 rounded-xl border border-white/8 bg-black/20 p-2.5">
                <div class="flex items-center justify-between gap-2">
                  <div class="text-xs font-semibold text-white">Slot {{ (int) ($slot['slot'] ?? ($slotIndex + 1)) }}</div>
                  <div class="text-[11px] text-emerald-100/55">{{ (int) ($slot['units_per_box'] ?? 0) }} per box</div>
                </div>
                <select
                  wire:model.live="draftRows.{{ $activeTopShelfRowId }}.top_shelf.slots.{{ $slotIndex }}"
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
      </div>
    @endif
  @endif
</div>
