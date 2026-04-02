@php
  $context = $mappingContext ?? [];
  $searchInput = trim((string) ($existingScentSearch ?? ''));
  $rawLabel = trim((string) ($context['raw_label'] ?? ''));
  $rawTitle = trim((string) ($context['raw_title'] ?? ''));
  $rawVariant = trim((string) ($context['raw_variant'] ?? ''));
  $accountName = trim((string) ($context['account_name'] ?? ''));
  $isWholesale = (bool) ($context['is_wholesale'] ?? false);
  $orderNumber = trim((string) ($context['order_number'] ?? ''));
  $customerName = trim((string) ($context['customer_name'] ?? ''));
  $channelLabel = trim((string) ($context['channel_label'] ?? ''));
  $sourceStore = trim((string) ($context['source_store'] ?? ''));
  $lineQuantity = (int) ($context['line_quantity'] ?? 0);
  $notesLines = is_array($context['notes_lines'] ?? null) ? $context['notes_lines'] : [];
  $notesPreview = trim((string) ($context['notes_preview'] ?? ''));
  $labelText = trim((string) ($context['label_text'] ?? ''));
  $detectedProductForm = trim((string) ($context['detected_product_form'] ?? ''));
  $effectiveProductForm = trim((string) ($context['product_form_hint'] ?? ''));
  $productFormLabel = match($effectiveProductForm) {
    'room_spray' => 'Room Spray',
    'wax_melt' => 'Wax Melt',
    'candle' => 'Candle',
    default => 'Unspecified',
  };
  $detectedProductFormLabel = match($detectedProductForm) {
    'room_spray' => 'Room Spray',
    'wax_melt' => 'Wax Melt',
    'candle' => 'Candle',
    default => 'Not detected',
  };
  $sameCount = count($sameNameExceptionIds ?? []);
@endphp

