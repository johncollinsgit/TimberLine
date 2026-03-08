<?php

namespace App\Services\ScentGovernance;

use App\Models\Scent;
use Illuminate\Validation\ValidationException;

class ScentLifecycleService
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    /**
     * @return array<int,string>
     */
    public function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_ACTIVE,
            self::STATUS_INACTIVE,
            self::STATUS_ARCHIVED,
        ];
    }

    /**
     * Current schema only has is_active. This maps lifecycle intent safely.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    public function applyLifecycle(array $attributes, string $fieldPrefix = ''): array
    {
        $status = trim((string) ($attributes['lifecycle_status'] ?? ''));
        if ($status === '') {
            return $attributes;
        }

        if (! in_array($status, $this->statuses(), true)) {
            throw ValidationException::withMessages([
                $this->field('lifecycle_status', $fieldPrefix) => 'Invalid lifecycle status.',
            ]);
        }

        $attributes['is_active'] = $status === self::STATUS_ACTIVE;

        return $attributes;
    }

    public function statusFor(?Scent $scent): string
    {
        if (! $scent) {
            return self::STATUS_DRAFT;
        }

        return (bool) ($scent->is_active ?? false)
            ? self::STATUS_ACTIVE
            : self::STATUS_INACTIVE;
    }

    protected function field(string $name, string $prefix): string
    {
        return $prefix !== '' ? $prefix.$name : $name;
    }
}

