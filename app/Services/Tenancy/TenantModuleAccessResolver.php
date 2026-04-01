<?php

namespace App\Services\Tenancy;

use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use Illuminate\Support\Facades\Schema;

class TenantModuleAccessResolver
{
    /**
     * @var array<string,array<string,mixed>>
     */
    protected array $moduleCatalog = [];

    /**
     * @var array<string,array<string,mixed>>
     */
    protected array $planCatalog = [];

    /**
     * @var array<string,array<string,mixed>>
     */
    protected array $addonCatalog = [];

    /**
     * @var array<int|string,array<string,mixed>>
     */
    protected array $profileCache = [];

    /**
     * @var array<int,array<int,array<string,mixed>>>
     */
    protected array $addonsCache = [];

    /**
     * @var array<int,array<string,array<string,mixed>>>
     */
    protected array $moduleStateCache = [];

    /**
     * @var array<int,array<string,array<string,mixed>>>
     */
    protected array $moduleEntitlementCache = [];

    public function __construct(
        protected TenantResolver $tenantResolver
    ) {
    }

    /**
     * @param  array<int,string>|null  $moduleKeys
     * @return array{
     *   tenant_id:?int,
     *   operating_mode:string,
     *   plan_key:string,
     *   modules:array<string,array<string,mixed>>
     * }
     */
    public function resolveForTenant(?int $tenantId, ?array $moduleKeys = null): array
    {
        $profile = $this->profileForTenant($tenantId);
        $planKey = $this->canonicalPlanKey((string) ($profile['plan_key'] ?? $this->defaultPlanKey()));
        $operatingMode = (string) ($profile['operating_mode'] ?? $this->defaultOperatingMode());

        $planIncludes = $this->planIncludes($planKey);
        $enabledAddons = $this->enabledAddonsForTenant($tenantId);
        $stateRows = $this->moduleStateRowsForTenant($tenantId);
        $entitlementRows = $this->moduleEntitlementRowsForTenant($tenantId);
        $requestedKeys = $this->requestedModuleKeys($moduleKeys);
        $keys = $this->expandModuleKeysWithDependencies($requestedKeys);

        $rawModules = [];
        foreach ($keys as $moduleKey) {
            $definition = $this->moduleDefinition($moduleKey);

            $sources = [];
            $hasAccess = false;
            $source = 'flag';

            if (in_array($moduleKey, $planIncludes, true)) {
                $hasAccess = true;
                $source = 'plan';
                $sources[] = 'plan:' . $planKey;
            }

            foreach ($enabledAddons as $addon) {
                $addonKey = strtolower(trim((string) ($addon['addon_key'] ?? '')));
                if ($addonKey === '') {
                    continue;
                }

                if (in_array($moduleKey, $this->addonIncludes($addonKey), true)) {
                    $hasAccess = true;
                    $source = $source === 'plan' ? $source : 'addon';
                    $sources[] = 'addon:' . $addonKey;
                }
            }

            $entitlementRow = $entitlementRows[$moduleKey] ?? null;
            if ($entitlementRow !== null) {
                $enabledStatus = strtolower(trim((string) ($entitlementRow['enabled_status'] ?? 'inherit')));
                if ($enabledStatus === 'enabled') {
                    $hasAccess = true;
                    $source = $this->normalizeDecisionSource((string) ($entitlementRow['entitlement_source'] ?? 'override'));
                    $sources[] = 'entitlement:' . ($entitlementRow['entitlement_source'] ?? 'override');
                } elseif ($enabledStatus === 'disabled') {
                    $hasAccess = false;
                    $source = $this->normalizeDecisionSource((string) ($entitlementRow['entitlement_source'] ?? 'override'));
                    $sources[] = 'entitlement:' . ($entitlementRow['entitlement_source'] ?? 'override');
                }
            }

            $stateRow = $stateRows[$moduleKey] ?? null;
            if ($stateRow !== null && array_key_exists('enabled_override', $stateRow) && $stateRow['enabled_override'] !== null) {
                $hasAccess = (bool) $stateRow['enabled_override'];
                $source = 'override';
                $sources[] = 'override:enabled';
            }

            $defaultSetup = strtolower(trim((string) ($definition['default_setup_status'] ?? 'not_started')));
            $setupStatus = $stateRow !== null
                ? $this->normalizeSetupStatus((string) ($stateRow['setup_status'] ?? $defaultSetup))
                : $this->normalizeSetupStatus($defaultSetup);

            $status = strtolower(trim((string) ($definition['status'] ?? 'disabled')));
            $comingSoon = in_array($status, ['placeholder', 'roadmap'], true);
            if ($stateRow !== null && array_key_exists('coming_soon_override', $stateRow) && $stateRow['coming_soon_override'] !== null) {
                $comingSoon = (bool) $stateRow['coming_soon_override'];
            }

            $supportsUpgradePrompt = (bool) ($definition['supports_upgrade_prompt'] ?? true);
            if ($stateRow !== null && array_key_exists('upgrade_prompt_override', $stateRow) && $stateRow['upgrade_prompt_override'] !== null) {
                $supportsUpgradePrompt = (bool) $stateRow['upgrade_prompt_override'];
            }

            $rawModules[$moduleKey] = [
                'module_key' => $moduleKey,
                'label' => (string) ($definition['label'] ?? $moduleKey),
                'description' => (string) ($definition['description'] ?? ''),
                'classification' => (string) ($definition['classification'] ?? 'shared-core'),
                'status' => $status,
                'channels' => $this->normalizedStringList((array) ($definition['channels'] ?? [])),
                'billing_mode' => strtolower(trim((string) ($definition['billing_mode'] ?? 'unavailable'))),
                'dependencies' => $this->normalizedStringList((array) ($definition['dependencies'] ?? [])),
                'capabilities' => $this->normalizedCapabilityList((array) ($definition['capabilities'] ?? [])),
                'visibility' => is_array($definition['visibility'] ?? null) ? (array) $definition['visibility'] : [],
                'cta_routing' => strtolower(trim((string) ($definition['cta_routing'] ?? 'none'))),
                'market_state' => strtoupper(trim((string) ($definition['market_state'] ?? 'INTERNAL_ONLY'))),
                'default_enabled' => (bool) ($definition['default_enabled'] ?? false),
                'has_access_raw' => $hasAccess,
                'source_raw' => $source,
                'access_sources' => $sources !== [] ? array_values(array_unique($sources)) : ['none'],
                'setup_status' => $setupStatus,
                'coming_soon' => $comingSoon,
                'supports_upgrade_prompt' => $supportsUpgradePrompt,
                'billing_status' => $this->nullableString($entitlementRow['billing_status'] ?? null),
                'entitlement_source' => $this->nullableString($entitlementRow['entitlement_source'] ?? null),
                'price_source' => $this->nullableString($entitlementRow['price_source'] ?? null),
                'availability_status' => strtolower(trim((string) ($entitlementRow['availability_status'] ?? 'available'))),
                'enabled_status' => strtolower(trim((string) ($entitlementRow['enabled_status'] ?? 'inherit'))),
            ];
        }

        $modules = [];
        foreach ($requestedKeys as $moduleKey) {
            $rawModule = $rawModules[$moduleKey] ?? null;
            if (! is_array($rawModule)) {
                continue;
            }

            $decision = $this->decisionForModule(
                module: $rawModule,
                allModules: $rawModules,
                operatingMode: $operatingMode,
                planKey: $planKey
            );

            $uiState = $this->uiState(
                enabled: $decision['enabled'],
                setupStatus: (string) ($rawModule['setup_status'] ?? 'not_started'),
                comingSoon: (bool) ($rawModule['coming_soon'] ?? false),
                reason: (string) ($decision['reason'] ?? 'not_enabled')
            );

            $upgradePromptEligible = $this->upgradePromptEligible(
                enabled: (bool) ($decision['enabled'] ?? false),
                comingSoon: (bool) ($rawModule['coming_soon'] ?? false),
                supportsUpgradePrompt: (bool) ($rawModule['supports_upgrade_prompt'] ?? true),
                cta: (string) ($decision['cta'] ?? 'none')
            );

            $modules[$moduleKey] = [
                'module_key' => $moduleKey,
                'label' => (string) ($rawModule['label'] ?? $moduleKey),
                'description' => (string) ($rawModule['description'] ?? ''),
                'classification' => (string) ($rawModule['classification'] ?? 'shared-core'),
                'status' => (string) ($rawModule['status'] ?? 'disabled'),
                'channels' => (array) ($rawModule['channels'] ?? []),
                'billing_mode' => (string) ($rawModule['billing_mode'] ?? 'unavailable'),
                'dependencies' => (array) ($rawModule['dependencies'] ?? []),
                'capabilities' => (array) ($rawModule['capabilities'] ?? []),
                'visibility' => (array) ($rawModule['visibility'] ?? []),
                'market_state' => (string) ($rawModule['market_state'] ?? 'INTERNAL_ONLY'),
                'has_access' => (bool) ($decision['enabled'] ?? false),
                'enabled' => (bool) ($decision['enabled'] ?? false),
                'access_sources' => (array) ($rawModule['access_sources'] ?? ['none']),
                'source' => (string) ($decision['source'] ?? 'flag'),
                'reason' => (string) ($decision['reason'] ?? 'not_enabled'),
                'cta' => (string) ($decision['cta'] ?? 'none'),
                'cta_routing' => (string) ($decision['cta_routing'] ?? 'none'),
                'setup_status' => (string) ($rawModule['setup_status'] ?? 'not_started'),
                'coming_soon' => (bool) ($rawModule['coming_soon'] ?? false),
                'ui_state' => $uiState,
                'upgrade_prompt_eligible' => $upgradePromptEligible,
                'billing_status' => $this->nullableString($rawModule['billing_status'] ?? null),
                'availability_status' => (string) ($rawModule['availability_status'] ?? 'available'),
                'entitlement_source' => $this->nullableString($rawModule['entitlement_source'] ?? null),
                'price_source' => $this->nullableString($rawModule['price_source'] ?? null),
            ];
        }

        return [
            'tenant_id' => $tenantId,
            'operating_mode' => $operatingMode,
            'plan_key' => $planKey,
            'modules' => $modules,
        ];
    }

