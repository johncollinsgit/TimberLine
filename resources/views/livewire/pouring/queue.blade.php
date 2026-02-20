@php
  $views = [
    'upcoming' => 'Upcoming',
    'retail' => 'Retail',
    'market' => 'Market',
    'wholesale' => 'Wholesale',
    'ship_date' => 'By Ship Date',
    'scent' => 'By Scent',
  ];
  $statusLabels = [
    'submitted_to_pouring' => 'Submitted',
    'pouring' => 'Pouring',
    'brought_down' => 'Brought Down',
    'verified' => 'Verified',
  ];
@endphp

<div class="space-y-6">
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)]">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
      <div>
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Pouring Room</div>
        <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Production Queue</div>
          <div class="mt-2 text-sm text-emerald-50/70">{{ $reminder }}</div>
      </div>

      <div class="flex flex-wrap gap-2">
        @foreach($views as $key => $label)
          <button type="button" wire:click="$set('viewMode','{{ $key }}')"
            class="px-3 py-1.5 rounded-full text-xs border transition
              {{ $viewMode === $key ? 'border-emerald-300/35 bg-emerald-400/25 text-emerald-50' : 'border-emerald-400/15 bg-emerald-500/5 text-white/80 hover:bg-emerald-500/10 hover:border-emerald-300/25' }}">
            {{ $label }}
          </button>
        @endforeach
      </div>
    </div>
  </section>

  <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
    <div class="xl:col-span-8 space-y-4">
      @if($viewMode === 'scent')
        <div class="rounded-2xl border border-emerald-200/10 bg-[#101513]/80 p-4">
          <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60">Bulk by Scent</div>
          <div class="mt-3 space-y-3">
            @foreach($byScent as $scentId => $lines)
              @php
                $scentName = $lines->first()?->scent?->display_name ?? $lines->first()?->scent?->name ?? 'Unknown';
                $qty = $lines->sum(fn ($l) => (int)(($l->ordered_qty ?? $l->quantity) + ($l->extra_qty ?? 0)));
              @endphp
              <div class="flex items-center justify-between rounded-2xl border border-emerald-200/10 bg-emerald-500/5 p-4">
                <div>
                  <div class="text-sm font-semibold text-white/90">{{ $scentName }}</div>
                  <div class="text-xs text-emerald-100/60">{{ $qty }} units</div>
                </div>
                <button type="button" wire:click="toggleScent({{ $scentId }})"
                  class="px-3 py-1.5 rounded-xl text-xs border border-emerald-400/20 bg-emerald-500/10 text-white/80">
                  {{ !empty($selectedScents[$scentId] ?? false) ? 'Selected' : 'Select' }}
                </button>
              </div>
            @endforeach
          </div>
        </div>
      @else
        @forelse($orders as $order)
          @php
            $type = $order->order_type ?? 'retail';
            $label = $order->display_name ?? $order->order_label ?? $order->order_number ?? 'Order';
            $dueAt = $order->due_at ? \Carbon\CarbonImmutable::parse($order->due_at) : null;
            $urgencyStyle = $this->urgencyStyle($dueAt, $type);
            $typeStyle = $this->typeBadgeStyle($type);
            $typeLabel = ucfirst($this->typeLabel($type));
            $lines = $order->lines ?? collect();
            $status = $order->status ?? 'submitted_to_pouring';
            $statusLabel = $statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status));
          @endphp
          <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5">
            <div class="flex items-center justify-between gap-4">
              <div>
                <div class="flex items-center gap-2">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] border text-emerald-50/90" style="{{ $typeStyle }}">{{ $typeLabel }}</span>
                  <div class="text-white/90 font-semibold">{{ $label }}</div>
                </div>
                <div class="mt-1 text-xs text-emerald-100/60">
                  Order #{{ $order->order_number ?? '—' }}
                  <span class="mx-1 text-emerald-100/40">•</span>
                  <span class="text-emerald-50/70">{{ $statusLabel }}</span>
                  @if(($order->open_mapping_exceptions_count ?? 0) > 0)
                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] border border-amber-300/35 bg-amber-400/20 text-amber-50">
                      Blocked: needs mapping
                    </span>
                  @endif
                </div>
              </div>
              <div class="flex items-center gap-3">
                <div class="px-3 py-1 rounded-full text-xs border text-white/85" style="{{ $urgencyStyle }}">Bring Down: {{ optional($order->due_at)->toDateString() ?? '—' }}</div>
                <button type="button" wire:click="toggleOrder({{ $order->id }})"
                  class="px-3 py-1.5 rounded-xl text-xs border border-emerald-400/20 bg-emerald-500/10 text-white/80">
                  {{ !empty($selectedOrders[$order->id] ?? false) ? 'Selected' : 'Select Order' }}
                </button>
              </div>
            </div>

            <div class="mt-3 flex flex-wrap gap-2 text-xs text-emerald-100/60">
              <span class="px-2.5 py-1 rounded-full border border-emerald-200/10 bg-emerald-500/5">Ship By: {{ optional($order->ship_by_at)->toDateString() ?? '—' }}</span>
              <span class="px-2.5 py-1 rounded-full border border-emerald-200/10 bg-emerald-500/5">Lines: {{ $lines->count() }}</span>
              <span class="px-2.5 py-1 rounded-full border border-emerald-200/10 bg-emerald-500/5">Units: {{ $lines->sum(fn ($l) => (int)(($l->ordered_qty ?? $l->quantity) + ($l->extra_qty ?? 0))) }}</span>
            </div>

            <div class="mt-4 space-y-2">
              @foreach($lines as $line)
                @php
                  $name = $line->scent?->display_name
                    ?? $line->scent?->name
                    ?? $line->raw_title
                    ?? 'Item';
                  $size = $line->size?->label
                    ?? $line->size?->code
                    ?? $line->raw_variant
                    ?? $line->size_code
                    ?? '';
                  $qty = (int)(($line->ordered_qty ?? $line->quantity) + ($line->extra_qty ?? 0));
                  $wick = $line->wick_type ?? null;
                  $isMapped = !empty($line->scent_id) && !empty($line->size_id);
                  $blend = $line->scent?->oilBlend;
                @endphp
                <div class="flex items-center justify-between rounded-2xl border border-emerald-200/10 bg-emerald-500/5 px-4 py-2">
                  <div class="text-sm text-white/85">
                    {{ $name }} <span class="text-emerald-100/60">· {{ $size }}</span>
                    @if($wick)
                      <span class="text-emerald-100/60">· {{ ucfirst($wick) }} wick</span>
                    @endif
                    <span class="text-emerald-100/60">· ×{{ $qty }}</span>
                    @unless($isMapped)
                      <span class="ml-2 text-[11px] uppercase tracking-[0.2em] text-amber-200/80">Unmapped</span>
                    @endunless
                    @if($blend && ($order->order_type ?? null) === 'wholesale')
                      <div class="mt-1 text-[11px] text-emerald-100/70">
                        Recipe: {{ $blend->name }} ·
                        @foreach($blend->components as $component)
                          <span class="inline-flex items-center px-2 py-0.5 rounded-full border border-emerald-300/20 bg-emerald-500/10 text-emerald-50/80 mr-1 mb-1">
                            {{ $component->baseOil?->name ?? 'Oil' }} ({{ $component->ratio_weight }})
                          </span>
                        @endforeach
                      </div>
                    @endif
                  </div>
                  <div class="flex items-center gap-2">
                    @if($isMapped)
                      <button type="button" wire:click="toggleLine({{ $line->id }})"
                        class="px-2 py-1 rounded-lg text-xs border border-emerald-400/20 bg-emerald-500/10 text-white/80">
                        {{ !empty($selectedLines[$line->id] ?? false) ? 'Selected' : 'Select' }}
                      </button>
                    @endif
                    <button type="button" wire:click="oops({{ $line->id }})"
                      class="px-2 py-1 rounded-lg text-xs border border-red-400/20 bg-red-500/10 text-red-100">
                      Oops
                    </button>
                  </div>
                </div>
              @endforeach
            </div>
          </div>
        @empty
          <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 text-sm text-emerald-50/70">No orders ready for pouring.</div>
        @endforelse
      @endif
    </div>

    <div class="xl:col-span-4 space-y-4">
      <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5">
        <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60">Batch Preview</div>
        <div class="mt-4 space-y-3">
          <div class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 p-3 text-xs text-emerald-50/80">
            <div>Selected lines: {{ count(array_filter($selectedLines ?? [])) }}</div>
            <div>Selected orders: {{ count(array_filter($selectedOrders ?? [])) }}</div>
            <div>Selected scents: {{ count(array_filter($selectedScents ?? [])) }}</div>
          </div>

          <input type="text" wire:model.defer="batchName"
            class="w-full rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90"
            placeholder="Batch name" />

          <button type="button" wire:click="clearSelections" wire:loading.attr="disabled"
            class="w-full rounded-xl border border-emerald-400/15 bg-emerald-500/5 px-3 py-2 text-sm text-white/70">
            Clear Selection
          </button>

          <button type="button" wire:click="previewBatch"
            class="w-full rounded-xl border border-emerald-400/25 bg-emerald-500/15 px-3 py-2 text-sm text-white/90">
            Calculate Batch
          </button>

            @if(!empty($batchPreview))
              <div class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 p-4 text-sm text-white/80">
                <div>Total Wax: {{ $batchPreview['totals']['wax_grams'] ?? 0 }} g</div>
                <div>Total Oil: {{ $batchPreview['totals']['oil_grams'] ?? 0 }} g</div>
                @if(($batchPreview['totals']['alcohol_grams'] ?? 0) > 0)
                  <div>Total Alcohol: {{ $batchPreview['totals']['alcohol_grams'] ?? 0 }} g</div>
                @endif
                @if(($batchPreview['totals']['water_grams'] ?? 0) > 0)
                  <div>Total Water: {{ $batchPreview['totals']['water_grams'] ?? 0 }} g</div>
                @endif
                <div>Total: {{ $batchPreview['totals']['total_grams'] ?? 0 }} g</div>
                <div>Pitchers: {{ count($batchPreview['pitchers'] ?? []) }}</div>
              </div>

            <div class="space-y-2">
              @foreach($batchPreview['pitchers'] ?? [] as $pitcher)
                <div class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-xs text-emerald-50/80">
                  Pitcher {{ $pitcher['pitcher_index'] }}: {{ $pitcher['total_grams'] }} g
                </div>
              @endforeach
            </div>
          @endif

          <button type="button" wire:click="startBatch"
            class="w-full rounded-xl border border-emerald-400/25 bg-emerald-500/20 px-3 py-2 text-sm font-semibold text-white">
            Start Pour Batch
          </button>
        </div>
      </div>
    </div>
  </div>
</div>
