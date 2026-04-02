<div class="space-y-6">
  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Pouring</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">Markets</div>
  </section>

  @foreach($requests as $request)
    <section class="rounded-3xl border border-zinc-200 bg-white p-6">
      <div class="flex items-center justify-between">
        <div>
          <div class="text-sm text-zinc-700">Request #{{ $request->id }} · {{ $request->status }}</div>
          <div class="text-xs text-emerald-800">Due {{ $request->due_date }}</div>
        </div>
        @if($request->status !== 'closed')
          <button wire:click="closeRequest({{ $request->id }})"
            class="rounded-full border border-emerald-400/25 bg-emerald-100 px-3 py-2 text-xs text-zinc-900">
            Mark Complete
          </button>
        @endif
      </div>

      <div class="mt-4 rounded-2xl border border-zinc-200 overflow-hidden">
        <div class="grid grid-cols-5 gap-0 bg-zinc-50 text-[11px] text-zinc-500 px-3 py-2">
          <div>Scent</div>
          <div>Size</div>
          <div>Qty</div>
          <div>Produced</div>
          <div></div>
        </div>
        <div class="divide-y divide-emerald-200/10">
          @foreach($request->lines as $line)
            <div class="grid grid-cols-5 gap-0 px-3 py-2 text-xs text-zinc-700">
              <div>{{ $line->scent?->name ?? '—' }}</div>
              <div>{{ $line->size?->label ?? $line->size?->code ?? '—' }}</div>
              <div>{{ $line->qty }}</div>
              <div>
                <input type="number" min="0" value="{{ $line->produced_qty }}"
                  wire:change="markProduced({{ $line->id }}, $event.target.value)"
                  class="w-20 rounded-lg border border-zinc-200 bg-zinc-50 px-2 py-1 text-xs text-zinc-900" />
              </div>
              <div class="text-zinc-500">OK</div>
            </div>
          @endforeach
        </div>
      </div>
    </section>
  @endforeach
</div>
