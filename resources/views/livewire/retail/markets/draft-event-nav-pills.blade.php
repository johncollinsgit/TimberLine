<div>
  <div class="text-xs text-emerald-800">Draft events</div>
  @if(empty($events))
    <div class="mt-2 rounded-xl border border-dashed border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-600">
      No drafted events yet.
    </div>
  @else
    <div class="mt-2 flex flex-wrap gap-2">
      @foreach($events as $event)
        @php($isSelected = (int)($selectedEventId ?? 0) === (int)($event['id'] ?? 0))
        <button
          type="button"
          wire:click="selectDraftEvent({{ (int)($event['id'] ?? 0) }})"
          class="rounded-full border px-3 py-1 text-xs {{ $isSelected ? 'border-zinc-300 bg-emerald-100 text-zinc-950' : 'border-zinc-200 text-emerald-800 hover:bg-zinc-50' }}"
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
