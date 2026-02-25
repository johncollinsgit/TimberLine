<div class="space-y-4 sm:space-y-6 min-w-0">
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-4 sm:p-6 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)] min-w-0">
    <div class="flex min-w-0 flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
      <div class="min-w-0">
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">All Pour Lists</div>
        <div class="mt-2 max-w-full sm:max-w-[32rem] text-2xl sm:text-3xl font-['Fraunces'] font-semibold text-white truncate" title="{{ $plan->name }}">{{ $plan->name }}</div>
        <div class="mt-2 text-sm text-emerald-50/70 break-words">{{ $queueMeta['subtitle'] ?? 'Draft list for today. Publish to push to the pouring room.' }}</div>
        <div class="mt-2 text-xs text-emerald-100/70 italic">“{{ $quote }}”</div>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" wire:click="prefillFromOrders"
          class="px-4 py-2 rounded-full text-xs border border-emerald-400/25 bg-emerald-500/10 text-white/85 hover:bg-emerald-500/15 transition">
          {{ $queueMeta['prefill_label'] ?? 'Prefill from Retail Orders' }}
        </button>
        <button type="button" wire:click="clearScents"
          class="px-4 py-2 rounded-full text-xs border border-emerald-400/25 bg-emerald-500/10 text-white/85 hover:bg-emerald-500/15 transition">
          Clear Scents
        </button>
        <button type="button" wire:click="publishPlan"
          class="px-4 py-2 rounded-full text-xs border border-emerald-400/40 bg-emerald-500/25 text-white font-semibold hover:bg-emerald-500/30 transition">
          Publish to Pouring
        </button>
      </div>
    </div>
    <div class="mt-5 grid grid-cols-1 gap-3 md:grid-cols-3" role="navigation" aria-label="Pour list queues">
      @foreach (['retail' => ['label' => 'Retail', 'desc' => 'Shopify retail queue'], 'wholesale' => ['label' => 'Wholesale', 'desc' => 'Wholesale order queue'], 'markets' => ['label' => 'Markets', 'desc' => 'Market/event planning queue']] as $tabKey => $tabMeta)
        @php($isActiveQueue = ($queueMeta['key'] ?? 'retail') === $tabKey)
        <a
          href="{{ route('retail.plan', ['queue' => $tabKey]) }}"
          class="group block rounded-2xl border p-4 sm:p-5 transition min-w-0 {{ $isActiveQueue ? 'border-emerald-300/35 bg-emerald-500/18 shadow-[0_18px_40px_-30px_rgba(16,185,129,.35)]' : 'border-emerald-200/10 bg-emerald-500/5 hover:bg-emerald-500/10 hover:border-emerald-300/20' }}"
        >
          <div class="flex h-full min-h-[5.5rem] flex-col justify-between gap-3">
            <div class="min-w-0">
              <div class="text-base sm:text-lg font-semibold text-white leading-tight">{{ $tabMeta['label'] }}</div>
              <div class="mt-1 text-xs sm:text-sm text-emerald-50/65">{{ $tabMeta['desc'] }}</div>
            </div>
            <div class="inline-flex items-center gap-1 text-xs font-semibold {{ $isActiveQueue ? 'text-emerald-50' : 'text-emerald-100/80 group-hover:text-emerald-50' }}">
              {{ $isActiveQueue ? 'Current Queue' : 'Open Queue' }} <span aria-hidden="true">→</span>
            </div>
          </div>
        </a>
      @endforeach
    </div>
    @if(!empty($queueMeta['markets_help']))
      <div class="mt-4 rounded-2xl border border-amber-300/20 bg-amber-500/10 px-4 py-3 text-xs text-amber-50/85">
        Markets planning uses the existing Events + Market Pour Lists tools for forecasting (recommended for 4-week lookahead).
        <a href="{{ route('events.index') }}" class="underline decoration-amber-200/60 underline-offset-2">Events</a>
        ·
        <a href="{{ route('markets.lists.index') }}" class="underline decoration-amber-200/60 underline-offset-2">Market Pour Lists</a>
      </div>
    @endif
  </section>

  <div class="grid grid-cols-1 xl:grid-cols-12 gap-4 sm:gap-6 min-w-0" data-rp-grid>
    <div class="xl:col-span-12" data-rp-panel="add-scents" data-size="full" draggable="true">
      <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-4 sm:p-5 h-full min-w-0" data-rp-surface>
        <div class="flex min-w-0 flex-wrap items-center justify-between gap-2">
          <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60">Add Additional Scents to the list</div>
          <div class="flex items-center gap-1">
            <button type="button" data-rp-size="square" class="rounded-full border border-emerald-300/25 bg-emerald-500/10 px-2 py-1 text-[10px] text-white/80">Square</button>
            <button type="button" data-rp-size="half" class="rounded-full border border-emerald-300/25 bg-emerald-500/10 px-2 py-1 text-[10px] text-white/80">Half</button>
            <button type="button" data-rp-size="full" class="rounded-full border border-emerald-300/25 bg-emerald-500/10 px-2 py-1 text-[10px] text-white/80">Full</button>
          </div>
        </div>
        <div class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-12 md:items-end min-w-0" data-rp-body>
          <div class="{{ ($queueMeta['key'] ?? '') === 'markets' ? 'md:col-span-7' : 'md:col-span-5' }}">
            <label class="text-xs text-emerald-100/60">Scent</label>
            <livewire:components.scent-combobox
              emit-key="retail-plan"
              :selected-id="(int)($inventoryScentId ?? 0)"
              :allow-wholesale-custom="false"
              wire:key="retail-plan-scent"
            />
          </div>
          @if(($queueMeta['key'] ?? '') === 'markets')
            <div class="md:col-span-5">
              <label class="text-xs text-emerald-100/60">Add Market Box</label>
              <div class="mt-1 grid grid-cols-2 gap-2">
                <button type="button" wire:click="addMarketHalfBox"
                  class="w-full rounded-xl border border-emerald-400/25 bg-emerald-500/10 px-3 py-2 text-sm text-white/90 hover:bg-emerald-500/15">
                  Add Half Box
                </button>
                <button type="button" wire:click="addMarketFullBox"
                  class="w-full rounded-xl border border-emerald-400/35 bg-emerald-500/20 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-500/25">
                  Add Full Box
                </button>
              </div>
              <div class="mt-2 text-[11px] text-emerald-100/60">
                1 box = 4x 16oz cotton, 8x 8oz cotton, 8x wax melts (half box = half quantities)
              </div>
            </div>
          @else
            <div class="md:col-span-4">
              <label class="text-xs text-emerald-100/60">Size</label>
              <input type="text"
                list="retail-size-list"
                wire:model.live.debounce.200ms="inventorySizeSearch"
                wire:blur="selectInventorySize"
                wire:change="selectInventorySize"
                class="mt-1 w-full rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90"
                placeholder="Start typing a size..." />
              <datalist id="retail-size-list">
                @foreach($sizes as $size)
                  <option value="{{ $size->label ?? $size->code }}"></option>
                @endforeach
              </datalist>
            </div>
            <div class="md:col-span-1">
              <label class="text-xs text-emerald-100/60">Quantity</label>
              <input type="number" min="1" wire:model="inventoryQty"
                class="mt-1 w-full rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2 text-sm text-white/90" />
            </div>
            <button type="button" wire:click="addInventoryItem"
              class="md:col-span-2 w-full rounded-xl border border-emerald-400/25 bg-emerald-500/15 px-3 py-2 text-sm text-white/90">
              {{ $queueMeta['add_button_label'] ?? 'Add to Retail/Pour List' }}
            </button>
          @endif
        </div>
      </div>
    </div>

    <div class="xl:col-span-12" data-rp-panel="candles" data-size="full" draggable="true">
      <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-4 sm:p-5 min-w-0">
        <div class="flex min-w-0 flex-wrap items-center justify-between gap-2">
          <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60">Candles to be poured</div>
          <div class="flex items-center gap-1">
            <button type="button" data-rp-size="square" class="rounded-full border border-emerald-300/25 bg-emerald-500/10 px-2 py-1 text-[10px] text-white/80">Square</button>
            <button type="button" data-rp-size="half" class="rounded-full border border-emerald-300/25 bg-emerald-500/10 px-2 py-1 text-[10px] text-white/80">Half</button>
            <button type="button" data-rp-size="full" class="rounded-full border border-emerald-300/25 bg-emerald-500/10 px-2 py-1 text-[10px] text-white/80">Full</button>
          </div>
        </div>
        <div class="mt-4 space-y-2" data-rp-body>
          @if($items->isEmpty())
            <div class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 p-4 text-sm text-emerald-50/70">
              {{ $queueMeta['empty_label'] ?? 'No items yet. Prefill from retail orders or add inventory below.' }}
            </div>
          @else
            @foreach($items as $item)
            <div class="flex flex-col gap-2 rounded-2xl border border-emerald-200/10 bg-emerald-500/5 px-4 py-3 md:flex-row md:items-center md:justify-between min-w-0">
              <div class="min-w-0">
                <div class="text-sm text-white/90">
                  @if(($queueMeta['key'] ?? '') === 'markets')
                    {{ $scents->firstWhere('id', $item->scent_id)?->display_name ?? $scents->firstWhere('id', $item->scent_id)?->name ?? ($item->sku ?: 'Unknown scent') }}
                    <span class="text-emerald-100/60">· Market Box</span>
                  @else
                    {{ $scents->firstWhere('id', $item->scent_id)?->display_name ?? $scents->firstWhere('id', $item->scent_id)?->name ?? 'Unknown' }}
                    <span class="text-emerald-100/60">· {{ $sizes->firstWhere('id', $item->size_id)?->display ?? $sizes->firstWhere('id', $item->size_id)?->code ?? '—' }}</span>
                  @endif
                </div>
                <div class="text-xs text-emerald-100/60">
                  @if(isset($marketSourceLabels[$item->id]))
                    {{ $marketSourceLabels[$item->id] }}
                  @elseif(($item->source ?? '') === 'inventory')
                    Inventory
                  @elseif(($item->source ?? '') === 'market_box_draft')
                    Market Draft
                  @elseif(($item->source ?? '') === 'market_box_manual')
                    Market Box
                  @elseif(($queueMeta['key'] ?? '') === 'markets')
                    Market Draft
                  @else
                    Order
                  @endif
                  @if(($item->status ?? 'draft') === 'needs_mapping' && (($queueMeta['key'] ?? '') !== 'markets' || empty($item->scent_id)))
                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] border border-amber-300/35 bg-amber-400/20 text-amber-50">
                      Needs mapping
                    </span>
                  @endif
                </div>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <div class="flex items-center rounded-full border border-emerald-200/15 bg-black/30 px-2 py-1 shrink-0">
                  <button type="button" wire:click="decrementItemQuantity({{ $item->id }})"
                    class="h-6 w-6 rounded-full border border-emerald-300/20 bg-emerald-500/10 text-emerald-50 hover:bg-emerald-500/20 transition">
                    −
                  </button>
                  @if(($queueMeta['key'] ?? '') === 'markets')
                    <span class="w-20 text-center text-xs text-white/90">{{ $this->marketBoxLabel($item) }}</span>
                  @else
                    <input type="number" min="1" value="{{ $item->quantity }}"
                      wire:change="updateItemQuantity({{ $item->id }}, $event.target.value)"
                      class="w-12 bg-transparent text-center text-xs text-white/90 focus:outline-none appearance-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none" />
                  @endif
                  <button type="button" wire:click="incrementItemQuantity({{ $item->id }})"
                    class="h-6 w-6 rounded-full border border-emerald-300/20 bg-emerald-500/10 text-emerald-50 hover:bg-emerald-500/20 transition">
                    +
                  </button>
                </div>
                @if(($queueMeta['key'] ?? '') !== 'markets')
                  <div class="flex items-center rounded-full border border-emerald-200/10 bg-black/20 px-2 py-1 shrink-0">
                    <span class="text-[10px] text-emerald-100/60 mr-2">Additional for inventory</span>
                    <button type="button" wire:click="decrementItemInventoryQuantity({{ $item->id }})"
                      class="h-6 w-6 rounded-full border border-emerald-300/15 bg-emerald-500/5 text-emerald-50 hover:bg-emerald-500/15 transition">
                      −
                    </button>
                    <input type="number" min="0" value="{{ $item->inventory_quantity ?? 0 }}"
                      wire:change="updateItemInventoryQuantity({{ $item->id }}, $event.target.value)"
                      class="w-12 bg-transparent text-center text-xs text-white/90 focus:outline-none appearance-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none" />
                    <button type="button" wire:click="incrementItemInventoryQuantity({{ $item->id }})"
                      class="h-6 w-6 rounded-full border border-emerald-300/15 bg-emerald-500/5 text-emerald-50 hover:bg-emerald-500/15 transition">
                      +
                    </button>
                  </div>
                @endif
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
            class="w-full rounded-xl border border-emerald-400/40 bg-emerald-500/25 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-500/30 transition">
            Publish to Pouring room
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    const KEY = 'retail_plan_panels_v2';

    function readState() {
      try {
        const raw = localStorage.getItem(KEY);
        return raw ? JSON.parse(raw) : null;
      } catch (_) {
        return null;
      }
    }

    function writeState(grid) {
      const order = Array.from(grid.querySelectorAll('[data-rp-panel]')).map((p) => p.dataset.rpPanel);
      const sizes = {};
      Array.from(grid.querySelectorAll('[data-rp-panel]')).forEach((p) => {
        sizes[p.dataset.rpPanel] = p.dataset.size || 'half';
      });
      localStorage.setItem(KEY, JSON.stringify({ order, sizes }));
    }

    function applySize(panel, size) {
      panel.dataset.size = size;
      panel.style.gridColumn = size === 'full' ? 'span 12 / span 12' : 'span 6 / span 6';
      panel.style.aspectRatio = size === 'square' ? '1 / 1' : '';

      const body = panel.querySelector('[data-rp-body]');
      if (body) {
        body.style.maxHeight = size === 'square' ? 'calc(100% - 2.5rem)' : '';
        body.style.overflow = size === 'square' ? 'auto' : '';
      }

      panel.querySelectorAll('[data-rp-size]').forEach((btn) => {
        const active = btn.getAttribute('data-rp-size') === size;
        btn.classList.toggle('bg-emerald-400/30', active);
        btn.classList.toggle('text-white', active);
      });
    }

    function applyState(grid, state) {
      if (!state || typeof state !== 'object') return;

      const panelsById = {};
      grid.querySelectorAll('[data-rp-panel]').forEach((panel) => {
        panelsById[panel.dataset.rpPanel] = panel;
      });

      if (Array.isArray(state.order)) {
        state.order.forEach((id) => {
          const panel = panelsById[id];
          if (panel) grid.appendChild(panel);
        });
      }

      const sizes = state.sizes || {};
      grid.querySelectorAll('[data-rp-panel]').forEach((panel) => {
        applySize(panel, sizes[panel.dataset.rpPanel] || panel.dataset.size || 'half');
      });
    }

    function mountRetailPlanPanels() {
      const grid = document.querySelector('[data-rp-grid]');
      if (!grid || grid.dataset.rpBound === '1') return;
      grid.dataset.rpBound = '1';

      applyState(grid, readState());

      grid.querySelectorAll('[data-rp-panel]').forEach((panel) => {
        panel.addEventListener('dragstart', (e) => {
          if (e.target.closest('input, select, textarea, button, a, label, option, datalist, [role="button"]')) {
            e.preventDefault();
            return;
          }
          panel.classList.add('opacity-60');
          e.dataTransfer.setData('text/plain', panel.dataset.rpPanel || '');
          e.dataTransfer.effectAllowed = 'move';
        });

        panel.addEventListener('dragend', () => {
          panel.classList.remove('opacity-60');
          writeState(grid);
        });

        panel.querySelectorAll('[data-rp-size]').forEach((btn) => {
          btn.addEventListener('click', () => {
            const size = btn.getAttribute('data-rp-size') || 'half';
            applySize(panel, size);
            writeState(grid);
          });
        });
      });

      grid.addEventListener('dragover', (e) => {
        e.preventDefault();
        const draggedId = e.dataTransfer.getData('text/plain');
        const dragged = draggedId ? grid.querySelector(`[data-rp-panel="${draggedId}"]`) : null;
        const target = e.target.closest('[data-rp-panel]');
        if (!dragged || !target || dragged === target) return;

        const rect = target.getBoundingClientRect();
        const before = e.clientY < rect.top + rect.height / 2;
        if (before) {
          grid.insertBefore(dragged, target);
        } else {
          grid.insertBefore(dragged, target.nextSibling);
        }
      });

      grid.addEventListener('drop', () => writeState(grid));
    }

    document.addEventListener('DOMContentLoaded', mountRetailPlanPanels);
    document.addEventListener('livewire:navigated', mountRetailPlanPanels);
  })();
</script>

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
      title.textContent = 'Published to Pouring Room';
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
  })();
</script>
