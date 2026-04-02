<div class="space-y-6">
  <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Inventory</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">Material Inventory Foundation</div>
    <div class="mt-2 text-sm text-zinc-600">
      Canonical truth is grams on hand. Wax can be viewed as pounds and 45 lb box equivalents.
    </div>
  </section>

  @if($statusMessage)
    <section class="rounded-2xl border px-4 py-3 text-sm {{
      $statusLevel === 'success'
        ? 'border-emerald-400/40 bg-emerald-100 text-emerald-900'
        : ($statusLevel === 'error'
          ? 'border-rose-400/40 bg-rose-100 text-rose-900'
          : 'border-zinc-300 bg-zinc-50 text-zinc-700')
    }}">
      <div class="flex items-center justify-between gap-3">
        <span>{{ $statusMessage }}</span>
        <button type="button" wire:click="clearStatusMessage" class="text-xs text-zinc-600 hover:text-zinc-950">Dismiss</button>
      </div>
    </section>
  @endif

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <div class="text-xs uppercase tracking-[0.3em] text-emerald-800">Materials</div>
        <div class="mt-1 text-sm text-zinc-600">Track oils + wax in grams, apply signed adjustments, and maintain reorder thresholds.</div>
      </div>
      <input
        type="text"
        wire:model.live.debounce.250ms="materialSearch"
        placeholder="Search oils or wax..."
        class="h-10 w-full rounded-2xl border border-zinc-200 bg-zinc-50 px-3 text-sm text-zinc-900 sm:w-72"
      />
    </div>

    <div class="mt-6 rounded-2xl border border-zinc-200 overflow-hidden">
      <div class="grid grid-cols-12 bg-zinc-50 px-3 py-2 text-[11px] uppercase tracking-[0.2em] text-zinc-500">
        <div class="col-span-3">Oil</div>
        <div class="col-span-2 text-right">On Hand (g)</div>
        <div class="col-span-2 text-right">Reorder At (g)</div>
        <div class="col-span-1 text-center">State</div>
        <div class="col-span-2 text-right">Set On Hand</div>
        <div class="col-span-2 text-right">Adjust (+/- g)</div>
      </div>
      <div class="divide-y divide-emerald-200/10">
        @forelse($oilRows as $row)
          @php($id = (int) $row['id'])
          <div class="grid grid-cols-12 items-center gap-2 px-3 py-3 text-xs text-zinc-800 {{ (int) ($focusOilId ?? 0) === $id ? 'bg-emerald-100 ring-1 ring-emerald-300/45' : '' }}">
            <div class="col-span-3">
              <div class="font-semibold">{{ $row['name'] }}</div>
              <div class="text-[11px] text-zinc-500">{{ $row['supplier'] ?: 'No supplier' }}</div>
            </div>
            <div class="col-span-2 text-right font-medium">{{ number_format((float) $row['on_hand_grams'], 2) }}</div>
            <div class="col-span-2">
              <div class="flex items-center justify-end gap-2">
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  wire:model.defer="thresholdOil.{{ $id }}"
                  class="h-8 w-24 rounded-lg border border-zinc-200 bg-zinc-50 px-2 text-right text-xs text-zinc-950"
                />
                <button
                  type="button"
                  wire:click="saveOilThreshold({{ $id }})"
                  class="h-8 rounded-lg border border-zinc-300 bg-zinc-100 px-2 text-[11px] font-medium text-zinc-800 hover:bg-zinc-100"
                >
                  Save
                </button>
              </div>
            </div>
            <div class="col-span-1 text-center">
              @php($status = (string) ($row['state']['status'] ?? 'ok'))
              <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium {{
                $status === 'reorder'
                  ? 'bg-rose-100 text-rose-900'
                  : ($status === 'low' ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-900')
              }}">
                {{ strtoupper($row['state']['label'] ?? 'OK') }}
              </span>
            </div>
            <div class="col-span-2">
              <div class="flex items-center justify-end gap-2">
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  wire:model.defer="targetOnHandOil.{{ $id }}"
                  class="h-8 w-24 rounded-lg border border-zinc-200 bg-zinc-50 px-2 text-right text-xs text-zinc-950"
                />
                <button
                  type="button"
                  wire:click="setOilOnHand({{ $id }})"
                  class="h-8 rounded-lg border border-emerald-300/30 bg-emerald-100 px-2 text-[11px] font-medium text-zinc-950 hover:bg-emerald-500/30"
                >
                  Set
                </button>
              </div>
            </div>
            <div class="col-span-2">
              <div class="flex items-center justify-end gap-2">
                <input
                  type="number"
                  step="0.01"
                  wire:model.defer="adjustDeltaOil.{{ $id }}"
                  class="h-8 w-20 rounded-lg border border-zinc-200 bg-zinc-50 px-2 text-right text-xs text-zinc-950"
                />
                <select wire:model.defer="adjustReasonOil.{{ $id }}" class="h-8 rounded-lg border border-zinc-200 bg-zinc-50 px-2 text-[11px] text-zinc-900">
                  @foreach($adjustmentReasons as $reason)
                    <option value="{{ $reason }}">{{ $reason }}</option>
                  @endforeach
                </select>
                <button
                  type="button"
                  wire:click="applyOilAdjustment({{ $id }})"
                  class="h-8 rounded-lg border border-zinc-300 bg-zinc-100 px-2 text-[11px] font-medium text-zinc-800 hover:bg-zinc-100"
                >
                  Apply
                </button>
              </div>
            </div>
          </div>
        @empty
          <div class="px-3 py-3 text-xs text-zinc-500">No oil inventory records found.</div>
        @endforelse
      </div>
    </div>

    <div class="mt-6 rounded-2xl border border-zinc-200 overflow-hidden">
      <div class="grid grid-cols-12 bg-zinc-50 px-3 py-2 text-[11px] uppercase tracking-[0.2em] text-zinc-500">
        <div class="col-span-3">Wax</div>
        <div class="col-span-2 text-right">On Hand</div>
        <div class="col-span-2 text-right">Reorder At</div>
        <div class="col-span-1 text-center">State</div>
        <div class="col-span-2 text-right">Set On Hand (g)</div>
        <div class="col-span-2 text-right">Adjust (+/- g)</div>
      </div>
      <div class="divide-y divide-emerald-200/10">
        @forelse($waxRows as $row)
          @php($id = (int) $row['id'])
          <div class="grid grid-cols-12 items-center gap-2 px-3 py-3 text-xs text-zinc-800">
            <div class="col-span-3">
              <div class="font-semibold">{{ $row['name'] }}</div>
              <div class="text-[11px] text-zinc-500">
                {{ number_format((float) $row['on_hand_grams'], 2) }}g · {{ number_format((float) $row['on_hand_pounds'], 2) }} lb · {{ number_format((float) $row['on_hand_boxes'], 3) }} boxes
              </div>
            </div>
            <div class="col-span-2 text-right font-medium">{{ number_format((float) $row['on_hand_grams'], 2) }}g</div>
            <div class="col-span-2">
              <div class="flex items-center justify-end gap-2">
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  wire:model.defer="thresholdWax.{{ $id }}"
                  class="h-8 w-24 rounded-lg border border-zinc-200 bg-zinc-50 px-2 text-right text-xs text-zinc-950"
                />
                <button
                  type="button"
                  wire:click="saveWaxThreshold({{ $id }})"
                  class="h-8 rounded-lg border border-zinc-300 bg-zinc-100 px-2 text-[11px] font-medium text-zinc-800 hover:bg-zinc-100"
                >
                  Save
                </button>
              </div>
            </div>
            <div class="col-span-1 text-center">
              @php($status = (string) ($row['state']['status'] ?? 'ok'))
              <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium {{
                $status === 'reorder'
                  ? 'bg-rose-100 text-rose-900'
                  : ($status === 'low' ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-900')
              }}">
                {{ strtoupper($row['state']['label'] ?? 'OK') }}
              </span>
            </div>
            <div class="col-span-2">
              <div class="flex items-center justify-end gap-2">
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  wire:model.defer="targetOnHandWax.{{ $id }}"
                  class="h-8 w-24 rounded-lg border border-zinc-200 bg-zinc-50 px-2 text-right text-xs text-zinc-950"
                />
                <button
                  type="button"
                  wire:click="setWaxOnHand({{ $id }})"
                  class="h-8 rounded-lg border border-emerald-300/30 bg-emerald-100 px-2 text-[11px] font-medium text-zinc-950 hover:bg-emerald-500/30"
                >
                  Set
                </button>
              </div>
            </div>
            <div class="col-span-2">
              <div class="flex items-center justify-end gap-2">
                <input
                  type="number"
                  step="0.01"
                  wire:model.defer="adjustDeltaWax.{{ $id }}"
                  class="h-8 w-20 rounded-lg border border-zinc-200 bg-zinc-50 px-2 text-right text-xs text-zinc-950"
                />
                <select wire:model.defer="adjustReasonWax.{{ $id }}" class="h-8 rounded-lg border border-zinc-200 bg-zinc-50 px-2 text-[11px] text-zinc-900">
                  @foreach($adjustmentReasons as $reason)
                    <option value="{{ $reason }}">{{ $reason }}</option>
                  @endforeach
                </select>
                <button
                  type="button"
                  wire:click="applyWaxAdjustment({{ $id }})"
                  class="h-8 rounded-lg border border-zinc-300 bg-zinc-100 px-2 text-[11px] font-medium text-zinc-800 hover:bg-zinc-100"
                >
                  Apply
                </button>
              </div>
            </div>
          </div>
        @empty
          <div class="px-3 py-3 text-xs text-zinc-500">No wax inventory rows found.</div>
        @endforelse
      </div>
      <div class="border-t border-zinc-200 bg-zinc-50 px-3 py-2 text-[11px] text-zinc-500">
        Default wax reorder threshold: {{ number_format((float) $waxDefaultThreshold, 2) }}g (360 lb / 8 boxes of 45 lb).
      </div>
    </div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="text-xs uppercase tracking-[0.3em] text-emerald-800">Recent Adjustments</div>
    <div class="mt-1 text-sm text-zinc-600">Every manual correction is recorded with signed grams and reason.</div>
    <div class="mt-4 rounded-2xl border border-zinc-200 overflow-hidden">
      <div class="grid grid-cols-12 bg-zinc-50 px-3 py-2 text-[11px] uppercase tracking-[0.2em] text-zinc-500">
        <div class="col-span-2">When</div>
        <div class="col-span-3">Item</div>
        <div class="col-span-2 text-right">Delta (g)</div>
        <div class="col-span-2 text-right">Before → After</div>
        <div class="col-span-2">Reason</div>
        <div class="col-span-1">By</div>
      </div>
      <div class="divide-y divide-emerald-200/10">
        @forelse($recentAdjustments as $row)
          <div class="grid grid-cols-12 items-center gap-2 px-3 py-2 text-xs text-zinc-700">
            <div class="col-span-2 text-zinc-600">{{ optional($row['created_at'])->format('M d H:i') }}</div>
            <div class="col-span-3">
              <div>{{ $row['item_name'] }}</div>
              <div class="text-[10px] uppercase tracking-[0.15em] text-zinc-500">{{ $row['item_type'] }}</div>
            </div>
            <div class="col-span-2 text-right {{ (float) $row['grams_delta'] >= 0 ? 'text-emerald-200' : 'text-rose-200' }}">
              {{ (float) $row['grams_delta'] >= 0 ? '+' : '' }}{{ number_format((float) $row['grams_delta'], 2) }}
            </div>
            <div class="col-span-2 text-right">{{ number_format((float) $row['before_grams'], 2) }} → {{ number_format((float) $row['after_grams'], 2) }}</div>
            <div class="col-span-2">{{ $row['reason'] }}</div>
            <div class="col-span-1 text-zinc-500">{{ $row['performed_by'] ?: '—' }}</div>
          </div>
        @empty
          <div class="px-3 py-3 text-xs text-zinc-500">No inventory adjustments yet.</div>
        @endforelse
      </div>
    </div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Legacy Inventory Counts</div>
    <div class="mt-2 text-sm text-zinc-600">Tracks candles poured for inventory (not tied to a customer).</div>
    <div class="mt-4 flex items-center justify-between">
      <div class="text-xs uppercase tracking-[0.3em] text-emerald-800">All Scents</div>
      <input type="text" wire:model.live.debounce.250ms="search"
        placeholder="Search scents..."
        class="h-9 rounded-2xl border border-zinc-200 bg-zinc-50 px-3 text-xs text-zinc-900" />
    </div>
    <div class="mt-4 rounded-2xl border border-zinc-200 overflow-hidden">
      <div class="grid grid-cols-5 gap-0 bg-zinc-50 text-[11px] text-zinc-500 px-3 py-2">
        <div>Scent</div>
        <div>Size</div>
        <div>Total Poured</div>
        <div>On Hand</div>
        <div>Status</div>
      </div>
      <div class="divide-y divide-emerald-200/10">
        @forelse($scents as $scent)
          <div class="grid grid-cols-5 gap-0 px-3 py-2 text-xs text-zinc-700">
            <div class="font-semibold">{{ $scent['name'] }}</div>
            <div class="text-zinc-600">{{ $scent['size'] }}</div>
            <div>{{ $scent['qty'] }}</div>
            <div>
              <input type="number" min="0" value="{{ $scent['on_hand'] }}"
                wire:change="updateOnHand({{ $scent['id'] }}, {{ $scent['size_id'] ?? 'null' }}, $event.target.value)"
                class="w-20 rounded-lg border border-zinc-200 bg-zinc-50 px-2 py-1 text-xs text-zinc-900 appearance-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none" />
            </div>
            <div class="text-zinc-500">Unclaimed</div>
          </div>
        @empty
          <div class="px-3 py-3 text-xs text-zinc-500">No inventory pours yet.</div>
        @endforelse
      </div>
    </div>
  </section>
</div>
