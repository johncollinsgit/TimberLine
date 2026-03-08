<?php

namespace App\Services\Inventory;

class WaxConversionService
{
    public const GRAMS_PER_POUND = 453.59237;
    public const POUNDS_PER_BOX = 45.0;

    public function poundsToGrams(float $pounds): float
    {
        return round(max(0, $pounds) * self::GRAMS_PER_POUND, 2);
    }

    public function gramsToPounds(float $grams): float
    {
        return round(max(0, $grams) / self::GRAMS_PER_POUND, 2);
    }

    public function boxesToGrams(float $boxes): float
    {
        return $this->poundsToGrams(max(0, $boxes) * self::POUNDS_PER_BOX);
    }

    public function gramsToBoxes(float $grams): float
    {
        $pounds = $this->gramsToPounds($grams);

        return round($pounds / self::POUNDS_PER_BOX, 3);
    }

    public function defaultWaxReorderThresholdGrams(): float
    {
        return $this->boxesToGrams(8.0); // 8 x 45 lb = 360 lb
    }
}
