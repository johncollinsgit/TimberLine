<?php

namespace App\Livewire\Retail;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\RetailPlan;
use App\Models\RetailPlanItem;
use App\Models\Scent;
use App\Models\Size;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function addInventoryItem(): void
    {
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

        $scents = Scent::query()
            ->orderBy('name')
            ->get();

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
            'markets' => 'Prefill from Market Orders',
            default => 'Prefill from Retail Orders',
        };

        return [
            'key' => $this->queue,
            'title' => $queue.'/Pour List',
            'subtitle' => 'Draft list for today. Publish to push to the pouring room.',
            'prefill_label' => $prefillLabel,
            'add_button_label' => 'Add to '.$queue.'/Pour List',
            'empty_label' => 'No items yet. Prefill from '.strtolower($queue).' orders or add inventory below.',
            'markets_help' => $this->queue === 'markets',
        ];
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
