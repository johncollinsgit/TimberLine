<div class="space-y-6">
  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Event</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">{{ $event->name }}</div>
    <div class="mt-2 text-sm text-zinc-600">{{ $event->starts_at }} – {{ $event->ends_at }}</div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="flex gap-2">
      <button wire:click="$set('tab','overview')" class="px-3 py-2 rounded-full text-xs border {{ $tab==='overview' ? 'border-emerald-300/40 bg-emerald-100 text-emerald-900' : 'border-zinc-200 text-zinc-600' }}">Overview</button>
      <button wire:click="$set('tab','shipments')" class="px-3 py-2 rounded-full text-xs border {{ $tab==='shipments' ? 'border-emerald-300/40 bg-emerald-100 text-emerald-900' : 'border-zinc-200 text-zinc-600' }}">Shipment Plan</button>
      <button wire:click="$set('tab','results')" class="px-3 py-2 rounded-full text-xs border {{ $tab==='results' ? 'border-emerald-300/40 bg-emerald-100 text-emerald-900' : 'border-zinc-200 text-zinc-600' }}">Results</button>
      <button wire:click="$set('tab','recommendations')" class="px-3 py-2 rounded-full text-xs border {{ $tab==='recommendations' ? 'border-emerald-300/40 bg-emerald-100 text-emerald-900' : 'border-zinc-200 text-zinc-600' }}">Recommendations</button>
    </div>

    @if($tab==='overview')
      <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
        <input type="text" wire:model="name" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" placeholder="Event name" />
        <input type="text" wire:model="venue" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" placeholder="Venue" />
        <input type="text" wire:model="city" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" placeholder="City" />
        <input type="text" wire:model="state" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" placeholder="State" />
        <input type="date" wire:model="starts_at" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" />
        <input type="date" wire:model="ends_at" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" />
        <input type="date" wire:model="due_date" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" />
        <input type="date" wire:model="ship_date" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" />
        <select wire:model="status" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900">
          <option value="planned">Planned</option>
          <option value="active">Active</option>
          <option value="completed">Completed</option>
          <option value="archived">Archived</option>
        </select>
      </div>
      <textarea wire:model="notes" class="mt-3 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" rows="3" placeholder="Notes"></textarea>
      <div class="mt-3 flex items-center gap-2">
        <button wire:click="saveEvent" class="rounded-full border border-emerald-400/25 bg-emerald-100 px-4 py-2 text-xs text-zinc-900">Save Event</button>
        <button wire:click="createMarketPourList" class="rounded-full border border-zinc-300 bg-zinc-50 px-4 py-2 text-xs text-zinc-700">Generate Market Pour List</button>
      </div>
    @elseif($tab==='shipments')
      <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-4">
        <select wire:model="shipmentScentId" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900">
          <option value="">Scent</option>
          @foreach($scents as $s)
            <option value="{{ $s->id }}">{{ $s->name }}</option>
          @endforeach
        </select>
        <select wire:model="shipmentSizeId" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900">
          <option value="">Size</option>
          @foreach($sizes as $s)
            <option value="{{ $s->id }}">{{ $s->label ?? $s->code }}</option>
          @endforeach
        </select>
        <input type="number" wire:model="shipmentQty" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" placeholder="Qty" />
        <button wire:click="addShipment" class="rounded-xl border border-emerald-400/25 bg-emerald-100 px-3 py-2 text-sm text-zinc-900">Add</button>
      </div>
      <div class="mt-4">
        @foreach($shipments as $row)
          <div class="grid grid-cols-5 gap-2 text-xs text-zinc-700 items-center">
            <div>{{ $row->scent?->name ?? '—' }}</div>
            <div>{{ $row->size?->label ?? $row->size?->code ?? '—' }}</div>
            <div>Planned {{ $row->planned_qty }}</div>
            <div>
              <input type="number" min="0" value="{{ $row->sent_qty ?? 0 }}"
                wire:change="updateSent({{ $row->id }}, $event.target.value)"
                class="w-20 rounded-lg border border-zinc-200 bg-zinc-50 px-2 py-1 text-xs text-zinc-900" />
            </div>
            <div>
              <input type="number" min="0" value="{{ $row->returned_qty ?? 0 }}"
                wire:change="updateReturned({{ $row->id }}, $event.target.value)"
                class="w-20 rounded-lg border border-zinc-200 bg-zinc-50 px-2 py-1 text-xs text-zinc-900" />
            </div>
          </div>
        @endforeach
      </div>
    @elseif($tab==='results')
      <div class="mt-4 rounded-2xl border border-zinc-200 overflow-hidden">
        <div class="grid grid-cols-5 gap-0 bg-zinc-50 text-[11px] text-zinc-500 px-3 py-2">
          <div>Scent</div>
          <div>Size</div>
          <div>Sent</div>
          <div>Returned</div>
          <div>Sold</div>
        </div>
        <div class="divide-y divide-emerald-200/10">
          @foreach($shipments as $row)
            <div class="grid grid-cols-5 gap-0 px-3 py-2 text-xs text-zinc-700">
              <div>{{ $row->scent?->name ?? '—' }}</div>
              <div>{{ $row->size?->label ?? $row->size?->code ?? '—' }}</div>
              <div>{{ $row->sent_qty ?? 0 }}</div>
              <div>{{ $row->returned_qty ?? 0 }}</div>
              <div>
                @if($row->sold_qty !== null)
                  {{ $row->sold_qty }}
                @elseif($row->sent_qty !== null && $row->returned_qty !== null)
                  {{ max(0, $row->sent_qty - $row->returned_qty) }}
                @else
                  —
                @endif
              </div>
            </div>
          @endforeach
        </div>
      </div>
    @elseif($tab==='recommendations')
      <div class="mt-4">
        @forelse($recommendations as $r)
          <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-700 mb-2">
            <div class="flex items-center justify-between">
              <div>
                <div class="font-semibold">{{ $r['scent_name'] ?? 'Unknown scent' }}</div>
                <div class="text-[11px] text-zinc-500">{{ $r['size_label'] ?? 'Unknown size' }}</div>
              </div>
              <div class="text-sm text-emerald-900">{{ $r['recommended_qty'] }}</div>
            </div>
            <div class="mt-2 text-[11px] text-zinc-500">
              {{ $r['reason']['basis'] ?? 'history' }} · {{ $r['reason']['confidence'] ?? 'low' }} confidence · n={{ $r['reason']['history_count'] ?? 0 }}
            </div>
            <div class="mt-1 text-[11px] text-zinc-500">
              Sent {{ $r['reason']['avg_sent'] ?? 0 }} · Returned {{ $r['reason']['avg_returned'] ?? 0 }} · Sold {{ $r['reason']['avg_sold'] ?? 0 }} · Growth {{ $r['reason']['growth_factor'] ?? 1 }} · Safety {{ $r['reason']['safety_stock'] ?? 0 }}
            </div>
          </div>
        @empty
          <div class="text-xs text-zinc-500">No recommendations yet. Add shipment history or seed demo data.</div>
        @endforelse
        <button wire:click="createMarketPourList" class="mt-3 rounded-full border border-emerald-400/25 bg-emerald-100 px-4 py-2 text-xs text-zinc-900">
          Add to Market Pour List
        </button>
      </div>
    @endif
  </section>
</div>
