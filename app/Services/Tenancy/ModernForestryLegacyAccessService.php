<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use RuntimeException;

/** Legacy Growave/Candle Cash migration tools are Modern Forestry data only. */
class ModernForestryLegacyAccessService
{
    public function allowsTenantId(?int $tenantId): bool
    {
        if ($tenantId === null || $tenantId <= 0) {
            return false;
        }

        return strtolower(trim((string) Tenant::query()->whereKey($tenantId)->value('slug'))) === 'modern-forestry';
    }

    public function assertTenantId(?int $tenantId): void
    {
        if (! $this->allowsTenantId($tenantId)) {
            throw new RuntimeException('Legacy Growave data is restricted to the Modern Forestry workspace.');
        }
    }
}
