<div class="space-y-4 sm:space-y-6 min-w-0">
  <section class="rounded-3xl border border-zinc-200 bg-white p-4 sm:p-6 shadow-sm min-w-0">
    <div class="flex min-w-0 flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
      <div class="min-w-0">
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">All Pour Lists</div>
        <div class="mt-2 max-w-full sm:max-w-[32rem] text-2xl sm:text-3xl font-['Fraunces'] font-semibold text-zinc-950 truncate" title="{{ $plan->name }}">{{ $plan->name }}</div>
        <div class="mt-2 text-sm text-zinc-600 break-words">{{ $queueMeta['subtitle'] ?? 'Draft list for today. Publish to push to the pouring room.' }}</div>
        <div class="mt-2 text-xs text-emerald-800 italic">“{{ $quote }}”</div>
      </div>
      @php($wholesaleNeedsSelection = ($queueMeta['key'] ?? '') === 'wholesale' && empty($selectedWholesaleOrderId))
      <div class="flex flex-wrap gap-2">
        @if(($queueMeta['key'] ?? '') !== 'markets')
          <button type="button" wire:click="prefillFromOrders"
            @if($wholesaleNeedsSelection) disabled @endif
            class="px-4 py-2 rounded-full text-xs border border-emerald-400/25 bg-emerald-100 text-zinc-800 hover:bg-emerald-100 transition disabled:cursor-not-allowed disabled:opacity-40">
            {{ $queueMeta['prefill_label'] ?? 'Prefill from Retail Orders' }}
          </button>
          <button type="button" wire:click="clearScents"
            class="px-4 py-2 rounded-full text-xs border border-emerald-400/25 bg-emerald-100 text-zinc-800 hover:bg-emerald-100 transition">
            Clear Scents
          </button>
          <button type="button" wire:click="publishPlan"
            @if($wholesaleNeedsSelection) disabled @endif
            class="px-4 py-2 rounded-full text-xs border border-emerald-400/40 bg-emerald-500/25 text-zinc-950 font-semibold hover:bg-emerald-500/30 transition disabled:cursor-not-allowed disabled:opacity-40">
            Publish to Pouring
          </button>
        @endif
      </div>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-3 md:grid-cols-3" role="navigation" aria-label="Pour list queues">
      @foreach (['retail' => ['label' => 'Retail', 'desc' => 'Shopify retail queue'], 'wholesale' => ['label' => 'Wholesale', 'desc' => 'Wholesale order queue'], 'markets' => ['label' => 'Markets', 'desc' => 'Market/event planning queue']] as $tabKey => $tabMeta)
        @php($isActiveQueue = ($queueMeta['key'] ?? 'retail') === $tabKey)
        <a
          href="{{ route('retail.plan', ['queue' => $tabKey]) }}"
          class="group block rounded-2xl border p-4 sm:p-5 transition min-w-0 {{ $isActiveQueue ? 'border-zinc-300 bg-emerald-100 shadow-[0_18px_40px_-30px_rgba(16,185,129,.35)]' : 'border-zinc-200 bg-emerald-50 hover:bg-emerald-100 hover:border-emerald-300/20' }}"
        >
          <div class="flex h-full min-h-[5.5rem] flex-col justify-between gap-3">
            <div class="min-w-0">
              <div class="text-base sm:text-lg font-semibold text-zinc-950 leading-tight">{{ $tabMeta['label'] }}</div>
              <div class="mt-1 text-xs sm:text-sm text-zinc-600">{{ $tabMeta['desc'] }}</div>
            </div>
            <div class="inline-flex items-center gap-1 text-xs font-semibold {{ $isActiveQueue ? 'text-emerald-900' : 'text-emerald-800 group-hover:text-emerald-900' }}">
              {{ $isActiveQueue ? 'Current Queue' : 'Open Queue' }} <span aria-hidden="true">→</span>
            </div>
          </div>
        </a>
      @endforeach
    </div>
  </section>

  @if(($queueMeta['key'] ?? '') === 'markets')
    <section class="min-w-0">
      <div class="min-w-0">
        @livewire(
          \App\Livewire\Retail\Markets\MarketsPlanner::class,
          [
            'planId' => $plan->id,
          ],
          key('markets-planner-'.(int)$plan->id)
        )
      </div>
    </section>
  @endif

  <div class="grid grid-cols-1 gap-4 sm:gap-6 min-w-0">
    @if(($queueMeta['key'] ?? '') === 'wholesale')
    <section class="rounded-3xl border border-zinc-200 bg-white p-4 sm:p-5 min-w-0">
      <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div class="text-xs uppercase tracking-[0.3em] text-emerald-800">Select Wholesale Order</div>
        <div class="text-xs text-emerald-800">{{ $wholesaleOrders->count() }} open orders</div>
      </div>

      @if($wholesaleOrders->isEmpty())
        <div class="mt-3 rounded-2xl border border-zinc-200 bg-emerald-50 p-4 text-sm text-zinc-600">
          No open wholesale orders found.
        </div>
      @else
        <div class="mt-3 grid grid-cols-1 gap-2 lg:grid-cols-2">
          @foreach($wholesaleOrders as $orderRow)
            <button
              type="button"
              wire:click="selectWholesaleOrder({{ (int) $orderRow['id'] }})"
              class="rounded-2xl border px-4 py-3 text-left transition {{ ((int) ($selectedWholesaleOrderId ?? 0) === (int) $orderRow['id']) ? 'border-zinc-300 bg-emerald-100' : 'border-zinc-200 bg-emerald-50 hover:border-emerald-300/20 hover:bg-emerald-100' }}"
            >
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="truncate text-sm font-semibold text-zinc-950" title="{{ $orderRow['display_name'] }}">{{ $orderRow['display_name'] }}</div>
                  <div class="mt-1 text-xs text-emerald-800">
                    #{{ ltrim((string) $orderRow['order_number'], '#') }} · {{ ucfirst($orderRow['status']) }}
                    @if($orderRow['due_at'])
                      · Due {{ \Illuminate\Support\Carbon::parse($orderRow['due_at'])->format('M j') }}
                    @endif
                  </div>
                </div>
                <div class="text-right text-xs text-emerald-800">
                  <div>{{ (int) $orderRow['lines_count'] }} lines</div>
                  <div>{{ (int) $orderRow['units_total'] }} units</div>
                </div>
              </div>
            </button>
          @endforeach
        </div>
      @endif

      @if($selectedWholesaleOrder)
        <div class="mt-4 rounded-2xl border border-emerald-300/20 bg-emerald-100 p-4">
          <div class="text-[11px] uppercase tracking-[0.28em] text-emerald-800">Selected Order Overview</div>
          <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-zinc-900">
            <span class="font-semibold">{{ $selectedWholesaleOrder['display_name'] }}</span>
            <span class="text-emerald-800">#{{ ltrim((string) $selectedWholesaleOrder['order_number'], '#') }}</span>
            <span class="text-emerald-800">{{ ucfirst($selectedWholesaleOrder['status']) }}</span>
            @if($selectedWholesaleOrder['due_at'])
              <span class="text-emerald-800">Due {{ \Illuminate\Support\Carbon::parse($selectedWholesaleOrder['due_at'])->format('M j, Y') }}</span>
            @endif
          </div>
          <div class="mt-2 text-xs text-emerald-800">
            {{ (int) $selectedWholesaleOrder['lines_count'] }} line items · {{ (int) $selectedWholesaleOrder['units_total'] }} total units
          </div>
        </div>
      @endif
    </section>
    @endif

    @if(($queueMeta['key'] ?? '') !== 'markets')
    <section class="rounded-3xl border border-zinc-200 bg-white p-4 sm:p-5 h-full min-w-0">
      <div class="text-xs uppercase tracking-[0.3em] text-emerald-800">Add Additional Scents to the list</div>
      <div class="mt-3 min-w-0">
        <div class="grid grid-cols-1 gap-2 md:grid-cols-12 md:items-end min-w-0">
          <div class="{{ ($queueMeta['key'] ?? '') === 'markets' ? 'md:col-span-7' : 'md:col-span-5' }}">
            <label class="text-xs text-emerald-800">Scent</label>
            @livewire(
              \App\Livewire\Components\ScentCombobox::class,
              [
                'emitKey' => 'retail-plan',
                'selectedId' => (int)($inventoryScentId ?? 0),
                'allowWholesaleCustom' => false,
              ],
              key('retail-plan-scent')
            )
          </div>
          @if(($queueMeta['key'] ?? '') === 'markets')
            <div class="md:col-span-5">
              <label class="text-xs text-emerald-800">Add Market Box</label>
              <div class="mt-1 grid grid-cols-1 gap-2 sm:grid-cols-3">
                <button type="button" wire:click="addMarketHalfBox"
                  class="w-full rounded-xl border border-emerald-400/25 bg-emerald-100 px-3 py-2 text-sm text-zinc-900 hover:bg-emerald-100">
                  Add Half Box
                </button>
                <button type="button" wire:click="addMarketFullBox"
                  class="w-full rounded-xl border border-emerald-400/35 bg-emerald-100 px-3 py-2 text-sm font-semibold text-zinc-950 hover:bg-emerald-500/25">
                  Add Full Box
                </button>
                <button type="button" wire:click="addTopShelfTemplate"
                  class="w-full rounded-xl border border-amber-300/25 bg-amber-100 px-3 py-2 text-sm text-amber-900 hover:bg-amber-100">
                  Add Top Shelf
                </button>
              </div>
              <div class="mt-2 text-[11px] text-emerald-800">
                One event at a time. Select an event first, then add/edit boxes only for that event.
              </div>
            </div>
          @else
            <div class="md:col-span-4">
              <label class="text-xs text-emerald-800">Size</label>
              <input type="text"
                list="retail-size-list"
                wire:model.live.debounce.200ms="inventorySizeSearch"
                wire:blur="selectInventorySize"
                wire:change="selectInventorySize"
                class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900"
                placeholder="Start typing a size..." />
              <datalist id="retail-size-list">
                @foreach($sizes as $size)
                  <option value="{{ $size->label ?? $size->code }}"></option>
                @endforeach
              </datalist>
            </div>
            <div class="md:col-span-1">
              <label class="text-xs text-emerald-800">Quantity</label>
              <input type="number" min="1" wire:model="inventoryQty"
                class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900" />
            </div>
            <button type="button" wire:click="addInventoryItem"
              class="md:col-span-2 w-full rounded-xl border border-emerald-400/25 bg-emerald-100 px-3 py-2 text-sm text-zinc-900">
              {{ $queueMeta['add_button_label'] ?? 'Add to Retail/Pour List' }}
            </button>
          @endif
        </div>
      </div>
    </section>
    @endif

    @if(($queueMeta['key'] ?? '') !== 'markets')
    <section class="rounded-3xl border border-zinc-200 bg-white p-4 sm:p-5 min-w-0">
      <div class="text-xs uppercase tracking-[0.3em] text-emerald-800">Candles to be poured</div>
        <div class="mt-4 space-y-2">
          @if($items->isEmpty())
            <div class="rounded-2xl border border-zinc-200 bg-emerald-50 p-4 text-sm text-zinc-600">
              {{ $queueMeta['empty_label'] ?? 'No items yet. Prefill from retail orders or add inventory below.' }}
            </div>
          @else
            @foreach($items as $item)
              <div class="flex flex-col gap-2 rounded-2xl border border-zinc-200 bg-emerald-50 px-4 py-3 md:flex-row md:items-center md:justify-between min-w-0">
                <div class="min-w-0">
                  <div class="text-sm text-zinc-900">
                    {{ $item->scent?->display_name ?? $item->scent?->name ?? 'Unknown' }}
                    <span class="text-emerald-800">· {{ $item->size?->label ?? $item->size?->code ?? '—' }}</span>
                  </div>
                  <div class="text-xs text-emerald-800">
                    {{ ($item->source ?? '') === 'inventory' ? 'Inventory' : 'Order' }}
                    @if(($item->status ?? 'draft') === 'needs_mapping')
                      <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] border border-amber-300/35 bg-amber-100 text-amber-900">
                        Needs mapping
                      </span>
                    @endif
                  </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                  <div class="flex items-center rounded-full border border-zinc-200 bg-zinc-50 px-2 py-1 shrink-0">
                    <button type="button" wire:click="decrementItemQuantity({{ $item->id }})"
                      class="h-6 w-6 rounded-full border border-emerald-300/20 bg-emerald-100 text-emerald-900 hover:bg-emerald-100 transition">
                      −
                    </button>
                    <input type="number" min="1" value="{{ $item->quantity }}"
                      wire:change="updateItemQuantity({{ $item->id }}, $event.target.value)"
                      class="w-12 bg-transparent text-center text-xs text-zinc-900 focus:outline-none appearance-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none" />
                    <button type="button" wire:click="incrementItemQuantity({{ $item->id }})"
                      class="h-6 w-6 rounded-full border border-emerald-300/20 bg-emerald-100 text-emerald-900 hover:bg-emerald-100 transition">
                      +
                    </button>
                  </div>
                  <div class="flex items-center rounded-full border border-zinc-200 bg-zinc-50 px-2 py-1 shrink-0">
                    <span class="text-[10px] text-emerald-800 mr-2">Additional for inventory</span>
                    <button type="button" wire:click="decrementItemInventoryQuantity({{ $item->id }})"
                      class="h-6 w-6 rounded-full border border-emerald-300/15 bg-emerald-50 text-emerald-900 hover:bg-emerald-100 transition">
                      −
                    </button>
                    <input type="number" min="0" value="{{ $item->inventory_quantity ?? 0 }}"
                      wire:change="updateItemInventoryQuantity({{ $item->id }}, $event.target.value)"
                      class="w-12 bg-transparent text-center text-xs text-zinc-900 focus:outline-none appearance-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none" />
                    <button type="button" wire:click="incrementItemInventoryQuantity({{ $item->id }})"
                      class="h-6 w-6 rounded-full border border-emerald-300/15 bg-emerald-50 text-emerald-900 hover:bg-emerald-100 transition">
                      +
                    </button>
                  </div>
                  <button type="button" wire:click="removeItem({{ $item->id }})"
                    class="px-2.5 py-1 rounded-full text-xs border border-red-400/20 bg-red-500/10 text-red-100 hover:bg-red-500/20 transition">
                    Remove
                  </button>
                </div>
              </div>
            @endforeach
          @endif
        </div>
        <div class="mt-4">
          <button type="button" wire:click="publishPlan"
            @if($wholesaleNeedsSelection) disabled @endif
            class="w-full rounded-xl border border-emerald-400/40 bg-emerald-500/25 px-3 py-2 text-sm font-semibold text-zinc-950 hover:bg-emerald-500/30 transition disabled:cursor-not-allowed disabled:opacity-40">
            Publish to Pouring room
          </button>
        </div>
    </section>
    @endif
  </div>
