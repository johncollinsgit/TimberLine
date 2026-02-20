<?php

namespace App\Services\Pouring;

use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendComponent;
use App\Models\Scent;

class BlendResolver
{
    public function resolveBaseOilOrBlend(Scent $scent): array
    {
        $ref = $scent->oil_reference_name ?: $scent->name;

        if ($scent->is_blend) {
            $blend = Blend::query()->where('name', $ref)->first();
            return ['type' => 'blend', 'model' => $blend];
        }

        $base = BaseOil::query()->where('name', $ref)->first();
        return ['type' => 'base', 'model' => $base];
    }

    /**
     * @return array<int, array{base_oil_id: int, grams: float}>
     */
    public function splitBlendGrams(Blend $blend, float $grams): array
    {
        $components = BlendComponent::query()->where('blend_id', $blend->id)->get();
        $totalWeight = $components->sum('ratio_weight');

        if ($totalWeight <= 0) {
            return [];
        }

        return $components->map(function (BlendComponent $component) use ($grams, $totalWeight) {
            $portion = ($component->ratio_weight / $totalWeight) * $grams;
            return [
                'base_oil_id' => $component->base_oil_id,
                'grams' => round($portion, 2),
            ];
        })->all();
    }
}
