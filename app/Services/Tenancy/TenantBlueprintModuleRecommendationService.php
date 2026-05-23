<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\TenantSetupStatus;
use Illuminate\Support\Str;

class TenantBlueprintModuleRecommendationService
{
    /**
     * @var array<string,string>
     */
    protected const DISPLAY_STATE_LABELS = [
        'active' => 'Active',
        'available' => 'Available',
        'recommended' => 'Recommended for your setup',
        'requested' => 'Requested in your setup',
        'planned' => 'Planned',
        'future' => 'Future module',
        'requires_setup' => 'Requires Everbranch setup',
        'unavailable' => 'Unavailable',
        'not_active_yet' => 'Not active yet',
    ];

    /**
     * @var array<string,string>
     */
    protected const CATALOG_ALIASES = [
        'clients' => 'customers',
        'customer' => 'customers',
        'customers' => 'customers',
        'campaigns' => 'campaigns',
        'reports' => 'reporting',
        'reporting' => 'reporting',
        'rewards' => 'rewards',
        'retention' => 'rewards',
        'wishlist' => 'wishlist',
        'sms' => 'sms',
        'integrations' => 'integrations',
    ];

    /**
     * @var array<string,array<int,string>>
     */
    protected const WORK_INTENT_MODULES = [
        'wants_project_workspace' => ['projects'],
        'wants_task_management' => ['tasks'],
        'wants_user_assignments' => ['assignments'],
        'wants_team_communication' => ['team_communication'],
        'wants_client_communication' => ['client_communication'],
        'wants_photo_uploads' => ['photos'],
        'wants_file_uploads' => ['files'],
        'wants_mobile_field_capture' => ['mobile_field_capture'],
    ];

    public function __construct(
        protected TenantBlueprintProfileService $blueprints
    ) {
    }

    /**
     * @return array<string,string>
     */
    public function displayStateLabels(): array
    {
        return self::DISPLAY_STATE_LABELS;
    }

    /**
     * @param  array<int,array<string,mixed>>  $catalogModules
     * @return array<string,mixed>
     */
    public function forTenant(?int $tenantId, array $catalogModules = []): array
    {
        if (! is_int($tenantId) || $tenantId <= 0) {
            return $this->emptyPayload();
        }

        $tenant = Tenant::query()
            ->with(['accessProfile', 'setupStatus'])
            ->find($tenantId);

        if (! $tenant instanceof Tenant) {
            return $this->emptyPayload();
        }

        return $this->forTenantModel($tenant, $catalogModules);
    }