    /**
     * @param  array<string,mixed>  $storeContext
     * @param  array<int,string>|null  $moduleKeys
     * @return array{
     *   tenant_id:?int,
     *   operating_mode:string,
     *   plan_key:string,
     *   modules:array<string,array<string,mixed>>
     * }
     */
    public function resolveForStoreContext(array $storeContext, ?array $moduleKeys = null): array
    {
        $tenantId = $this->tenantResolver->resolveTenantIdForStoreContext($storeContext);

        return $this->resolveForTenant($tenantId, $moduleKeys);
    }

    public function canAccess(?int $tenantId, string $moduleKey): bool
    {
        $module = $this->module($tenantId, $moduleKey);

        return (bool) ($module['enabled'] ?? false);
    }

    public function module(?int $tenantId, string $moduleKey): array
    {
        $resolved = $this->resolveForTenant($tenantId, [$moduleKey]);
        $canonicalKey = $this->canonicalModuleKey($moduleKey);

        return $resolved['modules'][$canonicalKey] ?? [
            'module_key' => $canonicalKey,
            'label' => $canonicalKey,
            'description' => '',
            'classification' => 'shared-core',
            'status' => 'disabled',
            'channels' => [],
            'billing_mode' => 'unavailable',
            'dependencies' => [],
            'capabilities' => [],
            'visibility' => [],
            'market_state' => 'INTERNAL_ONLY',
            'has_access' => false,
            'enabled' => false,
            'access_sources' => ['none'],
            'source' => 'flag',
            'reason' => 'not_enabled',
            'cta' => 'none',
            'cta_routing' => 'none',
            'setup_status' => 'not_started',
            'coming_soon' => false,
            'ui_state' => 'locked',
            'upgrade_prompt_eligible' => false,
            'billing_status' => null,
            'availability_status' => 'available',
            'entitlement_source' => null,
            'price_source' => null,
        ];
    }

