<?php

namespace App\Services\Tenancy;

use App\Models\MarketingSetting;
use App\Models\TenantMarketingSetting;
use Illuminate\Support\Facades\Schema;

class TenantMarketingSettingsResolver
{
    /**
     * @var array<string,array<string,mixed>>
     */
    protected array $arrayCache = [];

    public function flushArrayCache(): void
    {
        $this->arrayCache = [];
    }

    /**
     * Resolve an array-valued marketing setting through tenant override, then global fallback.
     *
     * @param  array<string,mixed>  $fallback
     * @return array<string,mixed>
     */
    public function array(string $key, ?int $tenantId = null, array $fallback = []): array
    {
        $cacheKey = sprintf('%s:%s', $tenantId === null ? 'global' : 'tenant:' . $tenantId, trim($key));

        if (array_key_exists($cacheKey, $this->arrayCache)) {
            return $this->arrayCache[$cacheKey];
        }

        if ($tenantId !== null && Schema::hasTable('tenant_marketing_settings')) {
            $tenantSetting = TenantMarketingSetting::query()
                ->forTenantId($tenantId)
                ->where('key', $key)
                ->first();

            if ($tenantSetting) {
                return $this->arrayCache[$cacheKey] = is_array($tenantSetting->value)
                    ? $tenantSetting->value
                    : $fallback;
            }
        }

        $globalSetting = MarketingSetting::query()->where('key', $key)->first();

        return $this->arrayCache[$cacheKey] = is_array($globalSetting?->value)
            ? $globalSetting->value
            : $fallback;
    }
}
