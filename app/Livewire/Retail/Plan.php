<?php

namespace App\Livewire\Retail;

use App\Models\MarketPourList;
use App\Models\MarketPourListLine;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\RetailPlan;
use App\Models\RetailPlanItem;
use App\Models\Scent;
use App\Models\Size;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Component;

class Plan extends Component
{
    public RetailPlan $plan;
    public string $quote = '';
    public string $queue = 'retail';

    public ?int $inventoryScentId = null;
    public ?int $inventorySizeId = null;
    public int $inventoryQty = 1;
    public string $inventoryScentSearch = '';
    public string $inventorySizeSearch = '';

    protected $listeners = [
        'scentSelected' => 'handleScentSelected',
    ];

    protected $queryString = [
        'queue' => ['except' => 'retail'],
    ];

    public function mount(): void
    {
        $this->queue = $this->normalizeQueue($this->queue);

        $name = $this->queueDisplayName() . ' / Pour List ' . CarbonImmutable::now()->format('Y-m-d');
        $query = RetailPlan::query()
            ->whereDate('created_at', CarbonImmutable::today())
            ->whereNull('published_at');

        if ($this->supportsQueueTypeColumn()) {
            $query->where('queue_type', $this->queue);
        }

        $this->plan = $query->latest('id')->first()
            ?? $this->createDraftPlan($name);
        $this->quote = $this->enneagramQuote();

        if ($this->plan->items()->count() === 0) {
            $this->prefillFromOrdersInternal(true);
        }
    }

    public function prefillFromOrders(): void
    {
        $this->prefillFromOrdersInternal(false);
    }

