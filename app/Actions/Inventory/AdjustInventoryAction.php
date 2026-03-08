<?php

namespace App\Actions\Inventory;

use App\Models\BaseOil;
use App\Models\InventoryAdjustment;
use App\Models\WaxInventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdjustInventoryAction
{
    public function adjustOil(
        BaseOil|int $oil,
        float $gramsDelta,
        string $reason,
        ?string $notes = null,
        ?int $performedBy = null,
        ?string $sourceType = null,
        ?int $sourceId = null
    ): ?InventoryAdjustment {
        $oilModel = $oil instanceof BaseOil ? $oil : BaseOil::query()->findOrFail($oil);

        return $this->apply(
            itemType: InventoryAdjustment::ITEM_TYPE_OIL,
            target: $oilModel,
            currentOnHand: (float) $oilModel->grams_on_hand,
            gramsDelta: $gramsDelta,
            reason: $reason,
            notes: $notes,
            performedBy: $performedBy,
            sourceType: $sourceType,
            sourceId: $sourceId
        );
    }

    public function setOilOnHand(
        BaseOil|int $oil,
        float $targetOnHand,
        string $reason,
        ?string $notes = null,
        ?int $performedBy = null
    ): ?InventoryAdjustment {
        $oilModel = $oil instanceof BaseOil ? $oil : BaseOil::query()->findOrFail($oil);

        return $this->adjustOil(
            oil: $oilModel,
            gramsDelta: max(0, $targetOnHand) - (float) $oilModel->grams_on_hand,
            reason: $reason,
            notes: $notes,
            performedBy: $performedBy
        );
    }

    public function adjustWax(
        WaxInventory|int $wax,
        float $gramsDelta,
        string $reason,
        ?string $notes = null,
        ?int $performedBy = null,
        ?string $sourceType = null,
        ?int $sourceId = null
    ): ?InventoryAdjustment {
        $waxModel = $wax instanceof WaxInventory ? $wax : WaxInventory::query()->findOrFail($wax);

        return $this->apply(
            itemType: InventoryAdjustment::ITEM_TYPE_WAX,
            target: $waxModel,
            currentOnHand: (float) $waxModel->on_hand_grams,
            gramsDelta: $gramsDelta,
            reason: $reason,
            notes: $notes,
            performedBy: $performedBy,
            sourceType: $sourceType,
            sourceId: $sourceId
        );
    }

    public function setWaxOnHand(
        WaxInventory|int $wax,
        float $targetOnHand,
        string $reason,
        ?string $notes = null,
        ?int $performedBy = null
    ): ?InventoryAdjustment {
        $waxModel = $wax instanceof WaxInventory ? $wax : WaxInventory::query()->findOrFail($wax);

        return $this->adjustWax(
            wax: $waxModel,
            gramsDelta: max(0, $targetOnHand) - (float) $waxModel->on_hand_grams,
            reason: $reason,
            notes: $notes,
            performedBy: $performedBy
        );
    }

    protected function apply(
        string $itemType,
        BaseOil|WaxInventory $target,
        float $currentOnHand,
        float $gramsDelta,
        string $reason,
        ?string $notes,
        ?int $performedBy,
        ?string $sourceType,
        ?int $sourceId
    ): ?InventoryAdjustment {
        $this->assertReason($reason);

        $before = max(0.0, round($currentOnHand, 2));
        $after = max(0.0, round($before + $gramsDelta, 2));
        $effectiveDelta = round($after - $before, 2);

        if (abs($effectiveDelta) < 0.01) {
            return null;
        }

        return DB::transaction(function () use ($itemType, $target, $before, $after, $effectiveDelta, $reason, $notes, $performedBy, $sourceType, $sourceId): InventoryAdjustment {
            if ($target instanceof BaseOil) {
                $target->forceFill(['grams_on_hand' => $after])->save();
            } else {
                $target->forceFill(['on_hand_grams' => $after])->save();
            }

            return InventoryAdjustment::query()->create([
                'item_type' => $itemType,
                'base_oil_id' => $target instanceof BaseOil ? $target->id : null,
                'wax_inventory_id' => $target instanceof WaxInventory ? $target->id : null,
                'grams_delta' => $effectiveDelta,
                'before_grams' => $before,
                'after_grams' => $after,
                'reason' => $reason,
                'notes' => $notes ? trim($notes) : null,
                'performed_by' => $performedBy,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]);
        });
    }

    protected function assertReason(string $reason): void
    {
        if (! in_array($reason, InventoryAdjustment::reasons(), true)) {
            throw ValidationException::withMessages([
                'reason' => 'Invalid inventory adjustment reason.',
            ]);
        }
    }
}
