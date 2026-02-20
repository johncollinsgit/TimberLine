<?php

namespace App\Services\Pouring;

use App\Models\PouringMeasurement;
use App\Models\RoomSprayMeasurement;

class MeasurementResolver
{
    protected const CANONICAL_KEY = 'production_formulation_reference_v1';

    /**
     * Resolve a per-unit measurement for candles / wax melts.
     */
    public function resolve(string $sizeCode, ?string $productType = null): ?PouringMeasurement
    {
        $normalized = trim(strtolower($sizeCode));
        $type = $productType ?? $this->inferProductType($normalized);

        return PouringMeasurement::query()
            ->whereRaw('lower(size_code) = ?', [strtolower($sizeCode)])
            ->where('product_type', $type)
            ->where('active', true)
            ->first();
    }

    /**
     * Resolve ingredient totals for a specific quantity.
     *
     * @return array{type: string, wax_grams: float, oil_grams: float, alcohol_grams: float, water_grams: float, total_grams: float, pitcher_grams: float}|null
     */
    public function resolveLineIngredients(string $sizeCode, int $quantity, ?string $productType = null): ?array
    {
        $normalized = trim(strtolower($sizeCode));
        $type = $productType ?? $this->inferProductType($normalized);
        $qty = max(0, $quantity);

        if ($qty === 0) {
            return null;
        }

        $canonical = $this->resolveFromCanonical($normalized, $type, $qty);
        if ($canonical !== null) {
            return $canonical;
        }

        if ($type === 'room_spray') {
            $measurement = RoomSprayMeasurement::query()
                ->where('quantity', $qty)
                ->where('active', true)
                ->first();

            if (!$measurement) {
                $base = RoomSprayMeasurement::query()
                    ->where('quantity', 1)
                    ->where('active', true)
                    ->first();

                if (!$base) {
                    return null;
                }

                $alcohol = (float) $base->alcohol_grams * $qty;
                $oil = (float) $base->oil_grams * $qty;
                $water = (float) $base->water_grams * $qty;
            } else {
                $alcohol = (float) $measurement->alcohol_grams;
                $oil = (float) $measurement->oil_grams;
                $water = (float) $measurement->water_grams;
            }

            $total = $alcohol + $oil + $water;

            return [
                'type' => 'room_spray',
                'wax_grams' => 0.0,
                'oil_grams' => $oil,
                'alcohol_grams' => $alcohol,
                'water_grams' => $water,
                'total_grams' => $total,
                'pitcher_grams' => 0.0,
            ];
        }

        $measurement = $this->resolve($sizeCode, $type);
        if (!$measurement) {
            return null;
        }

        $wax = (float) $measurement->wax_grams * $qty;
        $oil = (float) $measurement->oil_grams * $qty;
        $total = (float) $measurement->total_grams * $qty;

        return [
            'type' => $type,
            'wax_grams' => $wax,
            'oil_grams' => $oil,
            'alcohol_grams' => 0.0,
            'water_grams' => 0.0,
            'total_grams' => $total,
            'pitcher_grams' => $wax + $oil,
        ];
    }

    public function inferProductType(string $sizeCode): string
    {
        if (str_contains($sizeCode, 'spray')) {
            return 'room_spray';
        }

        if (str_contains($sizeCode, 'melt')) {
            return 'wax_melt';
        }

        return 'candle';
    }

    /**
     * @return array{type: string, wax_grams: float, oil_grams: float, alcohol_grams: float, water_grams: float, total_grams: float, pitcher_grams: float}|null
     */
    protected function resolveFromCanonical(string $normalizedSizeCode, string $type, int $qty): ?array
    {
        $reference = config('production_formulation_reference.' . self::CANONICAL_KEY);
        if (!is_array($reference) || !($reference['canonical'] ?? false)) {
            return null;
        }

        $measurements = $reference['measurements'] ?? null;
        if (!is_array($measurements)) {
            return null;
        }

        $bucket = $this->canonicalBucketFor($normalizedSizeCode, $type);
        if ($bucket === null || !isset($measurements[$bucket]) || !is_array($measurements[$bucket])) {
            return null;
        }

        $rows = $measurements[$bucket];
        $row = $rows[$qty] ?? null;

        if (!is_array($row)) {
            $base = $rows[1] ?? null;
            if (!is_array($base)) {
                return null;
            }

            if ($type === 'room_spray') {
                $alcohol = round(((float) ($base['alcohol_grams'] ?? 0)) * $qty, 2);
                $oil = round(((float) ($base['oil_grams'] ?? 0)) * $qty, 2);
                $water = round(((float) ($base['water_grams'] ?? 0)) * $qty, 2);
                $total = round($alcohol + $oil + $water, 2);

                return [
                    'type' => 'room_spray',
                    'wax_grams' => 0.0,
                    'oil_grams' => $oil,
                    'alcohol_grams' => $alcohol,
                    'water_grams' => $water,
                    'total_grams' => $total,
                    'pitcher_grams' => 0.0,
                ];
            }

            $wax = round(((float) ($base['wax_grams'] ?? 0)) * $qty, 2);
            $oil = round(((float) ($base['oil_grams'] ?? 0)) * $qty, 2);
            $total = round($wax + $oil, 2);

            return [
                'type' => $type,
                'wax_grams' => $wax,
                'oil_grams' => $oil,
                'alcohol_grams' => 0.0,
                'water_grams' => 0.0,
                'total_grams' => $total,
                'pitcher_grams' => $total,
            ];
        }

        if ($type === 'room_spray') {
            $alcohol = round((float) ($row['alcohol_grams'] ?? 0), 2);
            $oil = round((float) ($row['oil_grams'] ?? 0), 2);
            $water = round((float) ($row['water_grams'] ?? 0), 2);
            $total = round($alcohol + $oil + $water, 2);

            return [
                'type' => 'room_spray',
                'wax_grams' => 0.0,
                'oil_grams' => $oil,
                'alcohol_grams' => $alcohol,
                'water_grams' => $water,
                'total_grams' => $total,
                'pitcher_grams' => 0.0,
            ];
        }

        $wax = round((float) ($row['wax_grams'] ?? 0), 2);
        $oil = round((float) ($row['oil_grams'] ?? 0), 2);
        $total = round($wax + $oil, 2);

        return [
            'type' => $type,
            'wax_grams' => $wax,
            'oil_grams' => $oil,
            'alcohol_grams' => 0.0,
            'water_grams' => 0.0,
            'total_grams' => $total,
            'pitcher_grams' => $total,
        ];
    }

    protected function canonicalBucketFor(string $normalizedSizeCode, string $type): ?string
    {
        if ($type === 'room_spray') {
            return 'room_spray';
        }

        if ($type === 'wax_melt') {
            return 'wax_melts';
        }

        if (str_contains($normalizedSizeCode, '16') && str_contains($normalizedSizeCode, 'top')) {
            return 'candle_16oz_top_off';
        }

        if (str_contains($normalizedSizeCode, '8') && str_contains($normalizedSizeCode, 'top')) {
            return 'candle_8oz_top_off';
        }

        if (str_contains($normalizedSizeCode, '16')) {
            return 'candle_16oz';
        }

        if (str_contains($normalizedSizeCode, '8')) {
            return 'candle_8oz';
        }

        if (str_contains($normalizedSizeCode, '4')) {
            return 'candle_4oz';
        }

        return null;
    }
}
