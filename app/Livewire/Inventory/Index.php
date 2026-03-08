<?php

namespace App\Livewire\Inventory;

use App\Actions\Inventory\AdjustInventoryAction;
use App\Models\BaseOil;
use App\Models\InventoryAdjustment;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\InventoryCount;
use App\Models\Size;
use App\Models\WaxInventory;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\WaxConversionService;
use Livewire\Component;

class Index extends Component
{
    public string $search = '';
    public string $materialSearch = '';
    public array $onHand = [];
    public array $targetOnHandOil = [];
    public array $targetOnHandWax = [];
    public array $adjustDeltaOil = [];
    public array $adjustDeltaWax = [];
    public array $adjustReasonOil = [];
    public array $adjustReasonWax = [];
    public array $adjustNotesOil = [];
    public array $adjustNotesWax = [];
    public array $thresholdOil = [];
    public array $thresholdWax = [];
    public ?string $statusMessage = null;
    public string $statusLevel = 'info';

    public function mount(): void
    {
        $this->onHand = InventoryCount::query()
            ->get()
            ->mapWithKeys(function (InventoryCount $row) {
                $key = $row->scent_id . ':' . ($row->size_id ?? 'null');
                return [$key => $row->on_hand_qty];
            })
            ->all();

        $this->seedMaterialDrafts();
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

    public function setOilOnHand(int $oilId): void
    {
        $target = (float) ($this->targetOnHandOil[$oilId] ?? 0);
        $target = max(0.0, $target);

        $adjustment = app(AdjustInventoryAction::class)->setOilOnHand(
            oil: $oilId,
            targetOnHand: $target,
            reason: InventoryAdjustment::REASON_MANUAL_CORRECTION,
            notes: 'Manual set from inventory screen',
            performedBy: auth()->id()
        );

        if (! $adjustment) {
            $this->flashStatus('No oil inventory change was needed.', 'info');
            return;
        }

        $this->targetOnHandOil[$oilId] = (float) $adjustment->after_grams;
        $this->flashStatus('Oil on-hand updated and logged.', 'success');
    }

    public function setWaxOnHand(int $waxId): void
    {
        $target = (float) ($this->targetOnHandWax[$waxId] ?? 0);
        $target = max(0.0, $target);

        $adjustment = app(AdjustInventoryAction::class)->setWaxOnHand(
            wax: $waxId,
            targetOnHand: $target,
            reason: InventoryAdjustment::REASON_MANUAL_CORRECTION,
            notes: 'Manual set from inventory screen',
            performedBy: auth()->id()
        );

        if (! $adjustment) {
            $this->flashStatus('No wax inventory change was needed.', 'info');
            return;
        }

        $this->targetOnHandWax[$waxId] = (float) $adjustment->after_grams;
        $this->flashStatus('Wax on-hand updated and logged.', 'success');
    }

    public function applyOilAdjustment(int $oilId): void
    {
        $delta = (float) ($this->adjustDeltaOil[$oilId] ?? 0);
        $reason = (string) ($this->adjustReasonOil[$oilId] ?? InventoryAdjustment::REASON_MANUAL_CORRECTION);
        $notes = trim((string) ($this->adjustNotesOil[$oilId] ?? ''));

        $adjustment = app(AdjustInventoryAction::class)->adjustOil(
            oil: $oilId,
            gramsDelta: $delta,
            reason: $reason,
            notes: $notes !== '' ? $notes : null,
            performedBy: auth()->id()
        );

        if (! $adjustment) {
            $this->flashStatus('No oil adjustment was applied.', 'info');
            return;
        }

        $this->adjustDeltaOil[$oilId] = 0;
        $this->adjustNotesOil[$oilId] = '';
        $this->targetOnHandOil[$oilId] = (float) $adjustment->after_grams;
        $this->flashStatus('Oil adjustment recorded.', 'success');
    }

    public function applyWaxAdjustment(int $waxId): void
    {
        $delta = (float) ($this->adjustDeltaWax[$waxId] ?? 0);
        $reason = (string) ($this->adjustReasonWax[$waxId] ?? InventoryAdjustment::REASON_MANUAL_CORRECTION);
        $notes = trim((string) ($this->adjustNotesWax[$waxId] ?? ''));

        $adjustment = app(AdjustInventoryAction::class)->adjustWax(
            wax: $waxId,
            gramsDelta: $delta,
            reason: $reason,
            notes: $notes !== '' ? $notes : null,
            performedBy: auth()->id()
        );

        if (! $adjustment) {
            $this->flashStatus('No wax adjustment was applied.', 'info');
            return;
        }

        $this->adjustDeltaWax[$waxId] = 0;
        $this->adjustNotesWax[$waxId] = '';
        $this->targetOnHandWax[$waxId] = (float) $adjustment->after_grams;
        $this->flashStatus('Wax adjustment recorded.', 'success');
    }

    public function saveOilThreshold(int $oilId): void
    {
        $value = max(0.0, (float) ($this->thresholdOil[$oilId] ?? 0));
        BaseOil::query()->whereKey($oilId)->update(['reorder_threshold' => $value]);
        $this->flashStatus('Oil reorder threshold updated.', 'success');
    }

    public function saveWaxThreshold(int $waxId): void
    {
        $value = max(0.0, (float) ($this->thresholdWax[$waxId] ?? 0));
        WaxInventory::query()->whereKey($waxId)->update(['reorder_threshold_grams' => $value]);
        $this->flashStatus('Wax reorder threshold updated.', 'success');
    }

    public function clearStatusMessage(): void
    {
        $this->statusMessage = null;
        $this->statusLevel = 'info';
    }

    public function render()
    {
        /** @var InventoryService $inventory */
        $inventory = app(InventoryService::class);
        $inventory->ensureDefaultWaxRow();

        $oilRows = $inventory->oilRows($this->materialSearch);
        $waxRows = $inventory->waxRows($this->materialSearch);
        $recentAdjustments = $inventory->recentAdjustments(30);
        $waxConversion = app(WaxConversionService::class);

        foreach ($oilRows as $row) {
            $id = (int) $row['id'];
            $this->targetOnHandOil[$id] ??= (float) $row['on_hand_grams'];
            $this->thresholdOil[$id] ??= (float) $row['reorder_threshold_grams'];
            $this->adjustDeltaOil[$id] ??= 0;
            $this->adjustReasonOil[$id] ??= InventoryAdjustment::REASON_MANUAL_CORRECTION;
            $this->adjustNotesOil[$id] ??= '';
        }

        foreach ($waxRows as $row) {
            $id = (int) $row['id'];
            $this->targetOnHandWax[$id] ??= (float) $row['on_hand_grams'];
            $this->thresholdWax[$id] ??= (float) $row['reorder_threshold_grams'];
            $this->adjustDeltaWax[$id] ??= 0;
            $this->adjustReasonWax[$id] ??= InventoryAdjustment::REASON_MANUAL_CORRECTION;
            $this->adjustNotesWax[$id] ??= '';
        }

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
            'oilRows' => $oilRows,
            'waxRows' => $waxRows,
            'recentAdjustments' => $recentAdjustments,
            'adjustmentReasons' => InventoryAdjustment::reasons(),
            'waxDefaultThreshold' => $waxConversion->defaultWaxReorderThresholdGrams(),
        ]);
    }

    protected function seedMaterialDrafts(): void
    {
        /** @var InventoryService $inventory */
        $inventory = app(InventoryService::class);
        $inventory->ensureDefaultWaxRow();

        foreach ($inventory->oilRows() as $row) {
            $id = (int) $row['id'];
            $this->targetOnHandOil[$id] = (float) $row['on_hand_grams'];
            $this->thresholdOil[$id] = (float) $row['reorder_threshold_grams'];
            $this->adjustDeltaOil[$id] = 0;
            $this->adjustReasonOil[$id] = InventoryAdjustment::REASON_MANUAL_CORRECTION;
            $this->adjustNotesOil[$id] = '';
        }

        foreach ($inventory->waxRows() as $row) {
            $id = (int) $row['id'];
            $this->targetOnHandWax[$id] = (float) $row['on_hand_grams'];
            $this->thresholdWax[$id] = (float) $row['reorder_threshold_grams'];
            $this->adjustDeltaWax[$id] = 0;
            $this->adjustReasonWax[$id] = InventoryAdjustment::REASON_MANUAL_CORRECTION;
            $this->adjustNotesWax[$id] = '';
        }
    }

    protected function flashStatus(string $message, string $level = 'info'): void
    {
        $this->statusMessage = $message;
        $this->statusLevel = $level;
    }
}
