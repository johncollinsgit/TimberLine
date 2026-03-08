<?php

namespace App\Services\Pouring;

use App\Actions\Inventory\AdjustInventoryAction;
use App\Models\BaseOil;
use App\Models\InventoryAdjustment;
use App\Models\OilMovement;
use App\Models\OrderLine;
use App\Models\Scent;
use Illuminate\Support\Facades\DB;

class OilConsumptionService
{
    public function __construct(
        protected BlendResolver $blendResolver,
        protected AdjustInventoryAction $adjustInventory,
    ) {}

    public function consumeForLine(OrderLine $line, float $oilGrams, string $reason, ?int $userId = null): void
    {
        if ($oilGrams <= 0 || !$line->scent_id) {
            return;
        }

        $scent = Scent::query()->find($line->scent_id);
        if (!$scent) {
            return;
        }

        $resolved = $this->blendResolver->resolveBaseOilOrBlend($scent);

        DB::transaction(function () use ($resolved, $oilGrams, $reason, $line, $userId) {
            if ($resolved['type'] === 'blend' && $resolved['model']) {
                $split = $this->blendResolver->splitBlendGrams($resolved['model'], $oilGrams);
                foreach ($split as $part) {
                    $this->applyMovement($part['base_oil_id'], $part['grams'], $reason, $line, $userId);
                }
                return;
            }

            if ($resolved['type'] === 'base' && $resolved['model']) {
                $this->applyMovement($resolved['model']->id, $oilGrams, $reason, $line, $userId);
            }
        });
    }

    protected function applyMovement(int $baseOilId, float $grams, string $reason, OrderLine $line, ?int $userId): void
    {
        $oil = BaseOil::query()->find($baseOilId);
        if (!$oil) {
            return;
        }

        $adjustment = $this->adjustInventory->adjustOil(
            oil: $oil,
            gramsDelta: -abs($grams),
            reason: InventoryAdjustment::REASON_CONSUMED,
            notes: $reason,
            performedBy: $userId,
            sourceType: 'order_lines',
            sourceId: $line->id
        );

        if (! $adjustment) {
            return;
        }

        OilMovement::create([
            'base_oil_id' => $baseOilId,
            'grams' => (float) $adjustment->grams_delta,
            'reason' => $reason,
            'source_type' => 'order_lines',
            'source_id' => $line->id,
            'created_by' => $userId,
        ]);
    }
}
