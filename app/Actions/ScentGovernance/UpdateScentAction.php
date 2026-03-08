<?php

namespace App\Actions\ScentGovernance;

use App\Models\Blend;
use App\Models\Scent;
use App\Services\ScentGovernance\ScentLifecycleService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class UpdateScentAction
{
    public function __construct(
        protected ScentLifecycleService $lifecycle
    ) {}

    /**
     * @param  array<string,mixed>  $attributes
     * @throws ValidationException
     */
    public function execute(Scent $scent, array $attributes, string $fieldPrefix = ''): Scent
    {
        $attributes = $this->normalize($attributes, $scent);
        $attributes = $this->lifecycle->applyLifecycle($attributes, $fieldPrefix);

        $this->assertUniqueName((string) ($attributes['name'] ?? ''), (int) $scent->id, $fieldPrefix);
        $this->assertUniqueAbbreviation($attributes['abbreviation'] ?? null, (int) $scent->id, $fieldPrefix);

        $canonicalId = $attributes['canonical_scent_id'] ?: null;
        if ($canonicalId === (int) $scent->id) {
            $canonicalId = null;
        }

        $blendOilCount = $this->resolveBlendOilCount($attributes);

        $payload = [
            'name' => (string) $attributes['name'],
            'display_name' => $attributes['display_name'] ?: null,
            'abbreviation' => $attributes['abbreviation'] ?: null,
            'oil_reference_name' => $attributes['oil_reference_name'] ?: null,
            'is_blend' => (bool) ($attributes['is_blend'] ?? false),
            'oil_blend_id' => $attributes['oil_blend_id'] ?: null,
            'blend_oil_count' => $blendOilCount,
            'canonical_scent_id' => $canonicalId,
            'source_wholesale_custom_scent_id' => $attributes['source_wholesale_custom_scent_id'] ?: null,
            'is_wholesale_custom' => (bool) ($attributes['is_wholesale_custom'] ?? false),
            'is_candle_club' => (bool) ($attributes['is_candle_club'] ?? false),
            'is_active' => (bool) ($attributes['is_active'] ?? true),
        ];

        if (Schema::hasColumn('scents', 'notes')) {
            $payload['notes'] = $attributes['notes'] ?: null;
        }

        if (Schema::hasColumn('scents', 'availability_json')) {
            $payload['availability_json'] = $attributes['availability_json'] ?: null;
        }

        $scent->fill($payload);
        $scent->save();

        return $scent->fresh() ?? $scent;
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    protected function normalize(array $attributes, Scent $scent): array
    {
        $normalized = [
            'name' => Scent::normalizeName((string) ($attributes['name'] ?? $scent->name)),
            'display_name' => trim((string) ($attributes['display_name'] ?? $scent->display_name ?? '')),
            'abbreviation' => trim((string) ($attributes['abbreviation'] ?? $scent->abbreviation ?? '')),
            'oil_reference_name' => trim((string) ($attributes['oil_reference_name'] ?? $scent->oil_reference_name ?? '')),
            'notes' => trim((string) ($attributes['notes'] ?? $scent->notes ?? '')),
            'is_blend' => (bool) ($attributes['is_blend'] ?? $scent->is_blend),
            'oil_blend_id' => blank($attributes['oil_blend_id'] ?? $scent->oil_blend_id) ? null : (int) ($attributes['oil_blend_id'] ?? $scent->oil_blend_id),
            'blend_oil_count' => blank($attributes['blend_oil_count'] ?? $scent->blend_oil_count) ? null : (int) ($attributes['blend_oil_count'] ?? $scent->blend_oil_count),
            'canonical_scent_id' => blank($attributes['canonical_scent_id'] ?? $scent->canonical_scent_id) ? null : (int) ($attributes['canonical_scent_id'] ?? $scent->canonical_scent_id),
            'source_wholesale_custom_scent_id' => blank($attributes['source_wholesale_custom_scent_id'] ?? $scent->source_wholesale_custom_scent_id)
                ? null
                : (int) ($attributes['source_wholesale_custom_scent_id'] ?? $scent->source_wholesale_custom_scent_id),
            'is_wholesale_custom' => (bool) ($attributes['is_wholesale_custom'] ?? $scent->is_wholesale_custom),
            'is_candle_club' => (bool) ($attributes['is_candle_club'] ?? $scent->is_candle_club),
            'is_active' => (bool) ($attributes['is_active'] ?? $scent->is_active),
            'lifecycle_status' => $attributes['lifecycle_status'] ?? null,
            'availability_json' => $this->normalizeAvailability(
                $attributes['availability_json'] ?? $scent->availability_json ?? null
            ),
        ];

        if (! $normalized['is_blend']) {
            $normalized['oil_blend_id'] = null;
            $normalized['blend_oil_count'] = null;
        }

        return $normalized;
    }

    /**
     * @param  mixed  $value
     * @return array<string,bool>|null
     */
    protected function normalizeAvailability(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $allowed = ['retail', 'wholesale', 'candle_club', 'room_spray', 'wax_melt'];
        $normalized = [];
        foreach ($allowed as $key) {
            $normalized[$key] = (bool) ($value[$key] ?? false);
        }

        return $normalized;
    }

    protected function assertUniqueName(string $normalizedName, int $ignoreId, string $fieldPrefix): void
    {
        if ($normalizedName === '') {
            throw ValidationException::withMessages([
                $this->field('name', $fieldPrefix) => 'Name is required.',
            ]);
        }

        $exists = Scent::query()
            ->where('id', '!=', $ignoreId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($normalizedName)])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                $this->field('name', $fieldPrefix) => 'A scent with this name already exists.',
            ]);
        }
    }

    protected function assertUniqueAbbreviation(?string $abbreviation, int $ignoreId, string $fieldPrefix): void
    {
        $abbr = trim((string) $abbreviation);
        if ($abbr === '') {
            return;
        }

        $exists = Scent::query()
            ->where('id', '!=', $ignoreId)
            ->whereRaw('lower(abbreviation) = ?', [mb_strtolower($abbr)])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                $this->field('abbreviation', $fieldPrefix) => 'This abbreviation is already used by another scent.',
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
