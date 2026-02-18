{{-- resources/views/livewire/shipping/orders.blade.php --}}
@php
  use Illuminate\Support\Carbon;
  use Carbon\CarbonPeriod;

  $pills = [
    'all' => 'All',
    'new' => 'New',
    'reviewed' => 'Reviewed',
    'submitted_to_pouring' => 'Submitted',
    'pouring' => 'Pouring',
    'brought_down' => 'Brought Down',
    'verified' => 'Verified',
    'complete' => 'Complete',
  ];

  $channels = [
    'all' => 'All',
    'wholesale' => 'Wholesale',
    'retail' => 'Retail',
    'event' => 'Event',
  ];

  $views = [
    'list' => ['label' => 'List', 'icon' => '≡'],
    'table' => ['label' => 'Table', 'icon' => '▦'],
    'timeline' => ['label' => 'Timeline', 'icon' => '🗓'],
  ];

  // Blue-forward palette helpers
  $panelBg    = 'linear-gradient(180deg, rgba(59,130,246,.12), rgba(0,0,0,0))';
  $cardBg     = 'linear-gradient(180deg, rgba(96,165,250,.14), rgba(255,255,255,.02))';
  $cardBgOpen = 'linear-gradient(180deg, rgba(96,165,250,.22), rgba(255,255,255,.04))';

  $fmtDate = function ($date) {
    if (blank($date)) return '—';
    try { return Carbon::parse($date)->format('M j, Y'); }
    catch (\Throwable $e) { return (string) $date; }
  };

  $fmtStatus = function ($status) {
    return $status ? ucwords(str_replace('_', ' ', $status)) : '—';
  };

  // TIMELINE GRID (Month view)
  $month = ($timelineMonth instanceof Carbon)
    ? $timelineMonth->copy()
    : now()->startOfMonth();

  $timelineOrders = $timelineOrders ?? collect();

  $startOfGrid = $month->copy()->startOfMonth()->startOfWeek(); // Sunday start
  $endOfGrid   = $month->copy()->endOfMonth()->endOfWeek();     // Saturday end
  $gridDays    = CarbonPeriod::create($startOfGrid, $endOfGrid);

  $byDay = $timelineOrders->groupBy(function ($o) {
    try {
      if (blank($o->due_date)) return '__none__';
      return Carbon::parse($o->due_date)->toDateString();
    } catch (\Throwable $e) {
      return '__none__';
    }
  });

  $daysOfWeek = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

  // Permissions placeholder
  $canEditOrders = true;

  // safe defaults (helps prevent "undefined" noise in blade)
  $lineDirty   = $lineDirty   ?? [];
  $lineOrder   = $lineOrder   ?? [];
  $orderNotice = $orderNotice ?? [];

  // Optional: if you pass $wicks from Livewire later, Blade won't complain
  $wicks = $wicks ?? [];
@endphp

