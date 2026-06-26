<?php

namespace App\Services\Mobile;

use App\Models\TenantMarketingSetting;
use Illuminate\Support\Facades\Schema;

class ModernForestryMobileSupportSettingsService
{
    public const SETTING_KEY = 'modern_forestry_mobile_support_alerts';

    /**
     * @return array{support_alert_phone:?string}
     */
    public function forTenant(?int $tenantId): array
    {
        $fallback = $this->normalizePhone(config('services.modern_forestry.support_alert_phone'));

        if ($tenantId === null || $tenantId <= 0 || ! Schema::hasTable('tenant_marketing_settings')) {
            return [
                'support_alert_phone' => $fallback,
            ];
        }

        $stored = TenantMarketingSetting::query()
            ->where('tenant_id', $tenantId)
            ->where('key', self::SETTING_KEY)
            ->first();

        $value = is_array($stored?->value) ? $stored->value : [];

        return [
            'support_alert_phone' => $this->normalizePhone($value['support_alert_phone'] ?? null) ?? $fallback,
        ];
    }

    public function supportAlertPhone(?int $tenantId): ?string
    {
        return $this->forTenant($tenantId)['support_alert_phone'] ?? null;
    }

    /**
     * @param array{support_alert_phone:?string} $payload
     */
    public function saveForTenant(int $tenantId, array $payload): TenantMarketingSetting
    {
        if (! Schema::hasTable('tenant_marketing_settings')) {
            throw new \RuntimeException('tenant_marketing_settings table is required for Modern Forestry mobile support settings.');
        }

        return TenantMarketingSetting::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'key' => self::SETTING_KEY,
            ],
            [
                'value' => [
                    'support_alert_phone' => $this->normalizePhone($payload['support_alert_phone'] ?? null),
                ],
                'description' => 'Tenant-scoped Modern Forestry mobile support alert routing.',
            ]
        );
    }

    protected function normalizePhone(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', trim((string) $value));
        if (! is_string($digits) || $digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return '+'.$digits;
    }
}
