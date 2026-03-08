<div class="space-y-6">
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)]">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Inventory</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Material Inventory Foundation</div>
    <div class="mt-2 text-sm text-emerald-50/70">
      Canonical truth is grams on hand. Wax can be viewed as pounds and 45 lb box equivalents.
    </div>
  </section>

  @if($statusMessage)
    <section class="rounded-2xl border px-4 py-3 text-sm {{
      $statusLevel === 'success'
        ? 'border-emerald-400/40 bg-emerald-500/10 text-emerald-100'
        : ($statusLevel === 'error'
          ? 'border-rose-400/40 bg-rose-500/10 text-rose-100'
          : 'border-white/15 bg-white/5 text-white/80')
    }}">
      <div class="flex items-center justify-between gap-3">
        <span>{{ $statusMessage }}</span>
        <button type="button" wire:click="clearStatusMessage" class="text-xs text-white/70 hover:text-white">Dismiss</button>
      </div>
    </section>
  @endif

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60">Materials</div>
        <div class="mt-1 text-sm text-emerald-50/75">Track oils + wax in grams, apply signed adjustments, and maintain reorder thresholds.</div>
      </div>
      <input
        type="text"
        wire:model.live.debounce.250ms="materialSearch"
        placeholder="Search oils or wax..."
        class="h-10 w-full rounded-2xl border border-emerald-200/15 bg-black/20 px-3 text-sm text-white/90 sm:w-72"
      />
    </div>

    <div class="mt-6 rounded-2xl border border-emerald-200/10 overflow-hidden">
      <div class="grid grid-cols-12 bg-black/35 px-3 py-2 text-[11px] uppercase tracking-[0.2em] text-white/55">
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
          <div class="grid grid-cols-12 items-center gap-2 px-3 py-3 text-xs text-white/85 {{ (int) ($focusOilId ?? 0) === $id ? 'bg-emerald-500/15 ring-1 ring-emerald-300/45' : '' }}">
            <div class="col-span-3">
              <div class="font-semibold">{{ $row['name'] }}</div>
              <div class="text-[11px] text-white/50">{{ $row['supplier'] ?: 'No supplier' }}</div>
            </div>
            <div class="col-span-2 text-right font-medium">{{ number_format((float) $row['on_hand_grams'], 2) }}</div>
            <div class="col-span-2">
              <div class="flex items-center justify-end gap-2">
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  wire:model.defer="thresholdOil.{{ $id }}"
                  class="h-8 w-24 rounded-lg border border-emerald-200/15 bg-black/20 px-2 text-right text-xs text-white"
                />
                <button
                  type="button"
                  wire:click="saveOilThreshold({{ $id }})"
                  class="h-8 rounded-lg border border-white/15 bg-white/10 px-2 text-[11px] font-medium text-white/85 hover:bg-white/15"
                >
                  Save
                </button>
              </div>
            </div>
            <div class="col-span-1 text-center">
              @php($status = (string) ($row['state']['status'] ?? 'ok'))
              <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium {{
                $status === 'reorder'
                  ? 'bg-rose-500/20 text-rose-100'
                  : ($status === 'low' ? 'bg-amber-500/20 text-amber-100' : 'bg-emerald-500/20 text-emerald-100')
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
                  class="h-8 w-24 rounded-lg border border-emerald-200/15 bg-black/20 px-2 text-right text-xs text-white"
                />
                <button
                  type="button"
                  wire:click="setOilOnHand({{ $id }})"
                  class="h-8 rounded-lg border border-emerald-300/30 bg-emerald-500/20 px-2 text-[11px] font-medium text-white hover:bg-emerald-500/30"
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
                  class="h-8 w-20 rounded-lg border border-emerald-200/15 bg-black/20 px-2 text-right text-xs text-white"
                />
                <select wire:model.defer="adjustReasonOil.{{ $id }}" class="h-8 rounded-lg border border-emerald-200/15 bg-black/20 px-2 text-[11px] text-white/90">
                  @foreach($adjustmentReasons as $reason)
                    <option value="{{ $reason }}">{{ $reason }}</option>
                  @endforeach
                </select>
                <button
                  type="button"
                  wire:click="applyOilAdjustment({{ $id }})"
                  class="h-8 rounded-lg border border-white/15 bg-white/10 px-2 text-[11px] font-medium text-white/85 hover:bg-white/15"
                >
                  Apply
                </button>
              </div>
            </div>
          </div>
        @empty
          <div class="px-3 py-3 text-xs text-white/60">No oil inventory records found.</div>
        @endforelse
      </div>
    </div>

    <div class="mt-6 rounded-2xl border border-emerald-200/10 overflow-hidden">
      <div class="grid grid-cols-12 bg-black/35 px-3 py-2 text-[11px] uppercase tracking-[0.2em] text-white/55">
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
          <div class="grid grid-cols-12 items-center gap-2 px-3 py-3 text-xs text-white/85">
            <div class="col-span-3">
              <div class="font-semibold">{{ $row['name'] }}</div>
              <div class="text-[11px] text-white/55">
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
                  class="h-8 w-24 rounded-lg border border-emerald-200/15 bg-black/20 px-2 text-right text-xs text-white"
                />
                <button
                  type="button"
                  wire:click="saveWaxThreshold({{ $id }})"
                  class="h-8 rounded-lg border border-white/15 bg-white/10 px-2 text-[11px] font-medium text-white/85 hover:bg-white/15"
                >
                  Save
                </button>
              </div>
            </div>
            <div class="col-span-1 text-center">
              @php($status = (string) ($row['state']['status'] ?? 'ok'))
              <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium {{
                $status === 'reorder'
                  ? 'bg-rose-500/20 text-rose-100'
                  : ($status === 'low' ? 'bg-amber-500/20 text-amber-100' : 'bg-emerald-500/20 text-emerald-100')
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
                  class="h-8 w-24 rounded-lg border border-emerald-200/15 bg-black/20 px-2 text-right text-xs text-white"
                />
                <button
                  type="button"
                  wire:click="setWaxOnHand({{ $id }})"
                  class="h-8 rounded-lg border border-emerald-300/30 bg-emerald-500/20 px-2 text-[11px] font-medium text-white hover:bg-emerald-500/30"
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
                  class="h-8 w-20 rounded-lg border border-emerald-200/15 bg-black/20 px-2 text-right text-xs text-white"
                />
                <select wire:model.defer="adjustReasonWax.{{ $id }}" class="h-8 rounded-lg border border-emerald-200/15 bg-black/20 px-2 text-[11px] text-white/90">
                  @foreach($adjustmentReasons as $reason)
                    <option value="{{ $reason }}">{{ $reason }}</option>
                  @endforeach
                </select>
                <button
                  type="button"
                  wire:click="applyWaxAdjustment({{ $id }})"
                  class="h-8 rounded-lg border border-white/15 bg-white/10 px-2 text-[11px] font-medium text-white/85 hover:bg-white/15"
                >
                  Apply
                </button>
              </div>
            </div>
          </div>
        @empty
          <div class="px-3 py-3 text-xs text-white/60">No wax inventory rows found.</div>
        @endforelse
      </div>
      <div class="border-t border-emerald-200/10 bg-black/20 px-3 py-2 text-[11px] text-white/60">
        Default wax reorder threshold: {{ number_format((float) $waxDefaultThreshold, 2) }}g (360 lb / 8 boxes of 45 lb).
      </div>
    </div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60">Recent Adjustments</div>
    <div class="mt-1 text-sm text-emerald-50/75">Every manual correction is recorded with signed grams and reason.</div>
    <div class="mt-4 rounded-2xl border border-emerald-200/10 overflow-hidden">
      <div class="grid grid-cols-12 bg-black/35 px-3 py-2 text-[11px] uppercase tracking-[0.2em] text-white/55">
        <div class="col-span-2">When</div>
        <div class="col-span-3">Item</div>
        <div class="col-span-2 text-right">Delta (g)</div>
        <div class="col-span-2 text-right">Before → After</div>
        <div class="col-span-2">Reason</div>
        <div class="col-span-1">By</div>
      </div>
      <div class="divide-y divide-emerald-200/10">
        @forelse($recentAdjustments as $row)
          <div class="grid grid-cols-12 items-center gap-2 px-3 py-2 text-xs text-white/80">
            <div class="col-span-2 text-white/65">{{ optional($row['created_at'])->format('M d H:i') }}</div>
            <div class="col-span-3">
              <div>{{ $row['item_name'] }}</div>
              <div class="text-[10px] uppercase tracking-[0.15em] text-white/45">{{ $row['item_type'] }}</div>
            </div>
            <div class="col-span-2 text-right {{ (float) $row['grams_delta'] >= 0 ? 'text-emerald-200' : 'text-rose-200' }}">
              {{ (float) $row['grams_delta'] >= 0 ? '+' : '' }}{{ number_format((float) $row['grams_delta'], 2) }}
            </div>
            <div class="col-span-2 text-right">{{ number_format((float) $row['before_grams'], 2) }} → {{ number_format((float) $row['after_grams'], 2) }}</div>
            <div class="col-span-2">{{ $row['reason'] }}</div>
            <div class="col-span-1 text-white/60">{{ $row['performed_by'] ?: '—' }}</div>
          </div>
        @empty
          <div class="px-3 py-3 text-xs text-white/60">No inventory adjustments yet.</div>
        @endforelse
      </div>
    </div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Legacy Inventory Counts</div>
    <div class="mt-2 text-sm text-emerald-50/70">Tracks candles poured for inventory (not tied to a customer).</div>
    <div class="mt-4 flex items-center justify-between">
      <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60">All Scents</div>
      <input type="text" wire:model.live.debounce.250ms="search"
        placeholder="Search scents..."
        class="h-9 rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-xs text-white/90" />
    </div>
    <div class="mt-4 rounded-2xl border border-emerald-200/10 overflow-hidden">
      <div class="grid grid-cols-5 gap-0 bg-black/30 text-[11px] text-white/50 px-3 py-2">
        <div>Scent</div>
        <div>Size</div>
        <div>Total Poured</div>
        <div>On Hand</div>
        <div>Status</div>
      </div>
      <div class="divide-y divide-emerald-200/10">
        @forelse($scents as $scent)
          <div class="grid grid-cols-5 gap-0 px-3 py-2 text-xs text-white/80">
            <div class="font-semibold">{{ $scent['name'] }}</div>
            <div class="text-white/70">{{ $scent['size'] }}</div>
            <div>{{ $scent['qty'] }}</div>
            <div>
              <input type="number" min="0" value="{{ $scent['on_hand'] }}"
                wire:change="updateOnHand({{ $scent['id'] }}, {{ $scent['size_id'] ?? 'null' }}, $event.target.value)"
                class="w-20 rounded-lg border border-emerald-200/10 bg-black/20 px-2 py-1 text-xs text-white/90 appearance-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none" />
            </div>
            <div class="text-white/50">Unclaimed</div>
          </div>
        @empty
          <div class="px-3 py-3 text-xs text-white/60">No inventory pours yet.</div>
        @endforelse
      </div>
    </div>
  </section>
</div>