<div class="min-h-[calc(100vh-4rem)]">
  <div class="space-y-6">

    {{-- TOP TOOLBAR --}}
    <section class="sticky top-4 z-30">
      <div
        style="background: {{ $panelBg }};"
        class="rounded-3xl border border-sky-500/15 bg-zinc-950/60 backdrop-blur
               shadow-[0_18px_60px_-40px_rgba(0,0,0,1)] overflow-hidden"
      >
        <div class="p-4">
          <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">

            {{-- Left --}}
            <div class="lg:col-span-4">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-semibold text-white/95">Orders</div>
                  <div class="text-xs text-white/50 mt-0.5">{{ $orders->total() }} total</div>
                </div>

                <div class="flex items-center gap-2">
                  <button type="button" wire:click="expandAll"
                    class="px-3 py-1.5 rounded-xl text-xs border border-sky-400/20 bg-sky-500/10 hover:bg-sky-500/15 text-white/80 transition">
                    Expand
                  </button>
                  <button type="button" wire:click="collapseAll"
                    class="px-3 py-1.5 rounded-xl text-xs border border-sky-400/20 bg-sky-500/10 hover:bg-sky-500/15 text-white/80 transition">
                    Collapse
                  </button>
                </div>
              </div>

              {{-- View selector --}}
              <div class="mt-3 flex flex-wrap items-center gap-2">
                @foreach($views as $key => $meta)
                  @php $active = (($view ?? 'list') === $key); @endphp
                  <button type="button" wire:click="$set('view','{{ $key }}')"
                    class="px-3 py-1.5 rounded-xl text-xs border transition inline-flex items-center gap-2
                      {{ $active ? 'border-sky-300/35 bg-sky-400/25 text-sky-50' : 'border-sky-400/15 bg-sky-500/5 text-white/75 hover:bg-sky-500/10 hover:border-sky-300/25' }}">
                    <span class="text-white/70">{{ $meta['icon'] }}</span>
                    <span>{{ $meta['label'] }}</span>
                  </button>
                @endforeach
              </div>

              {{-- Month nav only in timeline --}}
              @if(($view ?? 'list') === 'timeline')
                <div class="mt-3 flex items-center gap-2">
                  <button type="button" wire:click="timelinePrevMonth"
                    class="px-3 py-1.5 rounded-xl text-xs border border-sky-400/20 bg-sky-500/10 hover:bg-sky-500/15 text-white/80 transition"
                    title="Previous month">←</button>

                  <button type="button" wire:click="timelineToday"
                    class="px-3 py-1.5 rounded-xl text-xs border border-sky-400/20 bg-sky-500/10 hover:bg-sky-500/15 text-white/80 transition"
                    title="Jump to current month">Today</button>

                  <button type="button" wire:click="timelineNextMonth"
                    class="px-3 py-1.5 rounded-xl text-xs border border-sky-400/20 bg-sky-500/10 hover:bg-sky-500/15 text-white/80 transition"
                    title="Next month">→</button>

                  <div class="ml-2 text-xs text-white/55">
                    <span class="text-white/85 font-semibold">{{ $month->format('F Y') }}</span>
                  </div>
                </div>
              @endif
            </div>

            {{-- Middle --}}
            <div class="lg:col-span-4">
              <label class="block text-xs text-white/50 mb-2">Search</label>
              <div class="relative">
                <div class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-white/40">⌕</div>
                <input type="text" wire:model.live.debounce.250ms="search"
                  placeholder="Order #, customer, market, scent..."
                  class="w-full pl-9 pr-3 py-2 rounded-2xl bg-sky-500/5 border border-sky-400/15 text-white placeholder:text-white/40
                         focus:outline-none focus:ring-2 focus:ring-sky-400/20" />
              </div>

              {{-- Channel --}}
              <div class="mt-3">
                <div class="flex items-center justify-between mb-2">
                  <div class="text-xs text-white/50">Channel</div>
                  <div class="text-xs text-sky-200/70">{{ ucfirst($channel ?? 'all') }}</div>
                </div>

                <div class="flex flex-wrap gap-2">
                  @foreach($channels as $key => $label)
                    @php $active = (($channel ?? 'all') === $key); @endphp
                    <button type="button" wire:click="$set('channel','{{ $key }}')"
                      class="px-3 py-1.5 rounded-full text-xs border transition
                        {{ $active ? 'border-sky-300/35 bg-sky-400/25 text-sky-50' : 'border-sky-400/15 bg-sky-500/5 text-white/80 hover:bg-sky-500/10 hover:border-sky-300/25' }}">
                      {{ $label }}
                    </button>
                  @endforeach
                </div>
              </div>
            </div>

            {{-- Right --}}
            <div class="lg:col-span-4">
              <div class="flex items-center justify-between mb-2">
                <div class="text-xs text-white/50">Status</div>
                <div class="text-xs text-sky-200/70">{{ ucfirst($status ?? 'all') }}</div>
              </div>

              <div class="flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden">
                @foreach($pills as $key => $label)
                  @php $active = (($status ?? 'all') === $key); @endphp
                  <button type="button" wire:click="$set('status', '{{ $key }}')"
                    class="shrink-0 px-3 py-1.5 rounded-full text-xs border transition
                      {{ $active ? 'border-sky-300/35 bg-sky-400/25 text-sky-50 shadow-[0_0_0_1px_rgba(56,189,248,.08)]' : 'border-sky-400/15 bg-sky-500/5 text-white/80 hover:bg-sky-500/10 hover:border-sky-300/25' }}">
                    {{ $label }}
                  </button>
                @endforeach
              </div>

              <div class="mt-2 text-[11px] text-white/40">
                Tip: Timeline puts orders on their ship-by date (day-of-month) like Notion.
              </div>
            </div>

          </div>
        </div>
      </div>
    </section>

    {{-- CONTENT --}}
    <main>

      {{-- LIST VIEW (unchanged, display only) --}}
      @if(($view ?? 'list') === 'list')
        <div class="space-y-4">
          @forelse($orders as $order)
            @php
              $isOpen = $expanded[$order->id] ?? false;

              $rail = match($order->status) {
                'new' => 'linear-gradient(180deg, rgba(147,197,253,1), rgba(59,130,246,.25), rgba(0,0,0,0))',
                'reviewed' => 'linear-gradient(180deg, rgba(56,189,248,1), rgba(56,189,248,.22), rgba(0,0,0,0))',
                'submitted_to_pouring' => 'linear-gradient(180deg, rgba(99,102,241,1), rgba(59,130,246,.22), rgba(0,0,0,0))',
                'pouring' => 'linear-gradient(180deg, rgba(59,130,246,1), rgba(14,165,233,.22), rgba(0,0,0,0))',
                'brought_down' => 'linear-gradient(180deg, rgba(56,189,248,1), rgba(59,130,246,.18), rgba(0,0,0,0))',
                'verified' => 'linear-gradient(180deg, rgba(96,165,250,1), rgba(59,130,246,.20), rgba(0,0,0,0))',
                'complete' => 'linear-gradient(180deg, rgba(186,230,253,1), rgba(59,130,246,.14), rgba(0,0,0,0))',
                default => 'linear-gradient(180deg, rgba(147,197,253,.8), rgba(59,130,246,.12), rgba(0,0,0,0))',
              };

              $surface = $isOpen ? $cardBgOpen : $cardBg;
              $shadow  = $isOpen ? '0 22px 60px -40px rgba(0,0,0,1)' : '0 14px 46px -38px rgba(0,0,0,.95)';

              $lines = $order->lines ?? collect();

              $orderNumber = $order->order_number ?? '—';
              $market      = $order->container_name ?? '—';
              $customer    = $order->customer_name ?? '—';
              $due         = $fmtDate($order->due_date ?? null);

              $linesCount  = $lines->count();
              $qtyTotal    = $lines->sum(fn ($l) => (int)(($l->ordered_qty ?? $l->quantity) ?? 0));
              $statusLabel = $fmtStatus($order->status ?? null);
            @endphp

            <div style="background: {{ $surface }}; box-shadow: {{ $shadow }};"
                 class="group relative rounded-3xl overflow-hidden transition">
              <div class="absolute inset-0 rounded-3xl border border-sky-500/14 pointer-events-none"></div>
              <div class="absolute -inset-8 bg-sky-500/5 blur-3xl pointer-events-none"></div>
              <div style="background: {{ $rail }};" class="absolute left-0 top-4 bottom-4 w-[6px] rounded-full opacity-95"></div>

              <button type="button" wire:click="toggle({{ $order->id }})"
                      class="relative w-full px-6 py-5 text-left hover:bg-sky-500/5 transition">
                <div class="flex items-start justify-between gap-4">
                  <div class="min-w-0 pl-1">
                    <div class="min-w-0 flex items-center gap-2">
                      <div class="font-semibold text-white/95 truncate">{{ $market }}</div>
                      <span class="text-white/25">·</span>
                      <div class="text-white/80 truncate">{{ $customer }}</div>
                    </div>

                    <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-white/55">
                      <span class="text-sky-100/80">{{ $orderNumber }}</span>
                      <span class="inline-flex items-center gap-1"><span class="text-white/25">•</span><span>{{ $linesCount }} lines</span></span>
                      <span class="inline-flex items-center gap-1"><span class="text-white/25">•</span><span>{{ $qtyTotal }} qty</span></span>
                      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] border border-white/10 bg-white/5 text-white/70">
                        {{ $statusLabel }}
                      </span>
                    </div>
                  </div>

                  <div class="flex items-center gap-3 shrink-0">
                    <div class="text-sm text-white/60">
                      Due: <span class="text-sky-50/90">{{ $due }}</span>
                    </div>

                    <div class="text-xs px-3 py-1.5 rounded-full border
                      {{ $isOpen ? 'border-sky-300/30 bg-sky-400/20 text-sky-50' : 'border-sky-400/15 bg-sky-500/8 text-white/70' }}">
                      {{ $isOpen ? 'Open' : 'Closed' }}
                    </div>

                    <div class="text-sky-100/40 group-hover:text-sky-100/75 transition">
                      {{ $isOpen ? '▾' : '▸' }}
                    </div>
                  </div>
                </div>
              </button>

              @if($isOpen)
                <div class="relative px-6 pb-6 pt-4">
                  @if($lines->isEmpty())
                    <div class="rounded-2xl border border-sky-400/12 bg-sky-500/5 p-4 text-sm text-white/70">
                      No line items.
                    </div>
                  @else
                    <div class="rounded-2xl border border-sky-400/12 bg-sky-500/5 overflow-hidden">
                      <div class="flex items-center justify-between px-4 py-3 text-xs text-white/55 border-b border-sky-400/10">
                        <div>Line items</div>
                        <div class="text-white/45">{{ $lines->count() }} items · Total qty {{ $lines->sum(fn ($l) => (int)(($l->ordered_qty ?? $l->quantity) ?? 0))}}</div>
                      </div>

                      <div class="divide-y divide-sky-400/10">
                        @foreach($lines as $line)
                        @php
                          $title   = $line->raw_title ?? $line->product_title ?? $line->title ?? $line->name ?? $line->scent_name ?? 'Item';
                          $variant = $line->raw_variant ?? $line->variant_title ?? $line->variant_name ?? $line->size ?? $line->size_code ?? $line->sku ?? null;

                          // show ordered_qty if present; fallback to legacy quantity
                          $qty = (int) (($line->ordered_qty ?? $line->quantity) ?? 0);

                          $pour = $line->pour_status ?? null;
                          $img = $line->image_url ?? $line->image ?? $line->image_src ?? null;
                        @endphp

                          <div class="px-4 py-4">
                            <div class="flex items-start gap-4">
                              <div class="shrink-0">
                                @if($img)
                                  <img src="{{ $img }}" alt=""
                                       class="h-14 w-14 rounded-xl object-cover border border-white/10 bg-white/5"
                                       loading="lazy" />
                                @else
                                  <div class="h-14 w-14 rounded-xl border border-white/10 bg-white/5 flex items-center justify-center text-white/30 text-xs">—</div>
                                @endif
                              </div>

                              <div class="min-w-0 flex-1">
                                <div class="min-w-0 flex items-center gap-2 text-sm truncate">
                                  <span class="font-semibold text-white/90 truncate">{{ $title }}</span>

                                  @if($variant)
                                    <span class="text-white/30">·</span>
                                    <span class="text-white/55 truncate">{{ $variant }}</span>
                                  @endif

                                  <span class="text-white/30">·</span>

                                  <span class="inline-flex items-center gap-1 text-white/70 shrink-0">
                                    <span class="text-white/40">×</span>
                                    <span class="px-2 py-0.5 rounded-full text-xs border border-white/10 bg-white/5 text-white/80">{{ $qty }}</span>
                                  </span>

                                  @if($pour)
                                    <span class="text-white/30">·</span>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs border border-sky-300/20 bg-sky-500/10 text-sky-100/80 shrink-0">
                                      {{ $pour }}
                                    </span>
                                  @endif
                                </div>
                              </div>
                            </div>
                          </div>
                        @endforeach
                      </div>

                      <div class="flex items-center justify-between px-4 py-3 text-xs text-white/55 border-t border-sky-400/10">
                        <div>{{ $lines->count() }} items</div>
                        <div>Total qty: <span class="text-white/80 font-semibold">{{ $lines->sum(fn ($l) => (int)(($l->ordered_qty ?? $l->quantity) ?? 0))}}</span></div>
                      </div>
                    </div>
                  @endif
                </div>
              @endif
            </div>
          @empty
            <div class="rounded-3xl border border-sky-500/15 bg-sky-500/5 p-10 text-center">
              <div class="text-white font-medium">No orders found</div>
              <div class="text-white/50 text-sm mt-1">Try adjusting filters or search.</div>
            </div>
          @endforelse

          <div class="pt-4">{{ $orders->links() }}</div>
        </div>
      @endif

      {{-- TABLE VIEW (fixed + clean) --}}
      @if(($view ?? 'list') === 'table')
        <div class="rounded-3xl border border-sky-500/15 bg-sky-500/5 overflow-hidden">
          <div class="px-4 py-3 border-b border-sky-400/10 flex items-center justify-between">
            <div class="text-sm font-semibold text-white/90">Table View</div>
            <div class="text-xs text-white/55">Shipping-room spreadsheet mode.</div>
          </div>

          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="text-xs text-white/55 bg-black/20">
                <tr class="[&>th]:px-4 [&>th]:py-3 [&>th]:text-left [&>th]:font-medium">
                  <th>Order</th>
                  <th>Market</th>
                  <th>Customer</th>
                  <th>Due</th>
                  <th>Status</th>
                  <th class="text-right">Lines</th>
                  <th class="text-right">Qty</th>
                  <th class="text-right">Open</th>
                </tr>
              </thead>

              <tbody class="divide-y divide-sky-400/10">
                @forelse($orders as $order)
                  @php
                    $lines = $order->lines ?? collect();
                    $isOpen = $expanded[$order->id] ?? false;
                    $isEditingOrder = ($orderEditing[$order->id] ?? false) === true;

                    // Count dirty lines for THIS order only
                    $dirtyForOrder = 0;
                    foreach (($lineDirty ?? []) as $lineId => $true) {
                      if (($lineOrder[$lineId] ?? null) === $order->id) $dirtyForOrder++;
                    }
                  @endphp

                  {{-- main row --}}
                  <tr class="hover:bg-sky-500/5 transition" wire:key="order-row-{{ $order->id }}">
                    <td class="px-4 py-3 text-sky-100/80 whitespace-nowrap">
                      {{ $order->order_number ?? '—' }}
                    </td>

                    <td class="px-4 py-3 text-white/80 whitespace-nowrap">
                      {{ $order->container_name ?? '—' }}
                    </td>

                    <td class="px-4 py-3 text-white/80">
                      {{ $order->customer_name ?? '—' }}
                    </td>

                    {{-- Due --}}
                    <td class="px-4 py-3 text-white/70 whitespace-nowrap">
                      @if($canEditOrders && $isEditingOrder)
                        <input
                          type="date"
                          class="w-[150px] rounded-xl border border-white/10 bg-white/5 px-2 py-1 text-white/90
                                 focus:outline-none focus:ring-2 focus:ring-sky-400/20"
                          wire:model.defer="orderEdit.{{ $order->id }}.due_date"
                        />
                        @error("orderEdit.$order->id.due_date")
                          <div class="mt-1 text-[11px] text-red-400">{{ $message }}</div>
                        @enderror
                      @else
                        <button
                          type="button"
                          class="hover:underline decoration-sky-300/40"
                          @if($canEditOrders) wire:click="startEditing({{ $order->id }})" @endif
                        >
                          {{ $fmtDate($order->due_date ?? null) }}
                        </button>
                      @endif
                    </td>

                    {{-- Status --}}
                    <td class="px-4 py-3">
                      @if($canEditOrders && $isEditingOrder)
                        <select
                          class="w-[170px] rounded-full border border-white/10 bg-white/5 px-3 py-1 text-white/90
                                 focus:outline-none focus:ring-2 focus:ring-sky-400/20"
                          wire:model.defer="orderEdit.{{ $order->id }}.status"
                        >
                          <option value="new">New</option>
                          <option value="reviewed">Reviewed</option>
                          <option value="submitted_to_pouring">Submitted</option>
                          <option value="pouring">Pouring</option>
                          <option value="brought_down">Brought Down</option>
                          <option value="verified">Verified</option>
                          <option value="complete">Complete</option>
                        </select>
                        @error("orderEdit.$order->id.status")
                          <div class="mt-1 text-[11px] text-red-400">{{ $message }}</div>
                        @enderror
                      @else
                        <button
                          type="button"
                          class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] border border-white/10 bg-white/5 text-white/70 hover:bg-white/10 transition"
                          @if($canEditOrders) wire:click="startEditing({{ $order->id }})" @endif
                        >
                          {{ $fmtStatus($order->status ?? null) }}
                        </button>
                      @endif
                    </td>

                    <td class="px-4 py-3 text-right text-white/70 whitespace-nowrap">
                      {{ $lines->count() }}
                    </td>

                    <td class="px-4 py-3 text-right text-white/70 whitespace-nowrap">
                      {{ $lines->sum(fn ($l) => (int)(($l->ordered_qty ?? $l->quantity) ?? 0)) }}
                    </td>

                    <td class="px-4 py-3 text-right whitespace-nowrap">
                      <div class="flex items-center justify-end gap-2">
                        {{-- Save/Cancel for order-level edits --}}
                        @if($canEditOrders && $isEditingOrder)
                          <button
                            type="button"
                            wire:click="cancelEditing({{ $order->id }})"
                            class="px-3 py-1.5 rounded-xl text-xs border border-white/10 bg-white/5 hover:bg-white/10 text-white/75 transition"
                          >
                            Cancel
                          </button>
                          <button
                            type="button"
                            wire:click="saveOrder({{ $order->id }})"
                            wire:loading.attr="disabled"
                            class="px-3 py-1.5 rounded-xl text-xs border border-sky-400/25 bg-sky-500/15 hover:bg-sky-500/20 text-white/85 transition"
                          >
                            <span wire:loading.remove>Save</span>
                            <span wire:loading>Saving…</span>
                          </button>
                        @endif

                        {{-- Open/Close --}}
                        <button
                          type="button"
                          wire:click="toggle({{ $order->id }})"
                          class="px-3 py-1.5 rounded-xl text-xs border border-sky-400/20 bg-sky-500/10 hover:bg-sky-500/15 text-white/80 transition"
                        >
                          {{ $isOpen ? 'Close' : 'Open' }}
                        </button>
                      </div>
                    </td>
                  </tr>

                  {{-- open details row --}}
                  @if($isOpen)
                    <tr class="bg-black/20" wire:key="order-open-{{ $order->id }}">
                      <td colspan="8" class="px-4 py-4">
                        <div class="rounded-2xl border border-sky-400/12 bg-sky-500/5 overflow-hidden">

                          <div class="px-4 py-3 border-b border-sky-400/10 flex items-center justify-between">
                            <div class="text-xs text-white/60">
                              Line items
                              <span class="text-white/30">·</span>
                              <span class="text-white/50">{{ $lines->count() }} items</span>
                              <span class="text-white/30">·</span>
                              <span class="text-white/50">Total qty {{$lines->sum(fn ($l) => (int)($l->ordered_qty ?? $l->quantity ?? 0))}}</span>
                            </div>

                            {{-- Save dirty qty changes for this order only --}}
                            @if($canEditOrders && $dirtyForOrder > 0)
                              <button
                                type="button"
                                wire:click="saveOrderLines({{ $order->id }})"
                                wire:loading.attr="disabled"
                                class="px-3 py-1.5 rounded-xl text-xs border border-sky-400/25 bg-sky-500/15 hover:bg-sky-500/20 text-white/85 transition"
                              >
                                <span wire:loading.remove>Save {{ $dirtyForOrder }} change{{ $dirtyForOrder === 1 ? '' : 's' }}</span>
                                <span wire:loading>Saving…</span>
                              </button>
                            @endif
                          </div>

                          {{-- NOTICE --}}
                          @if(!empty($orderNotice[$order->id] ?? null))
                            <div class="px-4 py-2 border-b border-sky-400/10 bg-sky-500/10 text-xs text-sky-100/90">
                              {{ $orderNotice[$order->id] }}
                            </div>
                          @endif

                          {{-- ADD NEW LINE --}}
                          @if($canEditOrders)
                            <div class="px-4 py-3 border-b border-sky-400/10 bg-black/10">
                              <div class="grid grid-cols-12 gap-2 items-center">

                          {{-- SCENT --}}
                          <div class="col-span-4">
                            <input
                              type="text"
                              list="scents-list-{{ $order->id }}"
                              placeholder="Start typing a scent…"
                              class="w-full h-10 rounded-xl border border-white/10 bg-white/5 px-3 text-white/90 placeholder:text-white/35
                                    focus:outline-none focus:ring-2 focus:ring-sky-400/20"
                              wire:model.live.debounce.150ms="newLine.{{ $order->id }}.scent_search"
                              wire:keydown.enter.prevent="selectNewLineScent({{ $order->id }})"
                              wire:blur="selectNewLineScent({{ $order->id }})"
                              wire:change="selectNewLineScent({{ $order->id }})"
                            />

                            <datalist id="scents-list-{{ $order->id }}">
                              @foreach($scents as $scent)
                                <option value="{{ $scent->name }}"></option>
                              @endforeach
                            </datalist>

                            {{-- Show either scent_search or scent_id errors (depending on which one you hit) --}}
                            @error("newLine.$order->id.scent_search")
                              <div class="mt-1 text-[11px] text-red-400">{{ $message }}</div>
                            @enderror
                            @error("newLine.$order->id.scent_id")
                              <div class="mt-1 text-[11px] text-red-400">{{ $message }}</div>
                            @enderror
                          </div>


                          {{-- SIZE --}}
                          <div class="col-span-2">
                            <input
                              type="text"
                              list="sizes-list-{{ $order->id }}"
                              placeholder="Size…"
                              class="w-full h-10 rounded-xl border border-white/10 bg-white/5 px-3 text-white/90 placeholder:text-white/35
                                    focus:outline-none focus:ring-2 focus:ring-sky-400/20"
                              wire:model.live.debounce.150ms="newLine.{{ $order->id }}.size_search"
                              wire:keydown.enter.prevent="selectNewLineSize({{ $order->id }})"
                              wire:blur="selectNewLineSize({{ $order->id }})"
                              wire:change="selectNewLineSize({{ $order->id }})"
                            />

                            <datalist id="sizes-list-{{ $order->id }}">
                              @foreach($sizes as $size)
                                <option value="{{ $size->code }}"></option>
                              @endforeach
                            </datalist>

                            @error("newLine.$order->id.size_search")
                              <div class="mt-1 text-[11px] text-red-400">{{ $message }}</div>
                            @enderror
                            @error("newLine.$order->id.size_id")
                              <div class="mt-1 text-[11px] text-red-400">{{ $message }}</div>
                            @enderror
                          </div>


                                {{-- WICK --}}
                                <div class="col-span-2">
                                  <select
                                    class="w-full h-10 rounded-xl border border-white/10 bg-white/5 px-3 text-white/90
                                           focus:outline-none focus:ring-2 focus:ring-sky-400/20"
                                    wire:model.defer="newLine.{{ $order->id }}.wick"
                                  >
                                    <option value="">Wick…</option>
                                    <option value="cotton">Cotton</option>
                                    <option value="wood">Wood</option>
                                  </select>

                                  @error("newLine.$order->id.wick")
                                    <div class="mt-1 text-[11px] text-red-400">{{ $message }}</div>
                                  @enderror
                                </div>

                                {{-- QTY --}}
                                <div class="col-span-2">
                                  <div class="flex items-center gap-2 justify-end">
                                    <button
                                      type="button"
                                      wire:click="decrementNewLineQty({{ $order->id }})"
                                      class="h-10 w-10 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 text-white/85 transition"
                                      title="Decrease"
                                    >−</button>

                                    <input
                                      type="text"
                                      inputmode="numeric"
                                      pattern="[0-9]*"
                                      class="h-10 w-20 text-center rounded-xl border border-white/10 bg-white/5 px-2 text-white/90
                                             focus:outline-none focus:ring-2 focus:ring-sky-400/20"
                                      wire:model.defer="newLine.{{ $order->id }}.qty"
                                    />

                                    <button
                                      type="button"
                                      wire:click="incrementNewLineQty({{ $order->id }})"
                                      class="h-10 w-10 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 text-white/85 transition"
                                      title="Increase"
                                    >+</button>
                                  </div>

                                  @error("newLine.$order->id.qty")
                                    <div class="mt-1 text-[11px] text-red-400 text-right">{{ $message }}</div>
                                  @enderror
                                </div>

                                {{-- ADD --}}
                                <div class="col-span-2 flex justify-end">
                                  <button
                                    type="button"
                                    wire:click="addLineItem({{ $order->id }})"
                                    wire:loading.attr="disabled"
                                    class="h-10 px-5 rounded-xl text-sm font-semibold border border-sky-400/25 bg-sky-500/15 hover:bg-sky-500/20 text-white/90 transition"
                                  >
                                    + Add
                                  </button>
                                </div>

                              </div>
                            </div>
                          @endif

                          {{-- EXISTING LINES --}}
                          <div class="divide-y divide-sky-400/10">
                            @forelse($lines as $line)
                              @php
                                $name = $line->scent?->name ?? $line->scent_name ?? ($line->name ?? 'Item');
                                $size = $line->size ? ($line->size->label ?: $line->size->code) : ($line->size_code ?? null);
                                $qty = (int) (($line->ordered_qty ?? $line->quantity) ?? 0);

                                // ✅ correct place to compute this: inside the loop where $line exists
                                $isDirtyLine = !empty($lineDirty[$line->id] ?? false);
                              @endphp

                              <div class="px-4 py-3 flex items-center justify-between gap-4" wire:key="line-{{ $line->id }}">
                                <div class="min-w-0 flex-1">
                                  <div class="min-w-0 truncate text-white/85">
                                    <span class="font-semibold">{{ $name }}</span>
                                    @if(!blank($size))
                                      <span class="text-white/25">·</span>
                                      <span class="text-white/55">{{ $size }}</span>
                                    @endif
                                  </div>
                                </div>

                                <div class="shrink-0 flex items-center gap-2">
                                  @if($canEditOrders)
                                    <button
                                      type="button"
                                      wire:click="decrementLineQty({{ $line->id }})"
                                      class="h-9 w-9 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 text-white/80 transition"
                                      title="Decrease"
                                    >−</button>

                                    <input
                                      type="number"
                                      min="0"
                                      inputmode="numeric"
                                      class="w-20 h-9 text-center rounded-xl border border-white/10 bg-white/5 px-2 text-white/90
                                             focus:outline-none focus:ring-2 focus:ring-sky-400/20"
                                      wire:model.defer="lineEdit.{{ $line->id }}.qty"
                                    />

                                    <button
                                      type="button"
                                      wire:click="incrementLineQty({{ $line->id }})"
                                      class="h-9 w-9 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 text-white/80 transition"
                                      title="Increase"
                                    >+</button>

                                    {{-- DELETE (DB delete) --}}
                                    <button
                                      type="button"
                                      wire:click="deleteLine({{ $line->id }})"
                                      wire:loading.attr="disabled"
                                      class="ml-2 h-9 w-9 rounded-xl border border-red-400/25 bg-red-500/10 hover:bg-red-500/15 text-red-100/90 transition"
                                      title="Delete line item"
                                    >
                                      🗑
                                    </button>

                                    @if($isDirtyLine)
                                      <button
                                        type="button"
                                        wire:click="saveLine({{ $line->id }})"
                                        wire:loading.attr="disabled"
                                        class="ml-2 px-3 py-2 rounded-xl text-xs border border-white/10 bg-white/5 hover:bg-white/10 text-white/80 transition"
                                      >
                                        Save
                                      </button>
                                    @endif
                                  @else
                                    <div class="text-white/80">× {{ $qty }}</div>
                                  @endif
                                </div>
                              </div>
                            @empty
                              <div class="px-4 py-6 text-sm text-white/60">No line items.</div>
                            @endforelse
                          </div>

                          <div class="px-4 py-3 border-t border-sky-400/10 flex items-center justify-between text-xs text-white/55">
                            <div>{{ $lines->count() }} items</div>
                            <div>Total qty: <span class="text-white/80 font-semibold">{{ $lines->sum(fn ($l) => (int)(($l->ordered_qty ?? $l->quantity) ?? 0))}}</span></div>
                          </div>

                        </div>
                      </td>
                    </tr>
                  @endif

                @empty
                  <tr>
                    <td colspan="8" class="px-4 py-10 text-center text-white/60">No orders found.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>

          <div class="px-4 py-3 border-t border-sky-400/10">{{ $orders->links() }}</div>
        </div>
      @endif

      {{-- TIMELINE VIEW (unchanged) --}}
      @if(($view ?? 'list') === 'timeline')
        <div class="space-y-4">

          <div class="rounded-3xl border border-sky-500/15 bg-sky-500/5 p-4 text-sm text-white/65">
            <div class="text-white/85 font-semibold">Timeline (Month)</div>
            <div class="mt-1">
              Orders show on their <span class="text-sky-100/80">ship-by due date</span> (day-of-month).
              Use Channel + Status + Search to isolate workloads.
            </div>
          </div>

          <div class="grid grid-cols-7 gap-2 text-xs text-white/45 px-2">
            @foreach($daysOfWeek as $d)
              <div class="px-2">{{ $d }}</div>
            @endforeach
          </div>

          <div class="rounded-3xl border border-sky-500/15 bg-zinc-950/40 backdrop-blur overflow-hidden">
            <div class="grid grid-cols-7 gap-px bg-sky-400/10">
              @foreach($gridDays as $cursor)
                @php
                  $dayKey   = $cursor->toDateString();
                  $inMonth  = $cursor->month === $month->month;
                  $isToday  = $cursor->isToday();
                  $dayOrders = $byDay->get($dayKey, collect());
                @endphp

                <div class="bg-black/30 min-h-[150px] p-2">
                  <div class="flex items-center justify-between">
                    <div class="text-xs {{ $inMonth ? 'text-white/75' : 'text-white/30' }}">
                      {{ $cursor->day }}
                    </div>

                    @if($isToday)
                      <div class="text-[11px] px-2 py-0.5 rounded-full border border-sky-300/30 bg-sky-400/20 text-sky-50">
                        Today
                      </div>
                    @endif
                  </div>

                  <div class="mt-2 space-y-1.5">
                    @foreach($dayOrders->take(4) as $order)
                      @php
                        $isOpen = $expanded[$order->id] ?? false;
                        $lines = $order->lines ?? collect();
                        $qtyTotal = $lines->sum(fn ($l) => (int)(($l->ordered_qty ?? $l->quantity) ?? 0));
                        $statusLabel = $fmtStatus($order->status ?? null);

                        $titleLeft = $order->container_name ?? '—';
                        $titleRight = $order->customer_name ?? '—';
                        $sub = ($order->order_number ?? '—') . ' · ' . $qtyTotal . ' qty · ' . $statusLabel;
                      @endphp

                      <button type="button" wire:click="toggle({{ $order->id }})"
                        class="w-full text-left rounded-xl border border-sky-500/12 bg-sky-500/5 hover:bg-sky-500/10 px-2 py-1.5 transition">
                        <div class="flex items-center justify-between gap-2">
                          <div class="min-w-0">
                            <div class="text-xs text-white/90 truncate">
                              <span class="font-semibold">{{ $titleLeft }}</span>
                              <span class="text-white/25">·</span>
                              <span class="text-white/70">{{ $titleRight }}</span>
                            </div>
                            <div class="mt-0.5 text-[11px] text-white/45 truncate">{{ $sub }}</div>
                          </div>
                          <div class="text-sky-100/40">{{ $isOpen ? '▾' : '▸' }}</div>
                        </div>
                      </button>

                      @if($isOpen)
                        <div class="ml-2 mr-1 mb-2 mt-1 rounded-xl border border-sky-400/10 bg-black/20 overflow-hidden">
                          <div class="divide-y divide-sky-400/10">
                            @foreach($lines->take(6) as $line)
                              @php
                                $name = $line->scent?->name ?? $line->scent_name ?? ($line->name ?? 'Item');
                                $size = $line->size ? ($line->size->label ?: $line->size->code) : ($line->size_code ?? null);
                                $qty = (int) (($line->ordered_qty ?? $line->quantity) ?? 0);
                              @endphp
                              <div class="px-2 py-1 text-[11px] text-white/65 flex items-center justify-between gap-2">
                                <div class="truncate">
                                  {{ $name }}
                                  @if(!blank($size))
                                    <span class="text-white/35">·</span>
                                    <span class="text-white/50">{{ $size }}</span>
                                  @endif
                                </div>
                                <div class="shrink-0 text-white/70">× {{ $qty }}</div>
                              </div>
                            @endforeach

                            @if($lines->count() > 6)
                              <div class="px-2 py-1 text-[11px] text-white/45">
                                + {{ $lines->count() - 6 }} more…
                              </div>
                            @endif
                          </div>
                        </div>
                      @endif
                    @endforeach

                    @if($dayOrders->count() > 4)
                      <div class="text-[11px] text-white/45 px-2">
                        + {{ $dayOrders->count() - 4 }} more…
                      </div>
                    @endif
                  </div>
                </div>
              @endforeach
            </div>
          </div>

        </div>
      @endif

    </main>
  </div>
</div>
