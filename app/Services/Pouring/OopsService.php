<?php

namespace App\Services\Pouring;

use App\Models\OrderLine;
use Illuminate\Support\Facades\DB;

class OopsService
{
    public function __construct(
        protected MeasurementResolver $measurements,
        protected OilConsumptionService $oil
    ) {
    }

    public function recordOops(OrderLine $line, int $qty, ?int $userId = null): void
    {
        if ($qty <= 0) {
            return;
        }

        $sizeCode = $line->size?->code ?? $line->size_code;
        if (!$sizeCode) {
            return;
        }

        $ingredients = $this->measurements->resolveLineIngredients($sizeCode, $qty);
        if (!$ingredients) {
            return;
        }

        $oilGrams = (float) $ingredients['oil_grams'];

        DB::transaction(function () use ($line, $qty, $oilGrams, $userId) {
            $line->extra_qty = (int) ($line->extra_qty ?? 0) + $qty;
            $line->save();

            $this->oil->consumeForLine($line, $oilGrams, 'oops_candle', $userId);
        });
    }
}
