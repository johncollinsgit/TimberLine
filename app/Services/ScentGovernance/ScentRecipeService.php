<?php

namespace App\Services\ScentGovernance;

use App\Models\BaseOil;
use App\Models\Scent;
use App\Models\ScentRecipe;
use App\Models\ScentRecipeComponent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScentRecipeService
{
    /**
     * @param  array<string,mixed>  $attributes
     */
    public function syncActiveRecipeForScent(Scent $scent, array $attributes = [], bool $forceNewVersion = false): ?ScentRecipe
    {
        if (! Schema::hasTable('scent_recipes') || ! Schema::hasTable('scent_recipe_components')) {
            return null;
        }

        $components = $this->resolveComponents($scent, $attributes);
        $status = $this->resolveStatus($scent, $attributes);
        $sourceContext = trim((string) ($attributes['source_context'] ?? 'wizard'));

        /** @var ScentRecipe|null $current */
        $current = ScentRecipe::query()
            ->with('components')
            ->where('scent_id', $scent->id)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        if (! $forceNewVersion && $current && ! $this->hasRecipeChanged($current, $components, $status)) {
            if ($scent->current_scent_recipe_id !== $current->id) {
                $scent->forceFill(['current_scent_recipe_id' => $current->id])->save();
            }

            return $current;
        }

        return DB::transaction(function () use ($scent, $components, $status, $sourceContext): ScentRecipe {
            ScentRecipe::query()->where('scent_id', $scent->id)->update(['is_active' => false]);

            $version = ((int) (ScentRecipe::query()->where('scent_id', $scent->id)->max('version') ?? 0)) + 1;

            $recipe = ScentRecipe::query()->create([
                'scent_id' => $scent->id,
                'version' => $version,
                'status' => $status,
                'is_active' => true,
                'activated_at' => $status === ScentLifecycleService::STATUS_ACTIVE ? now() : null,
                'source_context' => $sourceContext !== '' ? $sourceContext : 'wizard',
            ]);

            foreach ($components as $index => $component) {
                ScentRecipeComponent::query()->create([
                    'scent_recipe_id' => $recipe->id,
                    'component_type' => (string) $component['component_type'],
                    'base_oil_id' => $component['base_oil_id'] ?? null,
                    'blend_template_id' => $component['blend_template_id'] ?? null,
                    'parts' => $component['parts'] ?? null,
                    'percentage' => $component['percentage'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            $scent->forceFill(['current_scent_recipe_id' => $recipe->id])->save();

            return $recipe->fresh('components') ?? $recipe;
        });
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<int,array<string,mixed>>
     */
    protected function resolveComponents(Scent $scent, array $attributes): array
    {
        $explicit = $this->explicitComponents($attributes);
        if ($explicit !== []) {
            return $explicit;
        }

        $isBlend = (bool) ($attributes['is_blend'] ?? $scent->is_blend ?? false);
        $blendId = blank($attributes['oil_blend_id'] ?? $scent->oil_blend_id)
            ? null
            : (int) ($attributes['oil_blend_id'] ?? $scent->oil_blend_id);

        if ($isBlend && $blendId) {
            return [[
                'component_type' => ScentRecipeComponent::TYPE_BLEND_TEMPLATE,
                'blend_template_id' => $blendId,
                'parts' => 1,
                'percentage' => 100,
            ]];
        }

        $oilReference = trim((string) ($attributes['oil_reference_name'] ?? $scent->oil_reference_name ?? ''));
        if ($oilReference !== '' && Schema::hasTable('base_oils')) {
            $baseOilId = BaseOil::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower($oilReference)])
                ->value('id');

            if ($baseOilId) {
                return [[
                    'component_type' => ScentRecipeComponent::TYPE_OIL,
                    'base_oil_id' => (int) $baseOilId,
                    'parts' => 1,
                    'percentage' => 100,
                ]];
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<int,array<string,mixed>>
     */
    protected function explicitComponents(array $attributes): array
    {
        $rows = $attributes['recipe_components'] ?? null;
        if (! is_array($rows)) {
            return [];
        }

        $components = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = trim((string) ($row['component_type'] ?? ''));
            if (! in_array($type, [ScentRecipeComponent::TYPE_OIL, ScentRecipeComponent::TYPE_BLEND_TEMPLATE], true)) {
                continue;
            }

            $baseOilId = blank($row['base_oil_id'] ?? null) ? null : (int) $row['base_oil_id'];
            $blendTemplateId = blank($row['blend_template_id'] ?? null) ? null : (int) $row['blend_template_id'];
            $parts = blank($row['parts'] ?? null) ? null : (float) $row['parts'];
            $percentage = blank($row['percentage'] ?? null) ? null : (float) $row['percentage'];

            if ($type === ScentRecipeComponent::TYPE_OIL && ! $baseOilId) {
                continue;
            }

            if ($type === ScentRecipeComponent::TYPE_BLEND_TEMPLATE && ! $blendTemplateId) {
                continue;
            }

            $components[] = [
                'component_type' => $type,
                'base_oil_id' => $baseOilId,
                'blend_template_id' => $blendTemplateId,
                'parts' => $parts,
                'percentage' => $percentage,
            ];
        }

        return $components;
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    protected function resolveStatus(Scent $scent, array $attributes): string
    {
        $status = trim((string) ($attributes['lifecycle_status'] ?? $scent->lifecycle_status ?? ''));
        if ($status === '') {
            $status = (bool) ($attributes['is_active'] ?? $scent->is_active)
                ? ScentLifecycleService::STATUS_ACTIVE
                : ScentLifecycleService::STATUS_INACTIVE;
        }

        $allowed = app(ScentLifecycleService::class)->statuses();

        return in_array($status, $allowed, true)
            ? $status
            : ScentLifecycleService::STATUS_DRAFT;
    }

    /**
     * @param  array<int,array<string,mixed>>  $components
     */
    protected function hasRecipeChanged(ScentRecipe $current, array $components, string $status): bool
    {
        if ($current->status !== $status) {
            return true;
        }

        $left = $current->components
            ->map(fn (ScentRecipeComponent $component): string => implode(':', [
                (string) $component->component_type,
                (string) ($component->base_oil_id ?? ''),
                (string) ($component->blend_template_id ?? ''),
                (string) ($component->parts ?? ''),
                (string) ($component->percentage ?? ''),
            ]))
            ->values()
            ->all();

        $right = collect($components)
            ->map(fn (array $component): string => implode(':', [
                (string) ($component['component_type'] ?? ''),
                (string) ($component['base_oil_id'] ?? ''),
                (string) ($component['blend_template_id'] ?? ''),
                (string) ($component['parts'] ?? ''),
                (string) ($component['percentage'] ?? ''),
            ]))
            ->values()
            ->all();

        return $left !== $right;
    }
}
