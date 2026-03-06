@php
  $stackHref = $returnTo ?: route('pouring.stack', $order->channel ?? $order->order_type ?? 'retail');
@endphp

<div class="space-y-6">
  <div class="flex flex-wrap items-center gap-2 text-[11px] text-emerald-100/70">
    <a href="{{ route('pouring.index') }}" class="rounded-full border border-emerald-200/15 bg-emerald-500/10 px-3 py-1 hover:bg-emerald-500/20">Pouring Room</a>
    <a href="{{ $stackHref }}" class="rounded-full border border-emerald-200/15 bg-emerald-500/10 px-3 py-1 hover:bg-emerald-500/20">Stack</a>
    <span class="rounded-full border border-emerald-200/15 bg-emerald-500/20 px-3 py-1 text-white/85">{{ $order->order_number ?? 'Order' }}</span>
  </div>
  <livewire:pouring-room.dashboard-bar />

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Order</div>
    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">{{ $order->display_name }}</div>
    <div class="mt-2 text-sm text-emerald-50/70">
      {{ $order->order_number ?? '—' }} · {{ ucfirst($order->channel) }} · Due {{ optional($order->due_at)->format('M j, Y') ?? '—' }}
      @if($order->ship_by_at)
        · Ship {{ $order->ship_by_at->format('M j, Y') }}
      @endif
    </div>
    <div class="mt-4 flex flex-wrap items-center gap-2">
      <button
        type="button"
        wire:click="start"
        @disabled(($order->status ?? '') === 'brought_down')
        class="rounded-full border border-emerald-400/25 bg-emerald-500/15 px-4 py-2 text-xs text-white/90 disabled:cursor-not-allowed disabled:opacity-45"
      >
        Start this order
      </button>
      <button
        type="button"
        wire:click="complete"
        @disabled(!$canComplete)
        class="rounded-full border border-emerald-400/25 bg-emerald-500/20 px-4 py-2 text-xs text-white/90 disabled:cursor-not-allowed disabled:opacity-45"
      >
        Mark complete
      </button>
      <button
        type="button"
        wire:click="toggleCompleted"
        class="rounded-full border border-emerald-400/30 bg-emerald-500/10 px-4 py-2 text-xs text-white/90"
      >
        {{ $showCompleted ? 'Hide Completed' : 'See Completed' }} ({{ $completedCount }})
      </button>
    </div>
    @if($completeBlockedReason)
      <div class="mt-3 rounded-xl border border-amber-300/20 bg-amber-500/10 px-3 py-2 text-xs text-amber-100">
        {{ $completeBlockedReason }}
      </div>
    @endif
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60">Scent Summary</div>
        <div class="mt-1 text-sm text-emerald-50/75">
          One row per scent. Expand for size-level recipe details.
        </div>
      </div>
      <div class="text-xs text-emerald-100/65">
        {{ $allScentCount }} total scent{{ $allScentCount === 1 ? '' : 's' }}
      </div>
    </div>

    @if($scentRows->isEmpty())
      <div class="mt-4 rounded-2xl border border-emerald-200/10 bg-black/20 px-4 py-5 text-sm text-emerald-50/70">
        No scent lines found for this order.
      </div>
    @else
      <div class="mt-4 rounded-2xl border border-emerald-200/10 bg-black/15">
        <div class="border-b border-emerald-200/10">
          <div class="grid grid-cols-[180px_minmax(0,1.45fr)_minmax(0,1.25fr)_116px_116px_minmax(0,1.35fr)] items-center gap-2 px-2 py-2 text-[10px] uppercase tracking-[0.16em] text-emerald-100/55">
            <div>Status</div>
            <div>Scent</div>
            <div>Size Breakdown</div>
            <div class="text-right">Wax</div>
            <div class="text-right">Oil</div>
            <div class="pl-2">Oil Name</div>
          </div>
        </div>

        <div class="divide-y divide-emerald-200/10">
          @foreach($scentRows as $row)
            @php
              $scentKey = (string)($row['key'] ?? '');
              $isExpanded = (bool)($expandedScents[$scentKey] ?? false);
              $statusValue = (string)($scentStatuses[$scentKey] ?? ($row['status'] ?? 'queued'));
              $persistedStatus = (string)($persistedScentStatuses[$scentKey] ?? ($row['status'] ?? 'queued'));
              $statusDirty = $statusValue !== $persistedStatus;
            @endphp

            <div class="bg-transparent">
              <div
                class="grid grid-cols-[180px_minmax(0,1.45fr)_minmax(0,1.25fr)_116px_116px_minmax(0,1.35fr)] items-center gap-2 px-2 py-2 transition hover:bg-white/5"
                wire:click="toggleScent('{{ $scentKey }}')"
                role="button"
                tabindex="0"
                onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); $wire.toggleScent('{{ $scentKey }}'); }"
              >
                <div wire:click.stop>
                  <select
                    wire:model.live="scentStatuses.{{ $scentKey }}"
                    class="h-8 w-full rounded-xl border border-emerald-200/20 bg-black/35 px-2 text-[11px] text-white/90"
                  >
                    @if(($row['status'] ?? '') === 'mixed' && $statusValue === 'mixed')
                      <option value="mixed">Mixed (multiple)</option>
                    @endif
                    @foreach($statusOptions as $statusCode => $statusLabel)
                      <option value="{{ $statusCode }}">{{ $statusLabel }}</option>
                    @endforeach
                  </select>
                </div>

                <div class="min-w-0">
                  <div class="truncate text-sm font-semibold text-white">{{ $row['scent_label'] ?? 'Unknown scent' }}</div>
                  @if($statusDirty)
                    <div class="text-[10px] text-amber-200">Unsaved change</div>
                  @endif
                </div>

                <div class="truncate text-xs text-white/85">
                  @foreach(($row['size_breakdown'] ?? []) as $index => $sizePart)
                    <span>
                      {{ (int)($sizePart['qty'] ?? 0) }}x{{ $sizePart['label'] ?? 'Size' }}@if($index < count($row['size_breakdown'] ?? []) - 1) · @endif
                    </span>
                  @endforeach
                </div>

                <div class="text-right text-sm font-semibold text-white/95">{{ rtrim(rtrim(number_format((float)($row['wax_grams'] ?? 0), 1), '0'), '.') }}g</div>
                <div class="text-right text-sm font-semibold text-white/95">{{ rtrim(rtrim(number_format((float)($row['oil_grams'] ?? 0), 1), '0'), '.') }}g</div>
                <div class="pl-2 truncate text-sm text-emerald-50/90">{{ $row['oil_name'] ?? '—' }}</div>
              </div>

              @if($isExpanded)
                <div class="border-t border-emerald-200/10 bg-black/20 px-3 py-3">
                  @if($row['missing_recipe'] ?? false)
                    <div class="mb-3 rounded-xl border border-amber-300/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-100">
                      Recipe or formulation is missing for one or more size variants of this scent.
                      <a href="{{ route('admin.oils.blends') }}" class="underline">Open Recipes Admin</a>
                    </div>
                  @endif

                  <div class="rounded-xl border border-emerald-200/10">
                      <div class="grid grid-cols-[minmax(0,2fr)_68px_minmax(0,1.4fr)_110px_110px_120px] gap-1 border-b border-emerald-200/10 bg-black/30 px-2 py-2 text-[10px] uppercase tracking-[0.16em] text-emerald-100/55">
                        <div>Size</div>
                        <div class="text-right">Qty</div>
                        <div>Recipe</div>
                        <div class="text-right">Wax</div>
                        <div class="text-right">Oil</div>
                        <div>Status</div>
                      </div>
                      <div class="divide-y divide-emerald-200/10">
                        @foreach(($row['details'] ?? []) as $detail)
                          @php
                            $detailStatus = (string)($detail['status'] ?? 'queued');
                          @endphp
                          <div class="grid grid-cols-[minmax(0,2fr)_68px_minmax(0,1.4fr)_110px_110px_120px] gap-1 px-2 py-2 text-xs text-white/85">
                            <div class="truncate">
                              {{ $detail['size_label'] ?? 'Unknown size' }}
                              @if(!empty($detail['wick'])) · {{ ucfirst((string)$detail['wick']) }} wick @endif
                            </div>
                            <div class="text-right font-semibold text-white">{{ (int)($detail['qty'] ?? 0) }}</div>
                            <div class="truncate text-emerald-50/80">{{ $detail['recipe_name'] ?: '—' }}</div>
                            <div class="text-right">{{ rtrim(rtrim(number_format((float)($detail['wax_grams'] ?? 0), 1), '0'), '.') }}g</div>
                            <div class="text-right">{{ rtrim(rtrim(number_format((float)($detail['oil_grams'] ?? 0), 1), '0'), '.') }}g</div>
                            <div>
                              {{ $detailStatus === 'mixed' ? 'Mixed' : ($statusOptions[$detailStatus] ?? ucfirst(str_replace('_', ' ', $detailStatus))) }}
                            </div>
                          </div>
                        @endforeach
                      </div>
                  </div>

                  @if(!empty($row['recipe_components']))
                    <div class="mt-3 overflow-x-auto">
                      <div class="flex min-w-max items-center gap-2 whitespace-nowrap text-[11px] text-emerald-100/80">
                        <span class="uppercase tracking-[0.2em] text-emerald-100/55">Blend:</span>
                        @foreach(($row['recipe_components'] ?? []) as $component)
                          <span class="rounded-full border border-emerald-200/15 bg-emerald-500/10 px-2.5 py-1">
                            {{ $component['oil'] ?? 'Oil' }} ({{ $component['ratio'] ?? '—' }})
                          </span>
                        @endforeach
                      </div>
                    </div>
                  @endif
                </div>
              @endif
            </div>
          @endforeach
        </div>
      </div>

      <div class="mt-4 flex items-center justify-end gap-3">
        @if($pendingStatusChanges > 0)
          <div class="text-xs text-amber-100">{{ $pendingStatusChanges }} scent status change{{ $pendingStatusChanges === 1 ? '' : 's' }} pending</div>
        @endif
        <button
          type="button"
          wire:click="saveScentStatuses"
          wire:loading.attr="disabled"
          wire:target="saveScentStatuses"
          @disabled($pendingStatusChanges <= 0)
          class="rounded-xl border border-emerald-300/30 bg-emerald-500/20 px-5 py-2.5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-45"
        >
          Save Scent Statuses
        </button>
      </div>
    @endif
  </section>
</div>
