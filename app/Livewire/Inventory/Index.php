<?php

namespace App\Livewire\Inventory;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\InventoryCount;
use App\Models\Size;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    public string $search = '';
    public array $onHand = [];

    public function mount(): void
    {
        $this->onHand = InventoryCount::query()
            ->get()
            ->mapWithKeys(function (InventoryCount $row) {
                $key = $row->scent_id . ':' . ($row->size_id ?? 'null');
                return [$key => $row->on_hand_qty];
            })
            ->all();
    }

    public function updateOnHand(int $scentId, ?int $sizeId, int $qty): void
    {
        $qty = max(0, $qty);
        InventoryCount::query()->updateOrCreate(
            ['scent_id' => $scentId, 'size_id' => $sizeId],
            ['on_hand_qty' => $qty]
        );
        $key = $scentId . ':' . ($sizeId ?? 'null');
        $this->onHand[$key] = $qty;
    }

    public function render()
    {
        $inventoryOrderIds = Order::query()
            ->where('order_label', 'Retail Inventory')
            ->pluck('id')
            ->all();

        $pours = OrderLine::query()
            ->whereIn('order_id', $inventoryOrderIds)
            ->selectRaw('scent_id, size_id, SUM(ordered_qty) as qty')
            ->groupBy('scent_id', 'size_id')
            ->get()
            ->keyBy(fn ($row) => $row->scent_id . ':' . ($row->size_id ?? 'null'));

        $sizes = Size::query()->select(['id', 'label', 'code'])->get()->keyBy('id');

        $scents = Scent::query()
            ->when($this->search !== '', function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                  ->orWhere('display_name', 'like', '%'.$this->search.'%');
            })
            ->orderBy('name')
            ->get()
            ->flatMap(function (Scent $scent) use ($pours, $sizes) {
                $rows = [];
                $matched = false;
                foreach ($pours as $key => $row) {
                    if ((int) $row->scent_id !== $scent->id) {
                        continue;
                    }
                    $matched = true;
                    $size = $sizes[$row->size_id] ?? null;
                    $rows[] = [
                        'id' => $scent->id,
                        'size_id' => $row->size_id,
                        'name' => $scent->display_name ?? $scent->name,
                        'size' => $size?->label ?? $size?->code ?? '—',
                        'qty' => (int) $row->qty,
                        'on_hand' => (int) ($this->onHand[$key] ?? 0),
                    ];
                }
                if (!$matched) {
                    $rows[] = [
                        'id' => $scent->id,
                        'size_id' => null,
                        'name' => $scent->display_name ?? $scent->name,
                        'size' => '—',
                        'qty' => 0,
                        'on_hand' => (int) ($this->onHand[$scent->id . ':null'] ?? 0),
                    ];
                }
                return $rows;
            });

        return view('livewire.inventory.index', [
            'scents' => $scents,
        ]);
    }
}
