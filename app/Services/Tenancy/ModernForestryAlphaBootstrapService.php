<?php

namespace App\Services\Tenancy;

use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Services\Marketing\Email\TenantEmailSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ModernForestryAlphaBootstrapService
{
    protected const CACHE_PREFIX = 'modern-forestry-alpha-bootstrap';

    /**
     * @var array<int,string>
     */
    protected array $configuredModuleKeys = [
        'dashboard',
        'customers',
        'rewards',
        'birthdays',
        'reviews',
        'wishlist',
        'referrals',
        'vip',
        'notifications',
        'campaigns',
        'reporting',
        'integrations',
        'uploads',
        'onboarding',
        'settings',
        'shopify',
        'email',
        'sms',
        'bulk_email_marketing',
        'additional_channels',
        'messaging',
    ];

    public function __construct(
        protected TenantEmailSettingsService $tenantEmailSettingsService
    ) {
    }

    /**
     * @return array{applied:bool,tenant_id:?int,reason:string}
     */
    public function ensureForTenant(?int $tenantId, ?string $storeKey = null, bool $force = false): array
    {
        $tenant = $this->resolveAlphaTenant($tenantId);
        if (! $tenant) {
            return [
                'applied' => false,
                'tenant_id' => $tenantId,
                'reason' => 'not_modern_forestry_alpha',
            ];
        }

        $cacheKey = self::CACHE_PREFIX.':'.$tenant->id;
        if (! $force && Cache::has($cacheKey)) {
            return [
                'applied' => true,
                'tenant_id' => (int) $tenant->id,
                'reason' => 'cached_recently',
            ];
        }

        $this->ensureStoreOwnership((int) $tenant->id, $storeKey);
        $this->ensureModuleEntitlements((int) $tenant->id);
        $this->ensureModuleStates((int) $tenant->id);
        $this->ensureEmailSettings((int) $tenant->id);
        $this->ensureSmsProviderSettings((int) $tenant->id);

        Cache::put($cacheKey, true, now()->addMinutes(10));

        return [
            'applied' => true,
            'tenant_id' => (int) $tenant->id,
            'reason' => $force ? 'forced_refresh' : 'ensured',
        ];
    }

    protected function resolveAlphaTenant(?int $tenantId): ?Tenant
    {
        $tenant = $tenantId !== null ? Tenant::query()->find($tenantId) : null;
        if ($tenant && $this->isModernForestryTenant($tenant)) {
            return $tenant;
        }

        return Tenant::query()
            ->where('slug', 'modern-forestry')
            ->first();
    }

    protected function isModernForestryTenant(Tenant $tenant): bool
    {
        $slug = strtolower(trim((string) $tenant->slug));
        $name = strtolower(trim((string) $tenant->name));

        return $slug === 'modern-forestry'
            || $name === 'modern forestry'
            || ((int) $tenant->id === 1 && ($slug === 'modern-forestry' || $name === 'modern forestry'));
    }

    protected function ensureStoreOwnership(int $tenantId, ?string $storeKey): void
    {
        if (! Schema::hasTable('shopify_stores')) {
            return;
        }

        $normalizedStoreKey = strtolower(trim((string) $storeKey));
        if ($normalizedStoreKey !== '') {
            ShopifyStore::query()
                ->where('store_key', $normalizedStoreKey)
                ->where(function ($query): void {
                    $query->whereNull('tenant_id')
                        ->orWhere('tenant_id', 0);
                })
                ->update(['tenant_id' => $tenantId]);
        }

        ShopifyStore::query()
            ->where('shop_domain', 'modernforestry.myshopify.com')
            ->where(function ($query): void {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', 0);
            })
            ->update(['tenant_id' => $tenantId]);
    }

    protected function ensureModuleEntitlements(int $tenantId): void
    {
        if (! Schema::hasTable('tenant_module_entitlements')) {
            return;
        }

        $now = now();
        $moduleKeys = array_keys((array) config('module_catalog.modules', []));

        foreach ($moduleKeys as $moduleKey) {
            if (! is_string($moduleKey) || trim($moduleKey) === '') {
                continue;
            }

            TenantModuleEntitlement::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'module_key' => strtolower(trim($moduleKey)),
                ],
                [
                    'availability_status' => 'available',
                    'enabled_status' => 'enabled',
                    'billing_status' => 'add_on_comped',
                    'price_override_cents' => 0,
                    'currency' => 'USD',
                    'entitlement_source' => 'modern_forestry_alpha_default',
                    'price_source' => 'modern_forestry_alpha_default',
                    'notes' => 'Alpha client defaults keep all Backstage modules available for Modern Forestry.',
                    'metadata' => [
                        'source' => 'modern_forestry_alpha_default',
                        'alpha_client' => true,
                    ],
                    'updated_at' => $now,
                ]
            );
        }
    }

    protected function ensureModuleStates(int $tenantId): void
    {
        if (! Schema::hasTable('tenant_module_states')) {
            return;
        }

        $now = now();
        foreach ($this->configuredModuleKeys as $moduleKey) {
            TenantModuleState::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'module_key' => $moduleKey,
                ],
                [
                    'enabled_override' => true,
                    'setup_status' => 'configured',
                    'setup_completed_at' => $now,
                    'coming_soon_override' => false,
                    'upgrade_prompt_override' => false,
                    'metadata' => [
                        'source' => 'modern_forestry_alpha_default',
                        'alpha_client' => true,
                    ],
                    'updated_at' => $now,
                ]
            );
        }
    }

    protected function ensureEmailSettings(int $tenantId): void
    {
        $fallback = $this->tenantEmailSettingsService->resolvedForTenant($tenantId);
        $fromEmail = trim((string) ($fallback['from_email'] ?? ''));
        $providerStatus = trim((string) ($fallback['provider_status'] ?? '')) !== ''
            ? (string) $fallback['provider_status']
            : 'healthy';

        $this->tenantEmailSettingsService->saveForTenant($tenantId, [
            'email_provider' => 'sendgrid',
            'email_enabled' => true,
            'analytics_enabled' => true,
            'from_name' => (string) ($fallback['from_name'] ?? 'Modern Forestry'),
            'from_email' => $fromEmail !== '' ? $fromEmail : null,
            'reply_to_email' => $fallback['reply_to_email'] ?? null,
            'provider_status' => $providerStatus,
            'provider_status_message' => null,
            'provider_config' => [
                'sender_mode' => 'global_fallback',
                'tracking_enabled' => true,
                'verified_sender_email' => $fromEmail !== '' ? $fromEmail : null,
                'verified_sender_name' => $fallback['from_name'] ?? 'Modern Forestry',
                'reply_to_email' => $fallback['reply_to_email'] ?? null,
            ],
            'last_error' => null,
            'last_tested_at' => now(),
        ]);

        $this->tenantEmailSettingsService->setProviderDiagnostics(
            $tenantId,
            in_array($providerStatus, ['healthy', 'configured'], true) ? 'healthy' : 'configured',
            null,
            true
        );
    }

    protected function ensureSmsProviderSettings(int $tenantId): void
    {
        if (! Schema::hasTable('tenant_marketing_settings')) {
            return;
        }

        $existing = TenantMarketingSetting::query()
            ->where('tenant_id', $tenantId)
            ->where('key', 'candle_cash_integration_config')
            ->value('value');

        $payload = is_array($existing) ? $existing : [];
        $payload = array_merge($payload, [
            'sms_provider' => 'twilio',
            'sms_provider_enabled' => true,
        ]);

        TenantMarketingSetting::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'key' => 'candle_cash_integration_config',
            ],
            [
                'value' => $payload,
                'description' => 'Modern Forestry alpha defaults for messaging-enabled tenant marketing settings.',
            ]
        );
    }
}
