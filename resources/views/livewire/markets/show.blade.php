<div class="space-y-6">
  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Market Pour List</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">{{ $list->title }}</div>
    <div class="mt-2 text-sm text-zinc-600">Status: {{ $list->status }}</div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-white p-6 space-y-4">
    <div class="text-xs text-emerald-800">Events in list</div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
      @foreach($events as $event)
        <label class="flex items-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-700">
          <input type="checkbox" wire:model="selectedEvents" value="{{ $event->id }}">
          {{ $event->name }} ({{ $event->starts_at }} – {{ $event->ends_at }})
        </label>
      @endforeach
    </div>
    <div class="flex items-center gap-3">
      <div class="flex items-center gap-2 text-[11px] text-zinc-500">
        Growth
        <input type="number" step="0.01" wire:model="growthFactor" class="w-20 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-900" />
      </div>
      <div class="flex items-center gap-2 text-[11px] text-zinc-500">
        Safety
        <input type="number" step="0.01" wire:model="safetyStock" class="w-20 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-900" />
      </div>
      <button type="button" wire:click="generate"
        class="rounded-full border border-emerald-400/25 bg-emerald-100 px-4 py-2 text-xs text-zinc-900">
        Generate Recommendations
      </button>
    </div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="text-xs text-emerald-800">Aggregated Lines</div>
    <div class="mt-3 rounded-2xl border border-zinc-200 overflow-hidden">
      <div class="grid grid-cols-6 gap-0 bg-zinc-50 text-[11px] text-zinc-500 px-3 py-2">
        <div>Scent</div>
        <div>Size</div>
        <div>Recommended</div>
        <div>Edited</div>
        <div>Why</div>
        <div></div>
      </div>
      <div class="divide-y divide-emerald-200/10">
        @foreach($lines as $line)
          <div class="grid grid-cols-6 gap-0 px-3 py-2 text-xs text-zinc-700">
            <div>{{ $line->scent?->name ?? '—' }}</div>
            <div>{{ $line->size?->label ?? $line->size?->code ?? '—' }}</div>
            <div>{{ $line->recommended_qty }}</div>
            <div>
              <input type="number" min="0" value="{{ $line->edited_qty ?? $line->recommended_qty }}"
                wire:change="updateLine({{ $line->id }}, $event.target.value)"
                class="w-20 rounded-lg border border-zinc-200 bg-zinc-50 px-2 py-1 text-xs text-zinc-900 appearance-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none" />
            </div>
            <div class="text-[11px] text-zinc-500">
              @if(!empty($line->reason_json['sources']))
                {{ data_get($line->reason_json, 'sources.0.basis', 'history') }} ·
                {{ data_get($line->reason_json, 'sources.0.confidence', 'low') }}
              @else
                —
              @endif
            </div>
            <div class="text-zinc-500">OK</div>
          </div>
        @endforeach
      </div>
    </div>
    <div class="mt-3 text-xs text-emerald-800">Total units: {{ $totalQty }}</div>
    <div class="mt-4">
      <button type="button" wire:click="publish"
        class="rounded-full border border-emerald-400/25 bg-emerald-100 px-4 py-2 text-xs text-zinc-900">
        Publish to Pouring Room
      </button>
    </div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="text-xs text-emerald-800">Per-Event Lines</div>
    <div class="mt-3 rounded-2xl border border-zinc-200 overflow-hidden">
      <div class="grid grid-cols-6 gap-0 bg-zinc-50 text-[11px] text-zinc-500 px-3 py-2">
        <div>Event</div>
        <div>Scent</div>
        <div>Size</div>
        <div>Recommended</div>
        <div>Edited</div>
        <div></div>
      </div>
      <div class="divide-y divide-emerald-200/10">
        @foreach($eventLines as $line)
          <div class="grid grid-cols-6 gap-0 px-3 py-2 text-xs text-zinc-700">
            <div>{{ $line->event?->name ?? '—' }}</div>
            <div>{{ $line->scent?->name ?? '—' }}</div>
            <div>{{ $line->size?->label ?? $line->size?->code ?? '—' }}</div>
            <div>{{ $line->recommended_qty }}</div>
            <div>{{ $line->edited_qty ?? '—' }}</div>
            <div class="text-zinc-500">Event line</div>
          </div>
        @endforeach
      </div>
    </div>
  </section>
</div>
