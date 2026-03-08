<?php

namespace App\Services\Inventory;

use App\Models\BaseOil;
use App\Models\InventoryAdjustment;
use App\Models\WaxInventory;
use Illuminate\Support\Collection;

class InventoryService
{
    public function __construct(
        protected WaxConversionService $waxConversion
    ) {}

    public function ensureDefaultWaxRow(): void
    {
        if (WaxInventory::query()->whereRaw('lower(name) = ?', ['candle wax'])->exists()) {
            return;
        }

        WaxInventory::query()->create([
            'name' => 'Candle Wax',
            'on_hand_grams' => 0,
            'reorder_threshold_grams' => $this->waxConversion->defaultWaxReorderThresholdGrams(),
            'active' => true,
            'notes' => 'Default wax inventory row.',
        ]);
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function oilRows(?string $search = null, int $limit = 200): Collection
    {
        $query = BaseOil::query()
            ->when(($search = trim((string) $search)) !== '', function ($builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('supplier', 'like', '%'.$search.'%');
            })
            ->orderBy('name')
            ->limit(max(10, $limit));

        return $query->get()->map(function (BaseOil $oil): array {
            $onHand = (float) $oil->grams_on_hand;
            $threshold = (float) $oil->reorder_threshold;
            $state = $this->evaluateReorderState($onHand, $threshold);

            return [
                'id' => (int) $oil->id,
                'name' => (string) $oil->name,
                'on_hand_grams' => round($onHand, 2),
                'reorder_threshold_grams' => round($threshold, 2),
                'state' => $state,
                'supplier' => $oil->supplier,
                'active' => (bool) $oil->active,
            ];
        });
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function waxRows(?string $search = null, int $limit = 50): Collection
    {
        $this->ensureDefaultWaxRow();

        $query = WaxInventory::query()
            ->where('active', true)
            ->when(($search = trim((string) $search)) !== '', function ($builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%');
            })
            ->orderBy('name')
            ->limit(max(10, $limit));

        return $query->get()->map(function (WaxInventory $wax): array {
            $onHand = (float) $wax->on_hand_grams;
            $threshold = (float) $wax->reorder_threshold_grams;
            $state = $this->evaluateReorderState($onHand, $threshold);

            return [
                'id' => (int) $wax->id,
                'name' => (string) $wax->name,
                'on_hand_grams' => round($onHand, 2),
                'on_hand_pounds' => $this->waxConversion->gramsToPounds($onHand),
                'on_hand_boxes' => $this->waxConversion->gramsToBoxes($onHand),
                'reorder_threshold_grams' => round($threshold, 2),
                'reorder_threshold_pounds' => $this->waxConversion->gramsToPounds($threshold),
                'reorder_threshold_boxes' => $this->waxConversion->gramsToBoxes($threshold),
                'state' => $state,
                'active' => (bool) $wax->active,
            ];
        });
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function recentAdjustments(int $limit = 30): Collection
    {
        return InventoryAdjustment::query()
            ->with(['baseOil:id,name', 'waxInventory:id,name'])
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(function (InventoryAdjustment $adjustment): array {
                $itemName = $adjustment->item_type === InventoryAdjustment::ITEM_TYPE_OIL
                    ? ($adjustment->baseOil?->name ?? 'Unknown oil')
                    : ($adjustment->waxInventory?->name ?? 'Unknown wax');

                return [
                    'id' => (int) $adjustment->id,
                    'item_type' => (string) $adjustment->item_type,
                    'item_name' => $itemName,
                    'grams_delta' => (float) $adjustment->grams_delta,
                    'before_grams' => (float) $adjustment->before_grams,
                    'after_grams' => (float) $adjustment->after_grams,
                    'reason' => (string) $adjustment->reason,
                    'notes' => (string) ($adjustment->notes ?? ''),
                    'performed_by' => $adjustment->performed_by,
                    'created_at' => $adjustment->created_at,
                ];
            });
    }

    /**
     * @param  array<int,float>  $demandByOilId
     * @return array<int,array<string,mixed>>
     */
    public function evaluateDemandAgainstOilInventory(array $demandByOilId): array
    {
        $rows = [];
        foreach ($demandByOilId as $oilId => $demandGrams) {
            $oil = BaseOil::query()->find((int) $oilId);
            if (! $oil) {
                continue;
            }

            $onHand = (float) $oil->grams_on_hand;
            $threshold = (float) $oil->reorder_threshold;
            $projected = max(0.0, $onHand - max(0.0, (float) $demandGrams));

            $rows[] = [
                'base_oil_id' => (int) $oil->id,
                'name' => (string) $oil->name,
                'demand_grams' => round((float) $demandGrams, 2),
                'on_hand_grams' => round($onHand, 2),
                'projected_on_hand_grams' => round($projected, 2),
                'reorder_threshold_grams' => round($threshold, 2),
                'state_now' => $this->evaluateReorderState($onHand, $threshold),
                'state_after_demand' => $this->evaluateReorderState($projected, $threshold),
            ];
        }

        return $rows;
    }

    /**
     * @return array{status:string,label:string,gap_grams:float,ratio:float}
     */
    public function evaluateReorderState(float $onHandGrams, float $thresholdGrams): array
    {
        $onHand = round(max(0.0, $onHandGrams), 2);
        $threshold = round(max(0.0, $thresholdGrams), 2);

        if ($threshold <= 0) {
            return [
                'status' => $onHand <= 0 ? 'reorder' : 'ok',
                'label' => $onHand <= 0 ? 'Reorder' : 'OK',
                'gap_grams' => round($threshold - $onHand, 2),
                'ratio' => 1.0,
            ];
        }

        $ratio = $onHand / $threshold;

        if ($ratio <= 0.5) {
            $status = 'reorder';
            $label = 'Reorder';
        } elseif ($ratio < 1.0) {
            $status = 'low';
            $label = 'Low';
        } else {
            $status = 'ok';
            $label = 'OK';
        }

        return [
            'status' => $status,
            'label' => $label,
            'gap_grams' => round($threshold - $onHand, 2),
            'ratio' => round($ratio, 4),
        ];
    }
}