    /**
     * @param  array<int,string>|null  $moduleKeys
     * @return array<int,string>
     */
    protected function requestedModuleKeys(?array $moduleKeys): array
    {
        $catalog = $this->moduleCatalog();
        if ($moduleKeys === null || $moduleKeys === []) {
            return array_keys($catalog);
        }

        $normalized = array_values(array_filter(array_map(
            fn ($value): string => $this->canonicalModuleKey((string) $value),
            $moduleKeys
        )));

        return array_values(array_filter(
            array_unique($normalized),
            fn (string $key): bool => array_key_exists($key, $catalog)
        ));
    }

    /**
     * @param  array<int,string>  $moduleKeys
     * @return array<int,string>
     */
    protected function expandModuleKeysWithDependencies(array $moduleKeys): array
    {
        $expanded = [];
        $pending = array_values($moduleKeys);

        while ($pending !== []) {
            $moduleKey = array_shift($pending);
            if (! is_string($moduleKey) || $moduleKey === '' || isset($expanded[$moduleKey])) {
                continue;
            }

            $expanded[$moduleKey] = $moduleKey;
            foreach ((array) ($this->moduleDefinition($moduleKey)['dependencies'] ?? []) as $dependencyKey) {
                $canonicalDependency = $this->canonicalModuleKey((string) $dependencyKey);
                if ($canonicalDependency !== '' && ! isset($expanded[$canonicalDependency])) {
                    $pending[] = $canonicalDependency;
                }
            }
        }

        return array_values($expanded);
    }

