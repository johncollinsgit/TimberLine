<?php

namespace App\Services\Tenancy;

use App\Models\TenantMarketingSetting;

class TenantSquareConfigResolver
{
    public const KEY = 'square_config';

    public function __construct(private TenantMarketingSettingsResolver $settingsResolver)
    {
    }

    public function resolveForTenant(int $tenantId): ?array
    {
        $raw = $this->settingsResolver->array(self::KEY, $tenantId, []);
        return $this->normalize($raw);
    }

    /**
     * @return array<int,int>
     */
    public function tenantIdsWithConfig(): array
    {
        return TenantMarketingSetting::query()
            ->where('key', self::KEY)
            ->pluck('tenant_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $value
     */
    protected function normalize(array $value): ?array
    {
        $accessToken = trim((string) ($value['access_token'] ?? ''));
        $rawLocations = $value['location_ids'] ?? [];
        if (! is_array($rawLocations) && isset($value['location_id'])) {
            $rawLocations = [$value['location_id']];
        }

        $locationIds = array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), (array) $rawLocations)));

        if ($accessToken === '' || $locationIds === []) {
            return null;
        }

        return [
            'access_token' => $accessToken,
            'location_ids' => $locationIds,
            'base_url' => trim((string) ($value['base_url'] ?? config('marketing.square.base_url'))),
            'environment' => trim((string) ($value['environment'] ?? config('marketing.square.environment'))),
        ];
    }
}
