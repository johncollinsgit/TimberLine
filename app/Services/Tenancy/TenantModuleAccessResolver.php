<?php

namespace App\Services\Tenancy;

use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
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

    public function __construct(
        protected TenantResolver $tenantResolver
    ) {
    }

    /**
     * Resolve module access by tenant.
     *
     * @param  array<int,string>|null  $moduleKeys
     * @return array{
     *   tenant_id:?int,
     *   operating_mode:string,
     *   plan_key:string,
     *   modules:array<string,array{
     *     module_key:string,
     *     label:string,
     *     classification:string,
     *     has_access:bool,
     *     access_sources:array<int,string>,
     *     setup_status:string,
     *     coming_soon:bool,
     *     ui_state:string,
     *     upgrade_prompt_eligible:bool
     *   }>
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
        $keys = $this->requestedModuleKeys($moduleKeys);

        $modules = [];
        foreach ($keys as $moduleKey) {
            $definition = $this->moduleDefinition($moduleKey);

            $sources = [];
            $hasAccess = false;

            if (in_array($moduleKey, $planIncludes, true)) {
                $hasAccess = true;
                $sources[] = 'plan:' . $planKey;
            }

            foreach ($enabledAddons as $addon) {
                $addonKey = strtolower(trim((string) ($addon['addon_key'] ?? '')));
                if ($addonKey === '') {
                    continue;
                }

                if (in_array($moduleKey, $this->addonIncludes($addonKey), true)) {
                    $hasAccess = true;
                    $sources[] = 'addon:' . $addonKey;
                }
            }

            $stateRow = $stateRows[$moduleKey] ?? null;
            if ($stateRow !== null && array_key_exists('enabled_override', $stateRow) && $stateRow['enabled_override'] !== null) {
                $hasAccess = (bool) $stateRow['enabled_override'];
                $sources[] = 'override:enabled';
            }

            $defaultSetup = strtolower(trim((string) ($definition['default_setup_status'] ?? 'not_started')));
            $setupStatus = $stateRow !== null
                ? $this->normalizeSetupStatus((string) ($stateRow['setup_status'] ?? $defaultSetup))
                : $this->normalizeSetupStatus($defaultSetup);

            $comingSoon = (bool) ($definition['coming_soon'] ?? false);
            if ($stateRow !== null && array_key_exists('coming_soon_override', $stateRow) && $stateRow['coming_soon_override'] !== null) {
                $comingSoon = (bool) $stateRow['coming_soon_override'];
            }

            $supportsUpgradePrompt = (bool) ($definition['supports_upgrade_prompt'] ?? true);
            if ($stateRow !== null && array_key_exists('upgrade_prompt_override', $stateRow) && $stateRow['upgrade_prompt_override'] !== null) {
                $supportsUpgradePrompt = (bool) $stateRow['upgrade_prompt_override'];
            }

            $uiState = $this->uiState(
                hasAccess: $hasAccess,
                setupStatus: $setupStatus,
                comingSoon: $comingSoon
            );

            $modules[$moduleKey] = [
                'module_key' => $moduleKey,
                'label' => (string) ($definition['label'] ?? $moduleKey),
                'classification' => (string) ($definition['classification'] ?? 'shared-core'),
                'has_access' => $hasAccess,
                'access_sources' => $sources !== [] ? array_values(array_unique($sources)) : ['none'],
                'setup_status' => $setupStatus,
                'coming_soon' => $comingSoon,
                'ui_state' => $uiState,
                'upgrade_prompt_eligible' => $this->upgradePromptEligible($hasAccess, $comingSoon, $supportsUpgradePrompt),
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
     * Resolve module access using store context.
     *
     * @param  array<string,mixed>  $storeContext
     * @param  array<int,string>|null  $moduleKeys
     * @return array{
     *   tenant_id:?int,
     *   operating_mode:string,
     *   plan_key:string,
     *   modules:array<string,array{
     *     module_key:string,
     *     label:string,
     *     classification:string,
     *     has_access:bool,
     *     access_sources:array<int,string>,
     *     setup_status:string,
     *     coming_soon:bool,
     *     ui_state:string,
     *     upgrade_prompt_eligible:bool
     *   }>
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

        return (bool) ($module['has_access'] ?? false);
    }

    public function module(?int $tenantId, string $moduleKey): array
    {
        $resolved = $this->resolveForTenant($tenantId, [$moduleKey]);

        return $resolved['modules'][$moduleKey] ?? [
            'module_key' => $moduleKey,
            'label' => $moduleKey,
            'classification' => 'shared-core',
            'has_access' => false,
            'access_sources' => ['none'],
            'setup_status' => 'not_started',
            'coming_soon' => false,
            'ui_state' => 'locked',
            'upgrade_prompt_eligible' => true,
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
            fn ($value): string => strtolower(trim((string) $value)),
            $moduleKeys
        )));

        return array_values(array_filter(
            array_unique($normalized),
            fn (string $key): bool => array_key_exists($key, $catalog)
        ));
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
            'operating_mode' => strtolower(trim((string) $row->operating_mode)) ?: $this->defaultOperatingMode(),
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
                $moduleKey = strtolower(trim((string) $row->module_key));
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
     * @return array<string,mixed>
     */
    protected function moduleDefinition(string $moduleKey): array
    {
        $catalog = $this->moduleCatalog();

        return $catalog[$moduleKey] ?? [
            'label' => $moduleKey,
            'classification' => 'shared-core',
            'default_setup_status' => 'not_started',
            'coming_soon' => false,
            'supports_upgrade_prompt' => true,
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

        return array_values(array_filter(array_map(
            fn ($value): string => strtolower(trim((string) $value)),
            $includes
        )));
    }

    /**
     * @return array<int,string>
     */
    protected function addonIncludes(string $addonKey): array
    {
        $addons = $this->addonCatalog();
        $addon = $addons[$addonKey] ?? [];
        $includes = is_array($addon['includes'] ?? null) ? $addon['includes'] : [];

        return array_values(array_filter(array_map(
            fn ($value): string => strtolower(trim((string) $value)),
            $includes
        )));
    }

    protected function uiState(bool $hasAccess, string $setupStatus, bool $comingSoon): string
    {
        if ($comingSoon) {
            return 'coming_soon';
        }

        if (! $hasAccess) {
            return 'locked';
        }

        return $setupStatus === 'configured'
            ? 'active'
            : 'setup_needed';
    }

    protected function upgradePromptEligible(bool $hasAccess, bool $comingSoon, bool $supportsUpgradePrompt): bool
    {
        if ($comingSoon || $hasAccess) {
            return false;
        }

        return $supportsUpgradePrompt;
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

        $alias = config('commercial.legacy_plan_aliases.'.$normalized);
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
            $normalizedKey = strtolower(trim((string) $key));
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
}
