<div class="mt-4 space-y-2">
  @if(!$selectedEventId || !$event)
    <div class="rounded-2xl border border-dashed border-emerald-200/15 bg-emerald-500/5 p-4 text-sm text-emerald-50/70">
      Select an event first to view and edit candles to be poured.
    </div>
  @elseif($items->isEmpty())
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

    @foreach($items as $item)
      <div class="flex flex-col gap-2 rounded-2xl border border-emerald-200/10 bg-emerald-500/5 px-4 py-3 md:flex-row md:items-center md:justify-between min-w-0">
        <div class="min-w-0">
          <div class="text-sm text-white/90">
            {{ $item->scent?->display_name ?: $item->scent?->name ?: ($item->sku ?: 'Unknown scent') }}
            <span class="text-emerald-100/60">· {{ ($item->source ?? '') === 'market_top_shelf_template' ? 'Top Shelf' : 'Market Box' }}</span>
          </div>
          <div class="text-xs text-emerald-100/60">
            @if(($item->source ?? '') === 'market_box_manual')
              Manual
            @elseif(($item->source ?? '') === 'market_box_draft')
              Market draft
            @elseif(($item->source ?? '') === 'market_top_shelf_template')
              Top Shelf default (16oz only)
            @else
              Event prefill
            @endif
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <div class="flex items-center rounded-full border border-emerald-200/15 bg-black/30 px-2 py-1 shrink-0">
            <button type="button" wire:click="decrementItemQuantity({{ $item->id }})"
              class="h-6 w-6 rounded-full border border-emerald-300/20 bg-emerald-500/10 text-emerald-50 hover:bg-emerald-500/20 transition">
              −
            </button>
            @if(($item->source ?? '') === 'market_top_shelf_template')
              <span class="w-20 text-center text-xs text-white/90">{{ (int)($item->quantity ?? 0) }} 16oz</span>
            @else
              <span class="w-20 text-center text-xs text-white/90">{{ $this->marketBoxLabel($item) }}</span>
            @endif
            <button type="button" wire:click="incrementItemQuantity({{ $item->id }})"
              class="h-6 w-6 rounded-full border border-emerald-300/20 bg-emerald-500/10 text-emerald-50 hover:bg-emerald-500/20 transition">
              +
            </button>
          </div>

          <button type="button" wire:click="removeItem({{ $item->id }})"
            class="px-2.5 py-1 rounded-full text-xs border border-red-400/20 bg-red-500/10 text-red-100 hover:bg-red-500/20 transition">
            Remove
          </button>
        </div>
      </div>
    @endforeach

    @if(!$canSubmit)
      <div class="rounded-xl border border-amber-300/20 bg-amber-500/10 px-3 py-2 text-xs text-amber-50/90">
        All boxes must have a mapped scent and quantity before submit.
      </div>
    @endif
  @endif
</div>