    /**
     * @param  array<int,array<string,mixed>>  $catalogModules
     * @return array<string,mixed>
     */
    public function forTenantModel(Tenant $tenant, array $catalogModules = []): array
    {
        $blueprint = $this->blueprints->payloadForTenant($tenant);
        $setupStatus = $tenant->relationLoaded('setupStatus') ? $tenant->setupStatus : null;
        $requestedModuleKeys = $setupStatus instanceof TenantSetupStatus
            ? $this->normalizeKeys((array) ($setupStatus->module_interests ?? []))
            : [];

        $catalogByKey = collect($catalogModules)
            ->filter(fn (mixed $module): bool => is_array($module))
            ->mapWithKeys(fn (array $module): array => [
                strtolower(trim((string) ($module['module_key'] ?? ''))) => $module,
            ])
            ->filter(fn (array $module, string $key): bool => $key !== '')
            ->all();

        $starterKeys = $this->normalizeKeys((array) ($blueprint['starter_modules'] ?? []));
        $workKeys = $this->normalizeKeys((array) ($blueprint['work_management_module_keys'] ?? []));
        $shopifyKeys = $this->shopifyRecommendationKeys($blueprint);
        $workIntentKeys = $this->workIntentRecommendationKeys((array) ($blueprint['work_management_intent'] ?? []));

        $orderedKeys = array_values(array_unique(array_merge(
            $shopifyKeys,
            $starterKeys,
            $workKeys,
            $workIntentKeys,
            $requestedModuleKeys
        )));

        $rows = [];
        foreach ($orderedKeys as $key) {
            $catalogKey = $this->catalogModuleKey($key, $catalogByKey);
            $state = $this->displayStateForKey(
                key: $key,
                catalogKey: $catalogKey,
                catalogModules: $catalogByKey,
                requestedModuleKeys: $requestedModuleKeys,
                workIntentKeys: $workIntentKeys,
                starterKeys: $starterKeys,
                workKeys: $workKeys
            );

            $rows[] = [
                'key' => $key,
                'label' => $this->labelForKey($key, $blueprint),
                'display_state' => $state,
                'display_state_label' => self::DISPLAY_STATE_LABELS[$state] ?? Str::headline($state),
                'catalog_module_key' => $catalogKey,
                'is_catalog_module' => $catalogKey !== null,
                'is_visible_catalog_module' => $catalogKey !== null && isset($catalogByKey[$catalogKey]),
                'requires_future_implementation' => $catalogKey === null,
                'reason' => $this->reasonForKey($key, $state, $blueprint),
            ];
        }

        $sections = [];
        foreach (array_keys(self::DISPLAY_STATE_LABELS) as $state) {
            $sections[$state] = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (string) ($row['display_state'] ?? '') === $state
            ));
        }

        return [
            'context' => [
                'business_template' => (string) ($blueprint['business_template'] ?? 'generic'),
                'business_template_label' => (string) ($blueprint['business_template_label'] ?? 'Generic'),
                'operating_mode' => (string) ($blueprint['operating_mode'] ?? 'custom_or_unknown'),
                'operating_mode_label' => (string) ($blueprint['operating_mode_label'] ?? 'Not sure yet'),
                'data_source_preference' => (string) ($blueprint['data_source_preference'] ?? 'undecided'),
                'account_mode' => (string) ($blueprint['account_mode'] ?? 'production'),
                'account_mode_label' => (string) ($blueprint['account_mode_label'] ?? 'Production'),
                'is_demo' => (string) ($blueprint['account_mode'] ?? '') === 'demo',
                'is_sandbox' => (string) ($blueprint['account_mode'] ?? '') === 'sandbox',
                'is_flagship_tenant' => (string) $tenant->slug === (string) config('tenancy.auth.flagship_tenant_slug', 'modern-forestry'),
            ],
            'display_state_labels' => self::DISPLAY_STATE_LABELS,
            'rows' => $rows,
            'sections' => $sections,
            'recommended_keys' => $this->keysForStates($rows, ['recommended']),
            'requested_keys' => $this->keysForStates($rows, ['requested']),
            'planned_keys' => $this->keysForStates($rows, ['planned', 'future', 'not_active_yet']),
            'summary' => [
                'recommended' => count($sections['recommended'] ?? []),
                'requested' => count($sections['requested'] ?? []),
                'planned_or_future' => count($sections['planned'] ?? []) + count($sections['future'] ?? []) + count($sections['not_active_yet'] ?? []),
                'requires_future_implementation' => collect($rows)->where('requires_future_implementation', true)->count(),
            ],
            'notice' => 'Setup recommendations are display-only. They do not install modules, change access, start billing, run imports, or activate future work-management features.',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $modules
     * @param  array<string,mixed>  $recommendations
     * @return array<int,array<string,mixed>>
     */
    public function decorateCatalogModules(array $modules, array $recommendations): array
    {
        $recommendationByCatalogKey = collect((array) ($recommendations['rows'] ?? []))
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['catalog_module_key'] ?? null))
            ->mapWithKeys(fn (array $row): array => [(string) $row['catalog_module_key'] => $row])
            ->all();

        return array_values(array_map(function (array $module) use ($recommendationByCatalogKey): array {
            $moduleKey = strtolower(trim((string) ($module['module_key'] ?? '')));
            $recommendation = is_array($recommendationByCatalogKey[$moduleKey] ?? null)
                ? (array) $recommendationByCatalogKey[$moduleKey]
                : null;

            $defaultState = $this->defaultCatalogDisplayState($module);
            $displayState = $recommendation !== null
                ? (string) ($recommendation['display_state'] ?? $defaultState)
                : $defaultState;

            $module['blueprint_display_state'] = $displayState;
            $module['blueprint_display_state_label'] = self::DISPLAY_STATE_LABELS[$displayState] ?? Str::headline($displayState);
            $module['blueprint_recommendation_reason'] = $recommendation['reason'] ?? $this->defaultCatalogReason($displayState);

            return $module;
        }, $modules));
    }

    /**
     * @return array<string,mixed>
     */
    protected function emptyPayload(): array
    {
        return [
            'context' => [],
            'display_state_labels' => self::DISPLAY_STATE_LABELS,
            'rows' => [],
            'sections' => [],
            'recommended_keys' => [],
            'requested_keys' => [],
            'planned_keys' => [],
            'summary' => [
                'recommended' => 0,
                'requested' => 0,
                'planned_or_future' => 0,
                'requires_future_implementation' => 0,
            ],
            'notice' => 'No setup recommendations are available yet.',
        ];
    }

    /**
     * @param  array<string,mixed>  $blueprint
     * @return array<int,string>
     */
    protected function shopifyRecommendationKeys(array $blueprint): array
    {
        $operatingMode = (string) ($blueprint['operating_mode'] ?? '');
        $dataSource = (string) ($blueprint['data_source_preference'] ?? '');

        if ($operatingMode !== 'shopify' && $dataSource !== 'shopify') {
            return [];
        }

        return ['customers', 'orders', 'products', 'campaigns', 'reports', 'rewards', 'wishlist'];
    }

    /**
     * @param  array<string,mixed>  $intent
     * @return array<int,string>
     */
    protected function workIntentRecommendationKeys(array $intent): array
    {
        $keys = [];
        foreach (self::WORK_INTENT_MODULES as $intentKey => $moduleKeys) {
            if (! (bool) ($intent[$intentKey] ?? false)) {
                continue;
            }

            array_push($keys, ...$moduleKeys);
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string,array<string,mixed>>  $catalogModules
     */
    protected function catalogModuleKey(string $key, array $catalogModules): ?string
    {
        $normalized = $this->normalizeKey($key);
        $candidate = self::CATALOG_ALIASES[$normalized] ?? $normalized;

        return isset($catalogModules[$candidate]) ? $candidate : null;
    }

    /**
     * @param  array<string,array<string,mixed>>  $catalogModules
     * @param  array<int,string>  $requestedModuleKeys
     * @param  array<int,string>  $workIntentKeys
     * @param  array<int,string>  $starterKeys
     * @param  array<int,string>  $workKeys
     */
    protected function displayStateForKey(
        string $key,
        ?string $catalogKey,
        array $catalogModules,
        array $requestedModuleKeys,
        array $workIntentKeys,
        array $starterKeys,
        array $workKeys
    ): string {
        $normalized = $this->normalizeKey($key);

        if (in_array($normalized, $requestedModuleKeys, true)) {
            return 'requested';
        }

        if (in_array($normalized, $workIntentKeys, true)) {
            return $catalogKey === null ? 'not_active_yet' : 'requested';
        }

        if (in_array($normalized, $starterKeys, true)) {
            return 'recommended';
        }

        if (in_array($normalized, $workKeys, true)) {
            return 'planned';
        }

        if ($catalogKey !== null && isset($catalogModules[$catalogKey])) {
            return $this->defaultCatalogDisplayState($catalogModules[$catalogKey]);
        }

        return 'future';
    }

    /**
     * @param  array<string,mixed>  $blueprint
     */
    protected function labelForKey(string $key, array $blueprint): string
    {
        $normalized = $this->normalizeKey($key);

        return match ($normalized) {
            'jobs', 'projects' => Str::plural((string) ($blueprint['project_label'] ?? 'Project')),
            'tasks' => Str::plural((string) ($blueprint['task_label'] ?? 'Task')),
            'assignments' => 'Assignments',
            'team_communication' => (string) ($blueprint['communication_label'] ?? 'Team Updates'),
            'client_communication' => 'Client communication',
            'photos' => (string) ($blueprint['upload_label'] ?? 'Photos'),
            'files', 'documents' => (string) ($blueprint['upload_label'] ?? 'Files'),
            'materials', 'parts' => (string) ($blueprint['material_label'] ?? 'Materials'),
            'time_invoices', 'time_or_invoices', 'invoices' => (string) ($blueprint['money_label'] ?? 'Invoices'),
            'mobile_field_capture' => 'Mobile field capture',
            default => $this->configuredLabel($normalized),
        };
    }

    /**
     * @param  array<string,mixed>  $blueprint
     */
    protected function reasonForKey(string $key, string $state, array $blueprint): string
    {
        if ($state === 'requested') {
            return 'Captured from tenant setup or work-management intent. Everbranch review is still required.';
        }

        if ($state === 'recommended') {
            return sprintf(
                'Recommended by the %s setup profile. This is setup guidance only.',
                (string) ($blueprint['business_template_label'] ?? 'tenant')
            );
        }

        if (in_array($state, ['planned', 'future', 'not_active_yet'], true)) {
            return 'Planned or future module demand. No working feature, billing, upload, messaging, or mobile API is active from this recommendation.';
        }

        if ($state === 'requires_setup') {
            return 'Visible catalog module that still requires Everbranch setup or access review.';
        }

        return $this->defaultCatalogReason($state);
    }

    protected function configuredLabel(string $key): string
    {
        $starterLabel = config('tenant_blueprints.starter_modules.'.$key);
        if (is_string($starterLabel) && trim($starterLabel) !== '') {
            return trim($starterLabel);
        }

        $catalogKey = self::CATALOG_ALIASES[$key] ?? $key;
        $moduleLabel = config('module_catalog.modules.'.$catalogKey.'.display_name');
        if (is_string($moduleLabel) && trim($moduleLabel) !== '') {
            return trim($moduleLabel);
        }

        return Str::headline($key);
    }

    /**
     * @param  array<string,mixed>  $module
     */
    protected function defaultCatalogDisplayState(array $module): string
    {
        $moduleState = is_array($module['module_state'] ?? null) ? (array) $module['module_state'] : [];
        $uiState = strtolower(trim((string) ($moduleState['ui_state'] ?? '')));
        if (in_array($uiState, ['active', 'setup_needed'], true)) {
            return $uiState === 'setup_needed' ? 'requires_setup' : 'active';
        }

        return match ((string) ($module['state_bucket'] ?? '')) {
            'active' => 'active',
            'available' => 'available',
            'upgrade', 'request' => 'requires_setup',
            default => 'unavailable',
        };
    }

    protected function defaultCatalogReason(string $state): string
    {
        return match ($state) {
            'active' => 'Enabled or included by the current workspace plan/setup state.',
            'available' => 'Visible in the tenant Module Store. Any activation still follows guarded module rules.',
            'requires_setup' => 'Visible for request or setup review; no billing or access changes happen from display.',
            default => 'Catalog display state only.',
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,string>  $states
     * @return array<int,string>
     */
    protected function keysForStates(array $rows, array $states): array
    {
        return collect($rows)
            ->filter(fn (array $row): bool => in_array((string) ($row['display_state'] ?? ''), $states, true))
            ->pluck('key')
            ->map(fn (mixed $key): string => (string) $key)
            ->values()
            ->all();
    }

    /**
     * @param  array<int,mixed>  $keys
     * @return array<int,string>
     */
    protected function normalizeKeys(array $keys): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $key): string => $this->normalizeKey((string) $key),
            $keys
        ))));
    }

    protected function normalizeKey(string $key): string
    {
        return Str::slug(strtolower(trim($key)), '_');
    }
}
