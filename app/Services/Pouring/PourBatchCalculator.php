<?php

namespace App\Services\Pouring;

use App\Models\OrderLine;
use App\Models\PourBatchLine;
use App\Models\PourBatchPitcher;

class PourBatchCalculator
{
    public function __construct(protected MeasurementResolver $measurements)
    {
    }

    /**
     * @param array<int, OrderLine> $lines
     * @return array{lines: array<int, array>, totals: array, pitchers: array<int, array>}
     */
    public function calculate(array $lines, float $pitcherMax = 2300): array
    {
        $lineRows = [];
        $waxTotal = 0.0;
        $oilTotal = 0.0;
        $alcoholTotal = 0.0;
        $waterTotal = 0.0;
        $mixWaxTotal = 0.0;
        $mixOilTotal = 0.0;
        $mixTotal = 0.0;

        foreach ($lines as $line) {
            $sizeCode = $line->size?->code ?? $line->size_code ?? null;
            if (!$sizeCode) {
                continue;
            }

            $baseQty = (int) (($line->ordered_qty ?? $line->quantity) ?? 0);
            $extra = (int) ($line->extra_qty ?? 0);
            $qty = $baseQty + $extra;
            if ($qty <= 0) {
                continue;
            }

            $ingredients = $this->measurements->resolveLineIngredients($sizeCode, $qty);
            if (!$ingredients) {
                continue;
            }

            $wax = (float) $ingredients['wax_grams'];
            $oil = (float) $ingredients['oil_grams'];
            $alcohol = (float) $ingredients['alcohol_grams'];
            $water = (float) $ingredients['water_grams'];
            $total = (float) $ingredients['total_grams'];
            $pitcherGrams = (float) $ingredients['pitcher_grams'];

            $waxTotal += $wax;
            $oilTotal += $oil;
            $alcoholTotal += $alcohol;
            $waterTotal += $water;

            if ($pitcherGrams > 0) {
                $mixWaxTotal += $wax;
                $mixOilTotal += $oil;
                $mixTotal += $pitcherGrams;
            }

            $lineRows[] = [
                'order_line_id' => $line->id,
                'order_id' => $line->order_id,
                'scent_id' => $line->scent_id,
                'size_id' => $line->size_id,
                'sku' => $line->sku,
                'quantity' => $qty,
                'wax_grams' => round($wax, 2),
                'oil_grams' => round($oil, 2),
                'alcohol_grams' => round($alcohol, 2),
                'water_grams' => round($water, 2),
                'total_grams' => round($total, 2),
            ];
        }

        $totalGrams = round($waxTotal + $oilTotal + $alcoholTotal + $waterTotal, 2);
        $pitchers = $this->splitPitchers($mixTotal, $mixWaxTotal, $mixOilTotal, $pitcherMax);

        return [
            'lines' => $lineRows,
            'totals' => [
                'wax_grams' => round($waxTotal, 2),
                'oil_grams' => round($oilTotal, 2),
                'alcohol_grams' => round($alcoholTotal, 2),
                'water_grams' => round($waterTotal, 2),
                'total_grams' => $totalGrams,
            ],
            'pitchers' => $pitchers,
        ];
    }

    /**
     * @return array<int, array{pitcher_index: int, wax_grams: float, oil_grams: float, total_grams: float}>
     */
    protected function splitPitchers(float $total, float $waxTotal, float $oilTotal, float $max): array
    {
        if ($total <= 0 || $max <= 0) {
            return [];
        }

        $ratioWax = $total > 0 ? $waxTotal / $total : 0;
        $ratioOil = $total > 0 ? $oilTotal / $total : 0;

        $pitchers = [];
        $remaining = $total;
        $index = 1;

        while ($remaining > 0) {
            $pitcherTotal = min($remaining, $max);
            $pitchers[] = [
                'pitcher_index' => $index,
                'wax_grams' => round($pitcherTotal * $ratioWax, 2),
                'oil_grams' => round($pitcherTotal * $ratioOil, 2),
                'total_grams' => round($pitcherTotal, 2),
            ];
            $remaining -= $pitcherTotal;
            $index++;
        }

        return $pitchers;
    }
}