<div class="space-y-4">
  <div class="rounded-2xl border border-emerald-300/20 bg-emerald-100 px-4 py-3 text-xs text-zinc-600">
    Resolve incoming names by mapping to existing scents. If it does not exist yet, launch the New Scent Wizard.
    <div class="mt-1 text-emerald-800">
      Scent identity and product form are separate. Room spray mappings keep the same scent identity but use room-spray material usage (oil + alcohol + water, no wax).
    </div>
  </div>

  <div class="rounded-2xl border border-emerald-300/20 bg-emerald-950/35 p-4">
    <div class="text-xs uppercase tracking-[0.28em] text-emerald-800">Resolve Mapping</div>
    <div class="mt-2 text-lg font-semibold text-zinc-950">{{ $rawLabel !== '' ? $rawLabel : 'Unnamed incoming scent' }}</div>
    <div class="mt-1 text-sm text-zinc-600">{{ $rawVariant !== '' ? $rawVariant : 'No variant details provided' }}</div>
    @if($isWholesale)
      <div class="mt-2 inline-flex items-center rounded-full border border-zinc-200 bg-emerald-100 px-2 py-0.5 text-[11px] text-emerald-900">
        Wholesale context{{ $accountName !== '' ? ' · '.$accountName : '' }}
      </div>
    @endif
    @if($effectiveProductForm !== '')
      <div class="mt-2 inline-flex items-center rounded-full border border-cyan-200/30 bg-cyan-500/15 px-2 py-0.5 text-[11px] text-cyan-50">
        Product form · {{ $productFormLabel }}
      </div>
    @endif

    <div class="mt-3 grid gap-2 text-xs text-emerald-800 md:grid-cols-2">
      <div>Order: {{ $orderNumber !== '' ? '#'.ltrim($orderNumber, '#') : '—' }}</div>
      <div>Customer: {{ $customerName !== '' ? $customerName : '—' }}</div>
      <div>Channel: {{ $channelLabel !== '' ? $channelLabel : '—' }}</div>
      <div>Store: {{ $sourceStore !== '' ? $sourceStore : '—' }}</div>
      <div>Line Qty: {{ $lineQuantity > 0 ? $lineQuantity : '—' }}</div>
      <div>Raw Product: {{ $rawTitle !== '' ? $rawTitle : '—' }}</div>
      <div class="md:col-span-2">Raw Variant: {{ $rawVariant !== '' ? $rawVariant : '—' }}</div>
      @if($labelText !== '')
        <div class="md:col-span-2">Label / personalization: {{ $labelText }}</div>
      @endif
    </div>

    @if($notesPreview !== '')
      <div class="mt-3 rounded-xl border border-amber-300/25 bg-amber-100 p-3">
        <div class="text-[11px] uppercase tracking-[0.24em] text-amber-800">Notes</div>
        <div class="mt-2 space-y-1 text-xs text-amber-800">
          @foreach($notesLines as $line)
            <div>{{ $line }}</div>
          @endforeach
        </div>
      </div>
    @endif
  </div>

  <div class="rounded-2xl border border-cyan-300/20 bg-cyan-500/10 p-4">
    <div class="text-xs uppercase tracking-[0.24em] text-cyan-100/70">Product Form Context</div>
    <div class="mt-2 grid gap-3 md:grid-cols-[1fr_auto] md:items-end">
      <div>
        <label class="text-xs text-cyan-100/70">Map this exception as</label>
        <select
          wire:model.live="productForm"
          class="mt-1 w-full rounded-xl border border-cyan-200/20 bg-zinc-50 px-3 py-2 text-sm text-zinc-900"
        >
          <option value="">Auto-detect ({{ $detectedProductFormLabel }})</option>
          <option value="candle">Candle</option>
          <option value="room_spray">Room Spray</option>
          <option value="wax_melt">Wax Melt</option>
        </select>
      </div>
      <div class="text-[11px] text-cyan-100/75">
        Governs downstream material math.
      </div>
    </div>
    @if($effectiveProductForm === 'room_spray')
      <div class="mt-2 text-xs text-cyan-50/90">
        Room spray context confirmed: this mapping will preserve room-spray form usage and avoid wax-based assumptions.
      </div>
    @endif
  </div>

  <div class="rounded-2xl border border-emerald-300/20 bg-emerald-950/35 p-4 space-y-3">
    <div>
      <label class="text-xs text-emerald-800">Search</label>
      <input
        type="text"
        wire:model.live.debounce.250ms="existingScentSearch"
        wire:keydown.enter.prevent="selectOnlyMatch"
        placeholder="Search"
        class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900"
      >
      <div class="mt-1 text-[11px] text-emerald-800">Type to narrow. Press Enter when only one match remains.</div>
    </div>

    @if($matchingScents->isNotEmpty())
      <div class="rounded-xl border border-zinc-200 bg-zinc-50 divide-y divide-emerald-200/10">
        @foreach($matchingScents as $candidate)
          <label class="flex cursor-pointer items-center gap-2 px-3 py-2 transition {{ (int) $selectedScentId === (int) $candidate['id'] ? 'bg-emerald-100' : 'hover:bg-zinc-100' }}">
            <input
              type="radio"
              name="selected-scent"
              value="{{ (int) $candidate['id'] }}"
              wire:model.live="selectedScentId"
              class="h-3.5 w-3.5 border-emerald-200/40 bg-zinc-50 text-emerald-400"
            >
            <div class="min-w-0 flex-1 flex items-center justify-between gap-2">
              <div class="truncate text-sm font-medium text-zinc-950">{{ $candidate['name'] }}</div>
              <div class="flex shrink-0 items-center gap-2">
                <span class="text-[10px] text-emerald-800">{{ $candidate['mapping_type'] }}</span>
                <span class="text-[10px] text-emerald-800">{{ $candidate['score'] }}%</span>
              </div>
            </div>
          </label>
        @endforeach
      </div>
    @else
      <div class="rounded-xl border border-zinc-300 bg-zinc-100 px-3 py-2 text-sm text-zinc-700">
        {{ $searchInput === '' ? 'Start typing to search scent options.' : 'No scent matches found for this search.' }}
      </div>
    @endif
  </div>

  <div x-data="{ open: @entangle('splitEnabled').live }" class="rounded-2xl border border-indigo-300/20 bg-indigo-500/10 p-4">
    <label class="flex items-center gap-2 text-sm text-indigo-50/90">
      <input type="checkbox" x-model="open" class="rounded border-indigo-300/35 bg-zinc-50">
      <span>Split this line into multiple scents</span>
    </label>
    <div class="mt-1 text-[11px] text-indigo-100/75">
      Edge case: keep imported line as commercial truth and add internal production scent allocations.
    </div>

    <div x-show="open" x-cloak class="mt-3 space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="text-[11px] text-indigo-100/75">
          Total split qty must equal line qty ({{ $lineQuantity > 0 ? $lineQuantity : '—' }}).
        </div>
        <div class="flex flex-wrap gap-2">
          <button type="button" wire:click="splitEvenly" class="rounded-full border border-indigo-300/35 bg-indigo-500/20 px-3 py-1 text-[11px] font-semibold text-indigo-50">
            Split evenly
          </button>
          <button type="button" wire:click="addSplitRow" class="rounded-full border border-indigo-300/35 bg-indigo-500/20 px-3 py-1 text-[11px] font-semibold text-indigo-50">
            Add row
          </button>
        </div>
      </div>

      @foreach($splitRows as $index => $row)
        <div class="grid gap-2 rounded-xl border border-indigo-200/20 bg-zinc-50 p-3 md:grid-cols-[1.8fr_90px_1fr_auto]">
          <div class="space-y-1">
            <div class="text-[11px] text-indigo-100/70">Scent</div>
            <livewire:components.scent-combobox
              :wire:key="'split-scent-'.$index.'-'.($modalKey ?? 'ctx')"
              wire:model.live="splitRows.{{ $index }}.scent_id"
              placeholder="Search scent for split..."
              :allowWholesaleCustom="true"
            />
          </div>
          <div class="space-y-1">
            <div class="text-[11px] text-indigo-100/70">Qty</div>
            <input type="number" min="1" step="1" wire:model.live="splitRows.{{ $index }}.quantity"
              class="w-full rounded-xl border border-indigo-200/20 bg-zinc-50 px-3 py-2 text-sm text-zinc-900">
          </div>
          <div class="space-y-1">
            <div class="text-[11px] text-indigo-100/70">Notes (optional)</div>
            <input type="text" wire:model.live="splitRows.{{ $index }}.notes" placeholder="Label note / allocation note"
              class="w-full rounded-xl border border-indigo-200/20 bg-zinc-50 px-3 py-2 text-sm text-zinc-900">
          </div>
          <div class="flex items-end">
            <button type="button" wire:click="removeSplitRow({{ $index }})"
              class="rounded-full border border-rose-300/40 bg-rose-100 px-3 py-1.5 text-[11px] font-semibold text-rose-900">
              Remove
            </button>
          </div>
        </div>
      @endforeach
    </div>
  </div>

  @if($sameCount > 0)
    <div class="rounded-2xl border border-amber-300/30 bg-amber-950/25 p-4">
      <label class="flex items-start gap-2 text-sm text-amber-800">
        <input type="checkbox" wire:model.live="applySameName" class="mt-1 rounded border-amber-300/35 bg-zinc-50">
        <span>
          Also map {{ $sameCount }} other unresolved {{ $sameCount === 1 ? 'item' : 'items' }} with this same incoming name
          @if($accountName !== '')
            for this account
          @endif
        </span>
      </label>

      @if(!empty($sameNameExceptionPreview))
        <div class="mt-3 rounded-xl border border-amber-200/20 bg-zinc-50 p-3">
          <div class="text-[11px] uppercase tracking-[0.24em] text-amber-800">Will Also Map</div>
          <div class="mt-2 space-y-1.5">
            @foreach($sameNameExceptionPreview as $row)
              <div class="text-xs text-amber-800">
                {{ $row['label'] }}
                @if($row['variant'] !== '')
                  · {{ $row['variant'] }}
                @endif
                @if($row['account_name'] !== '')
                  · {{ $row['account_name'] }}
                @endif
                @if($row['order_number'] !== '')
                  · #{{ ltrim((string) $row['order_number'], '#') }}
                @endif
              </div>
            @endforeach
            @if($sameCount > count($sameNameExceptionPreview))
              <div class="text-[11px] text-amber-800">+{{ $sameCount - count($sameNameExceptionPreview) }} more</div>
            @endif
          </div>
        </div>
      @endif
    </div>
  @endif

  <div class="flex flex-wrap justify-end gap-2">
    <button
      type="button"
      wire:click="markAsNonCandleItem"
      class="rounded-full border border-sky-300/45 bg-sky-100 px-5 py-2 text-sm font-semibold text-sky-900 hover:bg-sky-500/30"
    >
      Revenue Only · No Material Usage
    </button>
    <a
      href="{{ $wizardUrl }}"
      wire:navigate
      class="rounded-full border border-amber-300/45 bg-amber-100 px-5 py-2 text-sm font-semibold text-amber-900 hover:bg-amber-500/30"
    >
      Launch New Scent Wizard
    </a>
    <button
      type="button"
      wire:click="save"
      class="rounded-full border border-emerald-300/50 bg-emerald-500/30 px-5 py-2 text-sm font-semibold text-zinc-950 shadow-[0_10px_28px_-12px_rgba(16,185,129,.55)] hover:bg-emerald-500/40"
    >
      <span x-data="{ split: @entangle('splitEnabled').live }" x-text="split ? 'Save Scent Split' : 'Map Selected Scent'"></span>
    </button>
  </div>

  <div class="text-[11px] text-emerald-800 text-right">
    Use revenue-only when the order made money but does not consume candle/room-spray/wax-melt materials.
  </div>
</div>
