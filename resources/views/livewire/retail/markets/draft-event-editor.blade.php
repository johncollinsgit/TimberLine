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

      <div wire:key="draft-row-{{ $rowId }}" class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 p-4 space-y-4">
        <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
          <div class="min-w-0">
            <div class="text-sm font-medium text-white/90">
              {{ $row['scent_label'] ?: ($row['scent_id'] ? 'Mapped scent' : 'Needs scent mapping') }}
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
              · {{ $this->marketBoxLabelFromUnits((int) ($row['quantity'] ?? 1)) }}
            </div>
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
            wire:model.live.debounce.300ms="draftRows.{{ $rowId }}.notes"
            rows="2"
            placeholder="Notes for this draft row…"
            class="w-full rounded-2xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90 placeholder:text-white/30"
          ></textarea>
        </div>

        @if(!empty($status['message']))
          <div class="rounded-xl border px-3 py-2 text-xs {{ ($status['type'] ?? '') === 'error' ? 'border-rose-300/20 bg-rose-500/10 text-rose-100' : 'border-emerald-300/20 bg-emerald-500/10 text-emerald-50' }}">
            {{ $status['message'] }}
          </div>
        @endif
      </div>
    @endforeach

    @if(!$canSubmit)
      <div class="rounded-xl border border-amber-300/20 bg-amber-500/10 px-3 py-2 text-xs text-amber-50/90">
        Every row needs a scent and quantity before publish.
      </div>
    @endif
  @endif
</div>
