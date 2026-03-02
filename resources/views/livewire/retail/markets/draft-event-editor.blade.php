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

      <div wire:key="draft-row-{{ $rowId }}" class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 p-4 space-y-4">
        <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
          <div class="min-w-0">
            <div class="text-sm font-medium text-white/90">
              {{ $boxTier === 'top_shelf' ? 'Top Shelf Box' : ($row['scent_label'] ?: ($row['scent_id'] ? 'Mapped scent' : 'Needs scent mapping')) }}
            </div>
            <div class="mt-1 text-xs text-emerald-100/60">
              {{ $boxTier === 'top_shelf' ? 'Top Shelf' : 'Standard Market Box' }}
              · {{
                ($row['source'] ?? '') === 'market_box_manual'
                  ? 'Manual'
                  : ((($row['source'] ?? '') === 'market_top_shelf_template'
                      ? 'Top Shelf default'
                      : (($row['source'] ?? '') === 'market_duration_template' ? 'Starter template' : 'Historical template')))
              }}
              · {{ $this->quantityLabelForRow($row) }}
            </div>
            @if($boxTier === 'top_shelf')
              <div class="mt-2 text-xs text-emerald-100/55">{{ $this->topShelfDescription($row) }}</div>
              <div class="mt-1 text-xs text-emerald-100/50">{{ $this->topShelfCompositionPreview($row, $scentLookup) }}</div>
            @endif
          </div>

          <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap lg:justify-end">
            <button
              type="button"
              wire:click="saveItem({{ $rowId }})"
              class="w-full rounded-xl border border-emerald-300/25 bg-emerald-500/12 px-4 py-2 text-sm font-semibold text-white sm:w-auto"
            >
              Save
            </button>
            <button
              type="button"
              wire:click="removeItem({{ $rowId }})"
              class="w-full rounded-xl border border-red-400/20 bg-red-500/10 px-4 py-2 text-sm text-red-100 sm:w-auto"
            >
              Remove
            </button>
          </div>
        </div>

        @if($boxTier === 'top_shelf')
          <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-5">
            <div class="space-y-2">
              <label class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Top Shelf Boxes</label>
              <input
                type="number"
                min="1"
                step="1"
                wire:model.live.debounce.300ms="draftRows.{{ $rowId }}.quantity"
                class="h-11 w-full rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
              />
            </div>

            <div class="space-y-2">
              <label class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Tier Type</label>
              <select
                wire:model.live="draftRows.{{ $rowId }}.box_tier"
                class="h-11 w-full rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
              >
                <option value="standard">Standard</option>
                <option value="top_shelf">Top Shelf</option>
              </select>
            </div>

            <div class="space-y-2">
              <label class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Composition</label>
              <select
                wire:model.live="draftRows.{{ $rowId }}.top_shelf.preset"
                class="h-11 w-full rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
              >
                @foreach($topShelfPresetOptions as $presetValue => $presetLabel)
                  <option value="{{ $presetValue }}">{{ $presetLabel }}</option>
                @endforeach
              </select>
            </div>

            <div class="space-y-2">
              <label class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Fill Type</label>
              <select
                wire:model.live="draftRows.{{ $rowId }}.top_shelf.size_mode"
                class="h-11 w-full rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
              >
                @foreach($topShelfSizeModes as $modeValue => $modeLabel)
                  <option value="{{ $modeValue }}">{{ $modeLabel }}</option>
                @endforeach
              </select>
            </div>

            @if(($topShelf['size_mode'] ?? '16oz') === 'wax_melt')
              <div class="space-y-2">
                <label class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Melts Per Box</label>
                <input
                  type="number"
                  min="1"
                  max="36"
                  step="1"
                  wire:model.live.debounce.300ms="draftRows.{{ $rowId }}.top_shelf.wax_melt_capacity"
                  class="h-11 w-full rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
                />
              </div>
            @endif
          </div>

          <div class="rounded-2xl border border-amber-300/15 bg-amber-500/5 p-4">
            <div class="text-[11px] uppercase tracking-[0.22em] text-amber-100/70">Top Shelf Builder</div>
            <div class="mt-1 text-xs text-amber-50/80">
              Configure the per-box composition here. This stays as a box row and expands into candle lines only when you publish.
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
              @foreach(($topShelf['composition'] ?? []) as $slotIndex => $slot)
                <div class="space-y-2 rounded-2xl border border-white/8 bg-black/15 p-3">
                  <div class="flex items-center justify-between gap-2">
                    <div class="text-xs font-semibold text-white">Slot {{ (int) ($slot['slot'] ?? ($slotIndex + 1)) }}</div>
                    <div class="text-[11px] text-emerald-100/55">{{ (int) ($slot['units_per_box'] ?? 0) }} per box</div>
                  </div>
                  <select
                    wire:model.live="draftRows.{{ $rowId }}.top_shelf.slots.{{ $slotIndex }}"
                    class="h-11 w-full rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
                  >
                    <option value="">Choose scent…</option>
                    @foreach($scentOptions as $option)
                      <option value="{{ $option->id }}">{{ $option->display_name ?: $option->name }}</option>
                    @endforeach
                  </select>
                </div>
              @endforeach
            </div>
          </div>
        @else
          <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div class="space-y-2 xl:col-span-2">
              <label class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Scent</label>
              <select
                wire:model.live="draftRows.{{ $rowId }}.scent_id"
                class="h-11 w-full rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
              >
                <option value="">Select scent…</option>
                @foreach($scentOptions as $option)
                  <option value="{{ $option->id }}">{{ $option->display_name ?: $option->name }}</option>
                @endforeach
              </select>
            </div>

            <div class="space-y-2">
              <label class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Half-Box Units</label>
              <input
                type="number"
                min="1"
                step="1"
                wire:model.live.debounce.300ms="draftRows.{{ $rowId }}.quantity"
                class="h-11 w-full rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
              />
            </div>

            <div class="space-y-2">
              <label class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Size</label>
              <select
                wire:model.live="draftRows.{{ $rowId }}.size_id"
                class="h-11 w-full rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
              >
                <option value="">Default publish sizes</option>
                @foreach($sizeOptions as $option)
                  <option value="{{ $option->id }}">{{ $option->label ?: $option->code }}</option>
                @endforeach
              </select>
            </div>

            <div class="space-y-2">
              <label class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Tier Type</label>
              <select
                wire:model.live="draftRows.{{ $rowId }}.box_tier"
                class="h-11 w-full rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
              >
                <option value="standard">Standard</option>
                <option value="top_shelf">Top Shelf</option>
              </select>
            </div>
          </div>

          <div class="space-y-2">
            <label class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/55">Notes</label>
            <textarea
              wire:model.live.debounce.300ms="draftRows.{{ $rowId }}.notes_text"
              rows="2"
              placeholder="Notes for this draft row…"
              class="w-full rounded-2xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90 placeholder:text-white/30"
            ></textarea>
          </div>
        @endif

        @if(!empty($status['message']))
          <div class="rounded-xl border px-3 py-2 text-xs {{ ($status['type'] ?? '') === 'error' ? 'border-rose-300/20 bg-rose-500/10 text-rose-100' : 'border-emerald-300/20 bg-emerald-500/10 text-emerald-50' }}">
            {{ $status['message'] }}
          </div>
        @endif
      </div>
    @endforeach

    @if(!$canSubmit)
      <div class="rounded-xl border border-amber-300/20 bg-amber-500/10 px-3 py-2 text-xs text-amber-50/90">
        Every standard row needs a scent and quantity. Every Top Shelf row needs a complete composition and quantity before publish.
      </div>
    @endif
  @endif
</div>