    protected function prefillFromOrdersInternal(bool $silent): void
    {
        if ($this->queue === 'markets') {
            $this->prefillFromMarketDraftsInternal($silent);
            return;
        }

        $orders = Order::query()
            ->where('order_type', $this->orderTypeForQueue())
            ->whereNull('published_at')
            ->whereIn('status', ['new', 'reviewed'])
            ->with(['lines'])
            ->get();

        $added = 0;

        foreach ($orders as $order) {
            foreach ($order->lines as $line) {
                $qty = (int) ($line->ordered_qty ?? 0) + (int) ($line->extra_qty ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                RetailPlanItem::updateOrCreate(
                    [
                        'retail_plan_id' => $this->plan->id,
                        'order_line_id' => $line->id,
                    ],
                    [
                        'order_id' => $order->id,
                        'scent_id' => $line->scent_id,
                        'size_id' => $line->size_id,
                        'sku' => $line->sku,
                        'quantity' => $qty,
                        'source' => 'order',
                        'status' => ($line->scent_id && $line->size_id) ? 'draft' : 'needs_mapping',
                    ]
                );

                $added++;
            }
        }

        if (!$silent) {
            $this->dispatch('toast', [
                'type' => 'success',
                'message' => $added > 0
                    ? "Prefilled {$added} ".strtolower($this->queueDisplayName()).' lines.'
                    : 'No new '.strtolower($this->queueDisplayName()).' lines to add.',
            ]);
        }
    }

    protected function prefillFromMarketDraftsInternal(bool $silent): void
    {
        $drafts = MarketPourList::query()
            ->where('status', 'draft')
            ->with(['event:id,name,starts_at', 'lines'])
            ->orderByDesc('id')
            ->get();

        $added = 0;

        foreach ($drafts as $draft) {
            foreach ($draft->lines as $line) {
                $qty = (int) ($line->edited_qty ?? $line->recommended_qty ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                [$scentId, $sizeId, $sku] = $this->resolveMarketDraftLineMapping($line, $draft);
                $rawReason = is_array($line->reason_json) ? $line->reason_json : [];
                $splitScentIds = $scentId
                    ? []
                    : $this->resolveSplitScentIdsFromText((string) ($rawReason['scent'] ?? ''));

                if (count($splitScentIds) === 2) {
                    // A "ABC / XYZ" market box note means one full box split across two scents.
                    // Store each split scent as half-box units (1 = half box).
                    RetailPlanItem::query()
                        ->where('retail_plan_id', $this->plan->id)
                        ->where('source', 'market_box_draft')
                        ->where('sku', $sku)
                        ->delete();

                    foreach (array_values($splitScentIds) as $index => $splitScentId) {
                        $item = RetailPlanItem::query()->updateOrCreate(
                            [
                                'retail_plan_id' => $this->plan->id,
                                'source' => 'market_box_draft',
                                'sku' => $sku.'#split:'.($index + 1),
                            ],
                            [
                                'order_id' => null,
                                'order_line_id' => null,
                                'scent_id' => $splitScentId,
                                'size_id' => $sizeId,
                                'quantity' => max(1, $qty),
                                'status' => 'draft',
                            ]
                        );

                        if ($item->wasRecentlyCreated) {
                            $added++;
                        }
                    }

                    continue;
                }

                if (!$scentId && str_starts_with($sku, 'mktpl:')) {
                    // No usable scent metadata on this draft line; keep it in the draft editor,
                    // but skip the markets planner row until it can be mapped by a human.
                    continue;
                }

                $item = RetailPlanItem::query()->updateOrCreate(
                    [
                        'retail_plan_id' => $this->plan->id,
                        'source' => 'market_box_draft',
                        'sku' => $sku,
                    ],
                    [
                        'order_id' => null,
                        'order_line_id' => null,
                        'scent_id' => $scentId,
                        'size_id' => $sizeId,
                        // Store market-box quantities in half-box units (1=half, 2=full).
                        'quantity' => max(1, $qty * 2),
                        'status' => $scentId ? 'draft' : 'needs_mapping',
                    ]
                );

                if ($item->wasRecentlyCreated) {
                    $added++;
                }
            }
        }

        if (!$silent) {
            $this->dispatch('toast', [
                'type' => 'success',
                'message' => $added > 0
                    ? "Prefilled {$added} market box scents from drafts."
                    : 'No new markets draft lines to add.',
            ]);
        }
    }

    public function addInventoryItem(): void
    {
        if ($this->queue === 'markets') {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => 'Use Half Box or Full Box for market scents.',
            ]);
            return;
        }

        $this->validate([
            'inventoryScentId' => 'required|exists:scents,id',
            'inventorySizeId' => 'required|exists:sizes,id',
            'inventoryQty' => 'required|integer|min:1',
        ]);

        RetailPlanItem::create([
            'retail_plan_id' => $this->plan->id,
            'scent_id' => $this->inventoryScentId,
            'size_id' => $this->inventorySizeId,
            'quantity' => $this->inventoryQty,
            'source' => 'inventory',
            'status' => 'draft',
        ]);

        $this->inventoryScentId = null;
        $this->inventorySizeId = null;
        $this->inventoryQty = 1;
        $this->inventoryScentSearch = '';
        $this->inventorySizeSearch = '';

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Inventory item added to plan.',
        ]);
    }

    public function addMarketHalfBox(): void
    {
        $this->addMarketBoxUnits(1);
    }

    public function addMarketFullBox(): void
    {
        $this->addMarketBoxUnits(2);
    }

    protected function addMarketBoxUnits(int $halfBoxUnits): void
    {
        if ($this->queue !== 'markets') {
            return;
        }

        $this->validate([
            'inventoryScentId' => 'required|exists:scents,id',
        ]);

        RetailPlanItem::create([
            'retail_plan_id' => $this->plan->id,
            'scent_id' => $this->inventoryScentId,
            'size_id' => null,
            'quantity' => max(1, $halfBoxUnits),
            'source' => 'market_box_manual',
            'status' => 'draft',
            'sku' => 'market-box',
        ]);

        $this->inventoryScentId = null;
        $this->inventoryScentSearch = '';

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Market box scent added to plan.',
        ]);
    }

    public function handleScentSelected(string $key, ?int $scentId = null, ?string $scentName = null): void
    {
        if ($key !== 'retail-plan') {
            return;
        }

        $this->inventoryScentId = $scentId;
        $this->inventoryScentSearch = $scentName ?? '';
    }

    public function selectInventorySize(): void
    {
        $text = trim($this->inventorySizeSearch);
        if ($text === '') {
            $this->inventorySizeId = null;
            return;
        }

        $size = Size::query()
            ->where('code', $text)
            ->orWhere('label', $text)
            ->first();
        $this->inventorySizeId = $size?->id;
    }

    public function removeItem(int $itemId): void
    {
        $item = RetailPlanItem::query()
            ->where('retail_plan_id', $this->plan->id)
            ->findOrFail($itemId);

        $item->delete();

        $this->dispatch('toast', [
            'type' => 'warning',
            'message' => 'Item removed from plan.',
        ]);
    }

    public function updateItemQuantity(int $itemId, int $quantity): void
    {
        $quantity = max(1, $quantity);
        $item = RetailPlanItem::query()
            ->where('retail_plan_id', $this->plan->id)
            ->findOrFail($itemId);
        $item->quantity = $quantity;
        $item->save();
    }

    public function updateItemInventoryQuantity(int $itemId, int $quantity): void
    {
        $quantity = max(0, $quantity);
        $item = RetailPlanItem::query()
            ->where('retail_plan_id', $this->plan->id)
            ->findOrFail($itemId);
        $item->inventory_quantity = $quantity;
        $item->save();
    }

    public function incrementItemQuantity(int $itemId): void
    {
        $item = RetailPlanItem::query()
            ->where('retail_plan_id', $this->plan->id)
            ->findOrFail($itemId);
        $item->quantity = max(1, (int) $item->quantity + 1);
        $item->save();
    }

    public function decrementItemQuantity(int $itemId): void
    {
        $item = RetailPlanItem::query()
            ->where('retail_plan_id', $this->plan->id)
            ->findOrFail($itemId);
        $item->quantity = max(1, (int) $item->quantity - 1);
        $item->save();
    }

    public function marketBoxLabel(RetailPlanItem $item): string
    {
        $units = max(1, (int) ($item->quantity ?? 0));
        $boxes = $units / 2;

        if (fmod($boxes, 1.0) === 0.0) {
            $value = (string) (int) $boxes;
        } else {
            $value = number_format($boxes, 1);
        }

        return $value.' '.(((float) $boxes) === 1.0 ? 'box' : 'boxes');
    }

    public function incrementItemInventoryQuantity(int $itemId): void
    {
        $item = RetailPlanItem::query()
            ->where('retail_plan_id', $this->plan->id)
            ->findOrFail($itemId);
        $item->inventory_quantity = max(0, (int) $item->inventory_quantity + 1);
        $item->save();
    }

    public function decrementItemInventoryQuantity(int $itemId): void
    {
        $item = RetailPlanItem::query()
            ->where('retail_plan_id', $this->plan->id)
            ->findOrFail($itemId);
        $item->inventory_quantity = max(0, (int) $item->inventory_quantity - 1);
        $item->save();
    }

    public function clearScents(): void
    {
        RetailPlanItem::query()
            ->where('retail_plan_id', $this->plan->id)
            ->delete();

        $this->dispatch('toast', [
            'type' => 'warning',
            'message' => $this->queueDisplayName().' list cleared.',
        ]);
    }

    public function publishPlan(): void
    {
        if ($this->plan->published_at) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => 'This '.$this->queueDisplayName().' list is already published.',
            ]);
            return;
        }

        DB::transaction(function () {
            $this->plan->status = 'published';
            $this->plan->published_at = now();
            $this->plan->save();

            $items = $this->plan->items()->get();
            foreach ($items as $item) {
                if ($item->order_id || $item->order_line_id) {
                    $order = $item->order_id
                        ? Order::query()->find($item->order_id)
                        : OrderLine::query()->find($item->order_line_id)?->order;

                    if ($order) {
                        if (in_array($order->status, ['new', 'reviewed'], true)) {
                            $order->status = 'submitted_to_pouring';
                        }
                        if (!$order->published_at) {
                            $order->published_at = now();
                        }
                        $order->save();
                    }

                    $item->status = 'published';
                    $item->save();
                }
            }

            if ($this->queue === 'markets') {
                $marketBoxItems = $items->whereIn('source', ['market_box_draft', 'market_box_manual']);
                $publishableBoxes = $marketBoxItems->filter(fn ($item) => (int) ($item->scent_id ?? 0) > 0 && (int) ($item->quantity ?? 0) > 0);

                if ($publishableBoxes->isNotEmpty()) {
                    $today = CarbonImmutable::today();
                    $marketOrder = Order::query()->create([
                        'order_type' => 'event',
                        'order_label' => 'Markets Box Plan',
                        'order_number' => 'MKT-BOX-' . $today->format('Ymd'),
                        'source' => 'internal',
                        'status' => 'submitted_to_pouring',
                        'ordered_at' => now(),
                        'ship_by_at' => $today,
                        'due_at' => $today,
                        'published_at' => now(),
                    ]);

                    [$size16CottonId, $size8CottonId, $sizeWaxMeltId] = $this->marketBoxSizeIds();

                    foreach ($publishableBoxes as $item) {
                        $halfBoxUnits = max(1, (int) $item->quantity);
                        $scentId = (int) $item->scent_id;

                        // One full box = 4x16oz cotton, 8x8oz cotton, 8x wax melts
                        // One half box = 2x16oz cotton, 4x8oz cotton, 4x wax melts
                        $this->createMarketBoxOrderLine($marketOrder->id, $scentId, $size16CottonId, $halfBoxUnits * 2);
                        $this->createMarketBoxOrderLine($marketOrder->id, $scentId, $size8CottonId, $halfBoxUnits * 4);
                        $this->createMarketBoxOrderLine($marketOrder->id, $scentId, $sizeWaxMeltId, $halfBoxUnits * 4);

                        $item->order_id = $marketOrder->id;
                        $item->status = 'published';
                        $item->save();
                    }
                }
            }

            $inventoryItems = $items->where('source', 'inventory');
            $inventoryExtras = $items->filter(fn ($item) => (int) ($item->inventory_quantity ?? 0) > 0);

            if ($inventoryItems->isNotEmpty() || $inventoryExtras->isNotEmpty()) {
                $today = CarbonImmutable::today();

                $inventoryOrder = Order::query()->create([
                    'order_type' => $this->orderTypeForQueue(),
                    'order_label' => $this->queueDisplayName() . ' Inventory',
                    'order_number' => $this->inventoryOrderNumberPrefix() . $today->format('Ymd'),
                    'source' => 'internal',
                    'status' => 'submitted_to_pouring',
                    'ordered_at' => now(),
                    'ship_by_at' => $today,
                    'due_at' => $today,
                    'published_at' => now(),
                ]);

                foreach ($inventoryItems as $item) {
                    $sizeCode = Size::query()->find($item->size_id)?->code;

                    OrderLine::query()->create([
                        'order_id' => $inventoryOrder->id,
                        'scent_id' => $item->scent_id,
                        'size_id' => $item->size_id,
                        'size_code' => $sizeCode,
                        'ordered_qty' => $item->quantity,
                        'extra_qty' => 0,
                    ]);

                    $item->order_id = $inventoryOrder->id;
                    $item->status = 'published';
                    $item->save();
                }

                foreach ($inventoryExtras as $item) {
                    $sizeCode = Size::query()->find($item->size_id)?->code;

                    OrderLine::query()->create([
                        'order_id' => $inventoryOrder->id,
                        'scent_id' => $item->scent_id,
                        'size_id' => $item->size_id,
                        'size_code' => $sizeCode,
                        'ordered_qty' => (int) $item->inventory_quantity,
                        'extra_qty' => 0,
                    ]);

                    $item->status = 'published';
                    $item->save();
                }
            }
        }, 3);

        $this->plan = $this->createDraftPlan(
            $this->queueDisplayName() . ' / Pour List ' . CarbonImmutable::now()->format('Y-m-d H:i')
        );
        $this->prefillFromOrdersInternal(true);

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => $this->queueDisplayName().'/Pour list sent to Pouring Room. Candles-to-pour list cleared for the next batch.',
        ]);
        $this->dispatch('retail-plan-published', [
            'pegasus_gif' => asset('images/pegasus.gif'),
        ]);
    }

    public function render()
    {
        $items = $this->plan->items()
            ->where('status', '!=', 'published')
            ->orderBy('source')
            ->get();
        $sizeQuery = trim($this->inventorySizeSearch);
        $marketSourceLabels = [];

        $scents = Scent::query()
            ->orderBy('name')
            ->get();

        if ($this->queue === 'markets') {
            $draftIds = $items
                ->map(function ($item) {
                    if (preg_match('/^mktpl:(\d+):\d+(?:#split:\d+)?$/', (string) ($item->sku ?? ''), $m)) {
                        return (int) $m[1];
                    }

                    return null;
                })
                ->filter()
                ->unique()
                ->values();

            $draftsById = MarketPourList::query()
                ->with(['event:id,market_id,name,starts_at', 'event.market:id,name'])
                ->whereIn('id', $draftIds)
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                if (!preg_match('/^mktpl:(\d+):\d+(?:#split:\d+)?$/', (string) ($item->sku ?? ''), $m)) {
                    continue;
                }
                $draft = $draftsById->get((int) $m[1]);
                $event = $draft?->event;
                $marketSourceLabels[$item->id] = $event?->market?->name ?: $event?->name;
            }

            $items = $items->sortBy(function ($item) use ($draftsById, $scents) {
                $draftId = null;
                if (preg_match('/^mktpl:(\d+):\d+(?:#split:\d+)?$/', (string) ($item->sku ?? ''), $m)) {
                    $draftId = (int) $m[1];
                }

                $draft = $draftId ? $draftsById->get($draftId) : null;
                $event = $draft?->event;
                $eventDate = $event?->starts_at ? (string) $event->starts_at : '9999-12-31';
                $eventName = Str::lower(trim((string) ($event?->name ?? 'zzzz manual market box')));
                $scentName = Str::lower(trim((string) (
                    $scents->firstWhere('id', $item->scent_id)?->display_name
                    ?? $scents->firstWhere('id', $item->scent_id)?->name
                    ?? $item->sku
                    ?? 'zzz'
                )));

                return [$eventDate, $eventName, $scentName, (int) $item->id];
            })->values();
        }

        $sizes = Size::query()
            ->when($sizeQuery !== '', function ($q) use ($sizeQuery) {
                $q->where('code', 'like', '%'.$sizeQuery.'%')
                  ->orWhere('label', 'like', '%'.$sizeQuery.'%');
            })
            ->orderBy('label')
            ->orderBy('code')
            ->limit(30)
            ->get();

        return view('livewire.retail.plan', [
            'items' => $items,
            'scents' => $scents,
            'sizes' => $sizes,
            'quote' => $this->quote,
            'queueMeta' => $this->queueMeta(),
            'marketSourceLabels' => $marketSourceLabels,
        ]);
    }

    protected function createDraftPlan(string $name): RetailPlan
    {
        $payload = [
            'name' => $name,
            'status' => 'draft',
            'created_by' => auth()->id(),
        ];

        if ($this->supportsQueueTypeColumn()) {
            $payload['queue_type'] = $this->queue;
        }

        return RetailPlan::query()->create($payload);
    }

    protected function supportsQueueTypeColumn(): bool
    {
        static $supports = null;

        if ($supports === null) {
            $supports = Schema::hasColumn('retail_plans', 'queue_type');
        }

        return $supports;
    }

    protected function normalizeQueue(string $queue): string
    {
        $queue = strtolower(trim($queue));

        return in_array($queue, ['retail', 'wholesale', 'markets'], true)
            ? $queue
            : 'retail';
    }

    protected function orderTypeForQueue(): string
    {
        return match ($this->queue) {
            'wholesale' => 'wholesale',
            'markets' => 'event',
            default => 'retail',
        };
    }

    protected function inventoryOrderNumberPrefix(): string
    {
        return match ($this->queue) {
            'wholesale' => 'WHL-INV-',
            'markets' => 'MKT-INV-',
            default => 'RET-INV-',
        };
    }

    protected function queueDisplayName(): string
    {
        return match ($this->queue) {
            'wholesale' => 'Wholesale',
            'markets' => 'Markets',
            default => 'Retail',
        };
    }

    protected function queueMeta(): array
    {
        $queue = $this->queueDisplayName();
        $prefillLabel = match ($this->queue) {
            'wholesale' => 'Prefill from Wholesale Orders',
            'markets' => 'Prefill from Market Drafts',
            default => 'Prefill from Retail Orders',
        };

        $emptyLabel = match ($this->queue) {
            'markets' => 'No items yet. Prefill from market drafts or add inventory below.',
            'wholesale' => 'No items yet. Prefill from wholesale orders or add inventory below.',
            default => 'No items yet. Prefill from retail orders or add inventory below.',
        };

        return [
            'key' => $this->queue,
            'title' => $queue.'/Pour List',
            'subtitle' => 'Draft list for today. Publish to push to the pouring room.',
            'prefill_label' => $prefillLabel,
            'add_button_label' => 'Add to '.$queue.'/Pour List',
            'empty_label' => $emptyLabel,
            'markets_help' => $this->queue === 'markets',
        ];
    }

    protected function resolveMarketDraftLineMapping(MarketPourListLine $line, MarketPourList $draft): array
    {
        $reason = is_array($line->reason_json) ? $line->reason_json : [];

        $scentId = $line->scent_id ?: $this->resolveScentIdFromText((string) ($reason['scent'] ?? ''));
        $sizeId = $line->size_id ?: $this->resolveSizeIdFromText((string) ($reason['size'] ?? ''));

        $sku = 'mktpl:'.$draft->id.':'.$line->id;

        // Preserve a human-readable identifier when mapping is incomplete.
        if (!$scentId || !$sizeId) {
            $parts = array_values(array_filter([
                trim((string) ($reason['product_key'] ?? '')),
                trim((string) ($reason['scent'] ?? '')),
                trim((string) ($reason['size'] ?? '')),
            ]));

            if ($parts !== []) {
                $sku = implode(' | ', $parts);
            }
        }

        return [$scentId ?: null, $sizeId ?: null, Str::limit($sku, 255, '')];
    }

    protected function resolveScentIdFromText(string $scent): ?int
    {
        return $this->resolveSingleScentIdFromText($scent);
    }

    /**
     * @return array<int,int>
     */
    protected function resolveSplitScentIdsFromText(string $scent): array
    {
        $scent = trim($scent);
        if ($scent === '' || !str_contains($scent, '/')) {
            return [];
        }

        $parts = array_values(array_filter(array_map('trim', explode('/', $scent))));
        if (count($parts) !== 2) {
            return [];
        }

        $ids = [];
        foreach ($parts as $part) {
            $id = $this->resolveSingleScentIdFromText($part);
            if (!$id) {
                return [];
            }
            $ids[] = (int) $id;
        }

        $ids = array_values(array_unique($ids));

        return count($ids) === 2 ? $ids : [];
    }

    protected function resolveSingleScentIdFromText(string $scent): ?int
    {
        $scent = trim($scent);
        if ($scent === '') {
            return null;
        }

        $normalized = Scent::normalizeName($scent);

        $match = Scent::query()
            ->where('name', $scent)
            ->orWhere('display_name', $scent)
            ->orWhere('abbreviation', $scent)
            ->get(['id', 'name', 'display_name', 'abbreviation'])
            ->first();

        if ($match) {
            return (int) $match->id;
        }

        return Scent::query()
            ->get(['id', 'name', 'display_name', 'abbreviation'])
            ->first(function (Scent $candidate) use ($normalized) {
                $names = array_filter([
                    $candidate->name,
                    $candidate->display_name,
                    $candidate->abbreviation,
                ]);

                foreach ($names as $name) {
                    if (Scent::normalizeName((string) $name) === $normalized) {
                        return true;
                    }
                }

                return false;
            })?->id;
    }

    protected function resolveSizeIdFromText(string $size): ?int
    {
        $size = trim($size);
        if ($size === '') {
            return null;
        }

        return Size::query()
            ->where('code', $size)
            ->orWhere('label', $size)
            ->first()?->id;
    }

    protected function marketBoxSizeIds(): array
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $sizes = Size::query()->get(['id', 'code', 'label']);
        $findByContains = function (array $needles) use ($sizes): ?int {
            foreach ($sizes as $size) {
                $haystack = Str::lower(trim(($size->code ?? '').' '.($size->label ?? '')));
                foreach ($needles as $needle) {
                    if (str_contains($haystack, Str::lower($needle))) {
                        return (int) $size->id;
                    }
                }
            }

            return null;
        };

        $cached = [
            $findByContains(['16oz-cotton', '16 oz cotton', '16oz cotton wick']),
            $findByContains(['8oz-cotton', '8 oz cotton', '8oz cotton wick']),
            $findByContains(['wax-melts', 'wax melts', 'wax melt']),
        ];

        return $cached;
    }

    protected function createMarketBoxOrderLine(int $orderId, int $scentId, ?int $sizeId, int $qty): void
    {
        if (!$sizeId || $qty <= 0) {
            return;
        }

        $size = Size::query()->find($sizeId);

        OrderLine::query()->create([
            'order_id' => $orderId,
            'scent_id' => $scentId,
            'size_id' => $sizeId,
            'size_code' => $size?->code,
            'ordered_qty' => $qty,
            'extra_qty' => 0,
            'quantity' => $qty,
            'wick_type' => str_contains((string) ($size?->code ?? ''), 'cotton') ? 'cotton' : null,
        ]);
    }

    protected function enneagramQuote(): string
    {
        $quotes = [
            'Respect is earned. So are clean pours.',
            'Don’t negotiate with sloppy wicks.',
            'Kindness is a choice. Precision is a requirement.',
            'Move fast. Pour clean.',
            'No excuses. Only outcomes.',
        ];

        return $quotes[array_rand($quotes)];
    }
}