</div>

<script>
  (function () {
    let lastCelebrationPayload = null;

    function payloadFromDetail(detail) {
      if (detail && typeof detail === 'object' && detail[0] && typeof detail[0] === 'object') {
        return detail[0];
      }
      return detail || {};
    }

    function createFireworks(container, count) {
      for (let i = 0; i < count; i++) {
        const p = document.createElement('span');
        p.style.position = 'absolute';
        p.style.left = `${10 + Math.random() * 80}%`;
        p.style.top = `${10 + Math.random() * 65}%`;
        p.style.width = '8px';
        p.style.height = '8px';
        p.style.borderRadius = '999px';
        p.style.background = `hsl(${Math.floor(Math.random() * 360)} 100% 60%)`;
        p.style.boxShadow = '0 0 20px rgba(255,255,255,0.6)';
        p.style.opacity = '0';
        const tx = -160 + Math.random() * 320;
        const ty = -140 + Math.random() * 280;
        const delay = Math.random() * 4600;
        p.animate(
          [
            { transform: 'translate(0,0) scale(0.2)', opacity: 0 },
            { opacity: 1, offset: 0.15 },
            { transform: `translate(${tx}px, ${ty}px) scale(1.2)`, opacity: 0 },
          ],
          { duration: 2200, delay, easing: 'ease-out', fill: 'forwards' }
        );
        container.appendChild(p);
      }
    }

    function ensureReplayButton() {
      let btn = document.getElementById('mf-retail-celebration-replay');
      if (btn) return btn;

      btn = document.createElement('button');
      btn.id = 'mf-retail-celebration-replay';
      btn.type = 'button';
      btn.textContent = 'Replay Celebration';
      btn.style.position = 'fixed';
      btn.style.right = '24px';
      btn.style.bottom = '24px';
      btn.style.zIndex = '9998';
      btn.style.display = 'none';
      btn.style.padding = '10px 14px';
      btn.style.borderRadius = '999px';
      btn.style.border = '1px solid rgba(110,231,183,.45)';
      btn.style.background = 'rgba(16,185,129,.28)';
      btn.style.color = '#fff';
      btn.style.fontSize = '12px';
      btn.style.fontWeight = '700';
      btn.style.cursor = 'pointer';
      btn.style.boxShadow = '0 10px 24px rgba(0,0,0,.35)';
      btn.addEventListener('click', () => runCelebration(lastCelebrationPayload || {}));
      document.body.appendChild(btn);

      return btn;
    }

    function runCelebration(detail) {
      const payload = payloadFromDetail(detail);
      lastCelebrationPayload = payload;
      const pegasusGif = payload.pegasus_gif || '/images/pegasus.gif';
      const titleText = payload.title || 'Published to Pouring Room';
      const subtitleText = payload.subtitle || '';

      const existing = document.getElementById('mf-retail-celebration');
      if (existing) existing.remove();

      const overlay = document.createElement('div');
      overlay.id = 'mf-retail-celebration';
      overlay.style.position = 'fixed';
      overlay.style.inset = '0';
      overlay.style.zIndex = '9999';
      overlay.style.pointerEvents = 'none';
      overlay.style.overflow = 'hidden';
      overlay.style.background = 'radial-gradient(ellipse at center, rgba(16,185,129,0.12), rgba(0,0,0,0) 55%)';

      const title = document.createElement('div');
      title.textContent = titleText;
      title.style.position = 'absolute';
      title.style.left = '50%';
      title.style.top = '8%';
      title.style.transform = 'translateX(-50%)';
      title.style.padding = '10px 18px';
      title.style.borderRadius = '999px';
      title.style.border = '1px solid rgba(110,231,183,.55)';
      title.style.background = 'rgba(16,185,129,.35)';
      title.style.color = '#fff';
      title.style.fontWeight = '700';
      title.style.letterSpacing = '.03em';
      overlay.appendChild(title);

      if (subtitleText) {
        const subtitle = document.createElement('div');
        subtitle.textContent = subtitleText;
        subtitle.style.position = 'absolute';
        subtitle.style.left = '50%';
        subtitle.style.top = '14%';
        subtitle.style.transform = 'translateX(-50%)';
        subtitle.style.padding = '6px 12px';
        subtitle.style.borderRadius = '999px';
        subtitle.style.border = '1px solid rgba(110,231,183,.35)';
        subtitle.style.background = 'rgba(0,0,0,.25)';
        subtitle.style.color = '#d1fae5';
        subtitle.style.fontSize = '12px';
        overlay.appendChild(subtitle);
      }

      const pegasus = document.createElement('img');
      pegasus.src = pegasusGif;
      pegasus.alt = 'Flying Pegasus';
      pegasus.style.position = 'absolute';
      pegasus.style.left = '-280px';
      pegasus.style.top = '58%';
      pegasus.style.width = '240px';
      pegasus.style.height = 'auto';
      pegasus.style.filter = 'drop-shadow(0 18px 24px rgba(0,0,0,.45))';
      pegasus.animate(
        [
          { transform: 'translateX(0) translateY(0) rotate(-2deg)' },
          { transform: 'translateX(42vw) translateY(-22px) rotate(2deg)', offset: 0.45 },
          { transform: 'translateX(96vw) translateY(0) rotate(-1deg)' },
        ],
        { duration: 6200, easing: 'ease-in-out', fill: 'forwards' }
      );
      overlay.appendChild(pegasus);

      createFireworks(overlay, 72);
      document.body.appendChild(overlay);
      ensureReplayButton().style.display = 'inline-flex';

      window.setTimeout(() => overlay.remove(), 7200);
    }

    window.addEventListener('retail-plan-published', (e) => runCelebration(e.detail));
    window.addEventListener('event-submitted', (e) => runCelebration(e.detail));
  })();
</script>
