<div>
  <div class="text-xs text-emerald-100/65">Draft events</div>
  @if(empty($events))
    <div class="mt-2 rounded-xl border border-dashed border-emerald-200/10 bg-black/10 p-3 text-xs text-emerald-50/70">
      No drafted events yet.
    </div>
  @else
    <div class="mt-2 flex flex-wrap gap-2">
      @foreach($events as $event)
        @php($isSelected = (int)($selectedEventId ?? 0) === (int)($event['id'] ?? 0))
        <button
          type="button"
          wire:click="selectDraftEvent({{ (int)($event['id'] ?? 0) }})"
          class="rounded-full border px-3 py-1 text-xs {{ $isSelected ? 'border-emerald-300/35 bg-emerald-500/15 text-white' : 'border-white/10 text-emerald-100/75 hover:bg-white/5' }}"
        >
          {{ $event['title'] ?? 'Event' }}
          @if(!empty($event['starts_at']))
            · {{ \Illuminate\Support\Carbon::parse($event['starts_at'])->format('M j') }}
          @endif
        </button>
      @endforeach
    </div>
  @endif
</div>
