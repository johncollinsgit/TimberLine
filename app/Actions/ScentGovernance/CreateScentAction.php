<?php

namespace App\Actions\ScentGovernance;

use App\Models\Blend;
use App\Models\Scent;
use App\Services\ScentGovernance\ScentLifecycleService;
use Illuminate\Validation\ValidationException;

class CreateScentAction
{
    public function __construct(
        protected ScentLifecycleService $lifecycle
    ) {}

    /**
     * @param  array<string,mixed>  $attributes
     * @throws ValidationException
     */
    public function execute(array $attributes, string $fieldPrefix = ''): Scent
    {
        $attributes = $this->normalize($attributes);
        $attributes = $this->lifecycle->applyLifecycle($attributes, $fieldPrefix);

        $this->assertUniqueName((string) ($attributes['name'] ?? ''), $fieldPrefix);
        $this->assertUniqueAbbreviation($attributes['abbreviation'] ?? null, $fieldPrefix);

        $blendOilCount = $this->resolveBlendOilCount($attributes);

        return Scent::query()->create([
            'name' => (string) $attributes['name'],
            'display_name' => $attributes['display_name'] ?: null,
            'abbreviation' => $attributes['abbreviation'] ?: null,
            'oil_reference_name' => $attributes['oil_reference_name'] ?: null,
            'is_blend' => (bool) ($attributes['is_blend'] ?? false),
            'oil_blend_id' => $attributes['oil_blend_id'] ?: null,
            'blend_oil_count' => $blendOilCount,
            'canonical_scent_id' => $attributes['canonical_scent_id'] ?: null,
            'source_wholesale_custom_scent_id' => $attributes['source_wholesale_custom_scent_id'] ?: null,
            'is_wholesale_custom' => (bool) ($attributes['is_wholesale_custom'] ?? false),
            'is_candle_club' => (bool) ($attributes['is_candle_club'] ?? false),
            'is_active' => (bool) ($attributes['is_active'] ?? true),
        ]);
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    protected function normalize(array $attributes): array
    {
        $normalized = [
            'name' => Scent::normalizeName((string) ($attributes['name'] ?? '')),
            'display_name' => trim((string) ($attributes['display_name'] ?? '')),
            'abbreviation' => trim((string) ($attributes['abbreviation'] ?? '')),
            'oil_reference_name' => trim((string) ($attributes['oil_reference_name'] ?? '')),
            'is_blend' => (bool) ($attributes['is_blend'] ?? false),
            'oil_blend_id' => blank($attributes['oil_blend_id'] ?? null) ? null : (int) $attributes['oil_blend_id'],
            'blend_oil_count' => blank($attributes['blend_oil_count'] ?? null) ? null : (int) $attributes['blend_oil_count'],
            'canonical_scent_id' => blank($attributes['canonical_scent_id'] ?? null) ? null : (int) $attributes['canonical_scent_id'],
            'source_wholesale_custom_scent_id' => blank($attributes['source_wholesale_custom_scent_id'] ?? null)
                ? null
                : (int) $attributes['source_wholesale_custom_scent_id'],
            'is_wholesale_custom' => (bool) ($attributes['is_wholesale_custom'] ?? false),
            'is_candle_club' => (bool) ($attributes['is_candle_club'] ?? false),
            'is_active' => (bool) ($attributes['is_active'] ?? true),
            'lifecycle_status' => $attributes['lifecycle_status'] ?? null,
        ];

        if (! $normalized['is_blend']) {
            $normalized['oil_blend_id'] = null;
            $normalized['blend_oil_count'] = null;
        }

        return $normalized;
    }

    protected function assertUniqueName(string $normalizedName, string $fieldPrefix): void
    {
        if ($normalizedName === '') {
            throw ValidationException::withMessages([
                $this->field('name', $fieldPrefix) => 'Name is required.',
            ]);
        }

        $exists = Scent::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($normalizedName)])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                $this->field('name', $fieldPrefix) => 'A scent with this name already exists.',
            ]);
        }
    }

    protected function assertUniqueAbbreviation(?string $abbreviation, string $fieldPrefix): void
    {
        $abbr = trim((string) $abbreviation);
        if ($abbr === '') {
            return;
        }

        $exists = Scent::query()
            ->whereRaw('lower(abbreviation) = ?', [mb_strtolower($abbr)])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                $this->field('abbreviation', $fieldPrefix) => "Abbrev '{$abbr}' is already used by another scent.",
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    protected function resolveBlendOilCount(array $attributes): ?int
    {
        if (! (bool) ($attributes['is_blend'] ?? false)) {
            return null;
        }

        $blendId = $attributes['oil_blend_id'] ?? null;
        if ($blendId) {
            $count = Blend::query()->find((int) $blendId)?->components()->count();
            if ($count) {
                return (int) $count;
            }
        }

        $explicit = $attributes['blend_oil_count'] ?? null;
        if ($explicit === null) {
            return null;
        }

        return max(1, (int) $explicit);
    }

    protected function field(string $name, string $prefix): string
    {
        return $prefix !== '' ? $prefix.$name : $name;
    }
}