    /**
     * @return array<string,mixed>
     */
    protected function profileForTenant(?int $tenantId): array
    {
        $cacheKey = $tenantId ?? 'none';
        if (array_key_exists($cacheKey, $this->profileCache)) {
            return $this->profileCache[$cacheKey];
        }

        $default = [
            'tenant_id' => $tenantId,
            'plan_key' => $this->defaultPlanKey(),
            'operating_mode' => $this->defaultOperatingMode(),
            'source' => 'config_default',
        ];

        if ($tenantId === null || ! Schema::hasTable('tenant_access_profiles')) {
            return $this->profileCache[$cacheKey] = $default;
        }

        $row = TenantAccessProfile::query()
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $row) {
            return $this->profileCache[$cacheKey] = $default;
        }

        return $this->profileCache[$cacheKey] = [
            'tenant_id' => (int) $row->tenant_id,
            'plan_key' => $this->canonicalPlanKey((string) $row->plan_key),
            'operating_mode' => strtolower(trim((string) ($row->operating_mode ?? ''))) ?: $this->defaultOperatingMode(),
            'source' => strtolower(trim((string) ($row->source ?? 'manual'))) ?: 'manual',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function enabledAddonsForTenant(?int $tenantId): array
    {
        if ($tenantId === null || ! Schema::hasTable('tenant_access_addons')) {
            return [];
        }

        if (array_key_exists($tenantId, $this->addonsCache)) {
            return $this->addonsCache[$tenantId];
        }

        $rows = TenantAccessAddon::query()
            ->forTenantId($tenantId)
            ->where('enabled', true)
            ->where(function ($query): void {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            })
            ->get(['addon_key', 'enabled', 'starts_at', 'ends_at'])
            ->map(fn (TenantAccessAddon $row): array => [
                'addon_key' => strtolower(trim((string) $row->addon_key)),
                'enabled' => (bool) $row->enabled,
                'starts_at' => optional($row->starts_at)->toIso8601String(),
                'ends_at' => optional($row->ends_at)->toIso8601String(),
            ])
            ->values()
            ->all();

        $this->addonsCache[$tenantId] = $rows;

        return $rows;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    protected function moduleStateRowsForTenant(?int $tenantId): array
    {
        if ($tenantId === null || ! Schema::hasTable('tenant_module_states')) {
            return [];
        }

        if (array_key_exists($tenantId, $this->moduleStateCache)) {
            return $this->moduleStateCache[$tenantId];
        }

        $rows = TenantModuleState::query()
            ->forTenantId($tenantId)
            ->get(['module_key', 'enabled_override', 'setup_status', 'coming_soon_override', 'upgrade_prompt_override'])
            ->mapWithKeys(function (TenantModuleState $row): array {
                $moduleKey = $this->canonicalModuleKey((string) $row->module_key);
                if ($moduleKey === '') {
                    return [];
                }

                return [
                    $moduleKey => [
                        'enabled_override' => $row->getRawOriginal('enabled_override') === null
                            ? null
                            : (bool) $row->enabled_override,
                        'setup_status' => (string) ($row->setup_status ?? ''),
                        'coming_soon_override' => $row->getRawOriginal('coming_soon_override') === null
                            ? null
                            : (bool) $row->coming_soon_override,
                        'upgrade_prompt_override' => $row->getRawOriginal('upgrade_prompt_override') === null
                            ? null
                            : (bool) $row->upgrade_prompt_override,
                    ],
                ];
            })
            ->all();

        $this->moduleStateCache[$tenantId] = $rows;

        return $rows;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    protected function moduleEntitlementRowsForTenant(?int $tenantId): array
    {
        if ($tenantId === null || ! Schema::hasTable('tenant_module_entitlements')) {
            return [];
        }

        if (array_key_exists($tenantId, $this->moduleEntitlementCache)) {
            return $this->moduleEntitlementCache[$tenantId];
        }

        $rows = TenantModuleEntitlement::query()
            ->forTenantId($tenantId)
            ->where(function ($query): void {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            })
            ->get([
                'module_key',
                'availability_status',
                'enabled_status',
                'billing_status',
                'entitlement_source',
                'price_source',
            ])
            ->mapWithKeys(function (TenantModuleEntitlement $row): array {
                $moduleKey = $this->canonicalModuleKey((string) $row->module_key);
                if ($moduleKey === '') {
                    return [];
                }

                return [
                    $moduleKey => [
                        'availability_status' => strtolower(trim((string) ($row->availability_status ?? 'available'))),
                        'enabled_status' => strtolower(trim((string) ($row->enabled_status ?? 'inherit'))),
                        'billing_status' => $this->nullableString($row->billing_status),
                        'entitlement_source' => $this->nullableString($row->entitlement_source),
                        'price_source' => $this->nullableString($row->price_source),
                    ],
                ];
            })
            ->all();

        $this->moduleEntitlementCache[$tenantId] = $rows;

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $module
     * @param  array<string,array<string,mixed>>  $allModules
     * @return array{enabled:bool,reason:string,source:string,cta:string,cta_routing:string}
     */
    protected function decisionForModule(array $module, array $allModules, string $operatingMode, string $planKey): array
    {
        $status = strtolower(trim((string) ($module['status'] ?? 'disabled')));
        $ctaRouting = strtolower(trim((string) ($module['cta_routing'] ?? 'none')));
        $source = $this->normalizeDecisionSource((string) ($module['source_raw'] ?? 'flag'));
        $enabled = (bool) ($module['has_access_raw'] ?? false);
        $reason = $enabled ? 'enabled' : 'not_enabled';

        $channels = $this->normalizedStringList((array) ($module['channels'] ?? []));
        if (! $this->operatingModeSupportsChannels($operatingMode, $channels)) {
            return [
                'enabled' => false,
                'reason' => 'channel_not_supported',
                'source' => 'flag',
                'cta' => 'none',
                'cta_routing' => $ctaRouting,
            ];
        }

        $availabilityStatus = strtolower(trim((string) ($module['availability_status'] ?? 'available')));
        if (in_array($availabilityStatus, ['unavailable', 'disabled'], true) || $status === 'disabled') {
            return [
                'enabled' => false,
                'reason' => 'module_unavailable',
                'source' => 'flag',
                'cta' => 'none',
                'cta_routing' => $ctaRouting,
            ];
        }

        if ($enabled) {
            foreach ((array) ($module['dependencies'] ?? []) as $dependencyKey) {
                $dependency = is_array($allModules[$dependencyKey] ?? null) ? $allModules[$dependencyKey] : null;
                if ($dependency === null || ! (bool) ($dependency['has_access_raw'] ?? false)) {
                    return [
                        'enabled' => false,
                        'reason' => 'dependency_not_enabled',
                        'source' => 'flag',
                        'cta' => $this->dependencyCta($dependencyKey, $allModules, $planKey),
                        'cta_routing' => $ctaRouting,
                    ];
                }
            }
        }

        if ($enabled) {
            if (($module['enabled_status'] ?? 'inherit') === 'disabled') {
                $reason = 'disabled_by_entitlement';
                $enabled = false;
                $source = 'override';
            } elseif (in_array((string) ($module['setup_status'] ?? 'not_started'), ['not_started', 'in_progress', 'blocked'], true)) {
                $reason = 'setup_required';
            }
        }

        if (! $enabled) {
            if ($source === 'override' || ($module['enabled_status'] ?? 'inherit') === 'disabled') {
                $reason = 'disabled_by_override';
            } elseif (($module['billing_mode'] ?? 'unavailable') === 'add_on') {
                $reason = 'add_on_required';
            } elseif (($module['billing_mode'] ?? 'unavailable') === 'custom') {
                $reason = 'contact_sales_required';
            } elseif ($this->moduleHasFutureStatus($status)) {
                $reason = 'rollout_pending';
            } else {
                $reason = 'plan_upgrade_required';
            }
        }

        return [
            'enabled' => $enabled,
            'reason' => $reason,
            'source' => $source,
            'cta' => $enabled ? 'none' : $this->ctaForReason($reason, $ctaRouting),
            'cta_routing' => $enabled ? 'none' : $ctaRouting,
        ];
    }

    protected function dependencyCta(string $dependencyKey, array $allModules, string $planKey): string
    {
        $dependency = is_array($allModules[$dependencyKey] ?? null) ? $allModules[$dependencyKey] : null;
        if ($dependency === null) {
            return 'none';
        }

        $billingMode = strtolower(trim((string) ($dependency['billing_mode'] ?? 'unavailable')));
        if ($billingMode === 'add_on') {
            return 'add';
        }

        return 'upgrade';
    }

    protected function ctaForReason(string $reason, string $ctaRouting): string
    {
        return match ($reason) {
            'add_on_required' => 'add',
            'contact_sales_required' => 'request',
            'rollout_pending' => $this->normalizeCta($ctaRouting),
            'channel_not_supported',
            'module_unavailable',
            'dependency_not_enabled' => $this->normalizeCta($ctaRouting),
            default => $this->normalizeCta($ctaRouting),
        };
    }

    protected function normalizeCta(string $ctaRouting): string
    {
        return match (strtolower(trim($ctaRouting))) {
            'upgrade_plan' => 'upgrade',
            'add_module' => 'add',
            'request_access', 'contact_sales' => 'request',
            default => 'none',
        };
    }

    protected function uiState(bool $enabled, string $setupStatus, bool $comingSoon, string $reason): string
    {
        if ($comingSoon || $reason === 'rollout_pending') {
            return 'coming_soon';
        }

        if (! $enabled) {
            return 'locked';
        }

        return $setupStatus === 'configured'
            ? 'active'
            : 'setup_needed';
    }

    protected function upgradePromptEligible(bool $enabled, bool $comingSoon, bool $supportsUpgradePrompt, string $cta): bool
    {
        if ($enabled || $comingSoon || ! $supportsUpgradePrompt) {
            return false;
        }

        return in_array($cta, ['upgrade', 'add'], true);
    }

    protected function moduleHasFutureStatus(string $status): bool
    {
        return in_array($status, ['placeholder', 'roadmap'], true);
    }

    protected function normalizeSetupStatus(string $status): string
    {
        $status = strtolower(trim($status));
        $allowed = (array) config('entitlements.setup_statuses', ['not_started', 'in_progress', 'configured', 'blocked']);

        return in_array($status, $allowed, true) ? $status : 'not_started';
    }

    protected function defaultPlanKey(): string
    {
        return strtolower(trim((string) config('entitlements.default_plan', 'starter')));
    }

    protected function canonicalPlanKey(string $planKey): string
    {
        $normalized = strtolower(trim($planKey));
        if ($normalized === '') {
            return $this->defaultPlanKey();
        }

        $alias = config('commercial.legacy_plan_aliases.' . $normalized);
        if (is_string($alias) && trim($alias) !== '') {
            return strtolower(trim($alias));
        }

        return $normalized;
    }

    protected function canonicalModuleKey(string $moduleKey): string
    {
        $normalized = strtolower(trim($moduleKey));
        if ($normalized === '') {
            return '';
        }

        $alias = config('module_catalog.legacy.module_aliases.' . $normalized);
        if (is_string($alias) && trim($alias) !== '') {
            return strtolower(trim($alias));
        }

        return $normalized;
    }

    protected function defaultOperatingMode(): string
    {
        return strtolower(trim((string) config('entitlements.default_operating_mode', 'shopify')));
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    protected function moduleCatalog(): array
    {
        if ($this->moduleCatalog !== []) {
            return $this->moduleCatalog;
        }

        $configured = (array) config('entitlements.modules', []);
        $normalized = [];
        foreach ($configured as $key => $value) {
            $normalizedKey = $this->canonicalModuleKey((string) $key);
            if ($normalizedKey === '' || ! is_array($value)) {
                continue;
            }
            $normalized[$normalizedKey] = $value;
        }

        $this->moduleCatalog = $normalized;

        return $this->moduleCatalog;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    protected function planCatalog(): array
    {
        if ($this->planCatalog !== []) {
            return $this->planCatalog;
        }

        $configured = (array) config('entitlements.plans', []);
        $normalized = [];
        foreach ($configured as $key => $value) {
            $normalizedKey = strtolower(trim((string) $key));
            if ($normalizedKey === '' || ! is_array($value)) {
                continue;
            }
            $normalized[$normalizedKey] = $value;
        }

        $this->planCatalog = $normalized;

        return $this->planCatalog;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    protected function addonCatalog(): array
    {
        if ($this->addonCatalog !== []) {
            return $this->addonCatalog;
        }

        $configured = (array) config('entitlements.addons', []);
        $normalized = [];
        foreach ($configured as $key => $value) {
            $normalizedKey = strtolower(trim((string) $key));
            if ($normalizedKey === '' || ! is_array($value)) {
                continue;
            }
            $normalized[$normalizedKey] = $value;
        }

        $this->addonCatalog = $normalized;

        return $this->addonCatalog;
    }

    /**
     * @return array<string,mixed>
     */
    protected function moduleDefinition(string $moduleKey): array
    {
        $catalog = $this->moduleCatalog();
        $definition = is_array($catalog[$moduleKey] ?? null) ? (array) $catalog[$moduleKey] : [];
        $canonical = is_array(config('module_catalog.modules.' . $moduleKey))
            ? (array) config('module_catalog.modules.' . $moduleKey)
            : [];

        $canonicalString = static function (string $key, ?string $fallback = null) use ($canonical, $definition): string {
            if (array_key_exists($key, $canonical)) {
                return (string) $canonical[$key];
            }

            if (array_key_exists($key, $definition)) {
                return (string) $definition[$key];
            }

            return (string) ($fallback ?? '');
        };

        $canonicalArray = static function (string $key, array $fallback = []) use ($canonical, $definition): array {
            if (array_key_exists($key, $canonical) && is_array($canonical[$key])) {
                return (array) $canonical[$key];
            }

            if (array_key_exists($key, $definition) && is_array($definition[$key])) {
                return (array) $definition[$key];
            }

            return $fallback;
        };

        $canonicalBool = static function (string $key, bool $fallback = false) use ($canonical, $definition): bool {
            if (array_key_exists($key, $canonical)) {
                return (bool) $canonical[$key];
            }

            if (array_key_exists($key, $definition)) {
                return (bool) $definition[$key];
            }

            return $fallback;
        };

        return [
            'label' => (string) ($definition['label'] ?? $canonical['display_name'] ?? $moduleKey),
            'description' => $canonicalString('description'),
            'classification' => (string) ($definition['classification'] ?? $canonical['classification'] ?? 'shared-core'),
            'default_setup_status' => (string) ($definition['default_setup_status'] ?? $canonical['default_setup_status'] ?? 'not_started'),
            'coming_soon' => (bool) ($definition['coming_soon'] ?? false),
            'supports_upgrade_prompt' => (bool) ($definition['supports_upgrade_prompt'] ?? true),
            'status' => $canonicalString('status', 'disabled'),
            'channels' => $canonicalArray('channels'),
            'billing_mode' => $canonicalString('billing_mode', 'unavailable'),
            'dependencies' => $canonicalArray('dependencies'),
            'capabilities' => $canonicalArray('capabilities'),
            'visibility' => $canonicalArray('visibility'),
            'cta_routing' => $canonicalString('cta_routing', 'none'),
            'market_state' => $canonicalString('market_state', 'INTERNAL_ONLY'),
            'default_enabled' => $canonicalBool('default_enabled'),
        ];
    }

    /**
     * @return array<int,string>
     */
    protected function planIncludes(string $planKey): array
    {
        $plans = $this->planCatalog();
        $resolvedKey = array_key_exists($planKey, $plans) ? $planKey : $this->defaultPlanKey();
        $plan = $plans[$resolvedKey] ?? [];
        $includes = is_array($plan['includes'] ?? null) ? $plan['includes'] : [];

        return $this->normalizedStringList($includes);
    }

    /**
     * @return array<int,string>
     */
    protected function addonIncludes(string $addonKey): array
    {
        $addons = $this->addonCatalog();
        $addon = $addons[$addonKey] ?? [];
        $includes = is_array($addon['includes'] ?? null) ? $addon['includes'] : [];

        return $this->normalizedStringList($includes);
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    protected function normalizedStringList(array $values): array
    {
        return array_values(array_filter(array_map(function ($value): string {
            $normalized = strtolower(trim((string) $value));

            return $this->canonicalModuleKey($normalized);
        }, $values)));
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    protected function normalizedCapabilityList(array $values): array
    {
        return array_values(array_filter(array_unique(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            $values
        ))));
    }

    /**
     * @param  array<int,string>  $channels
     */
    protected function operatingModeSupportsChannels(string $operatingMode, array $channels): bool
    {
        if ($channels === [] || in_array('both', $channels, true)) {
            return true;
        }

        $normalizedMode = strtolower(trim($operatingMode));

        if ($normalizedMode === 'shopify') {
            return in_array('shopify', $channels, true);
        }

        return in_array('backstage', $channels, true);
    }

    protected function normalizeDecisionSource(string $source): string
    {
        return match (strtolower(trim($source))) {
            'plan', 'addon', 'override', 'flag' => strtolower(trim($source)),
            'manual', 'landlord_console', 'entitlement', 'contract', 'purchase' => 'override',
            default => 'flag',
        };
    }

    protected function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
