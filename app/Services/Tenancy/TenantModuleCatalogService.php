<?php

namespace App\Services\Tenancy;

use App\Models\TenantModuleAccessRequest;
use App\Support\Tenancy\TenantModuleActionPresenter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TenantModuleCatalogService
{
    public function __construct(
        protected TenantModuleAccessResolver $accessResolver,
        protected TenantDisplayLabelResolver $displayLabelResolver,
        protected LandlordCommercialConfigService $commercialConfigService,
        protected LandlordOperatorActionAuditService $auditService,
        protected TenantBlueprintModuleRecommendationService $blueprintRecommendations
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function tenantStorePayload(?int $tenantId, string $surface = 'shopify'): array
    {
        $moduleDefinitions = $this->storeVisibleModuleDefinitions($surface === 'public_site' ? 'public_site' : 'app_store');
        $moduleKeys = array_keys($moduleDefinitions);
        $resolved = $this->accessResolver->resolveForTenant($tenantId, $moduleKeys);
        $moduleStates = $this->applyDisplayLabels(
            $tenantId,
            is_array($resolved['modules'] ?? null) ? (array) $resolved['modules'] : []
        );

        $modules = [];
        foreach ($moduleDefinitions as $moduleKey => $definition) {
            $moduleState = is_array($moduleStates[$moduleKey] ?? null)
                ? (array) $moduleStates[$moduleKey]
                : ['module_key' => $moduleKey, 'label' => (string) ($definition['display_name'] ?? $moduleKey)];

            $presented = TenantModuleActionPresenter::present(
                $moduleState,
                (string) ($moduleState['label'] ?? $definition['display_name'] ?? $moduleKey),
                $this->routeOptionsForSurface($surface)
            );

            if (($presented['reason'] ?? '') === 'channel_not_supported') {
                continue;
            }

            $productMetadata = $this->moduleProductMetadata($moduleKey, $definition, $moduleState);

            $modules[] = [
                'module_key' => $moduleKey,
                'display_name' => (string) ($moduleState['label'] ?? $definition['display_name'] ?? Str::headline($moduleKey)),
                'description' => (string) ($definition['description'] ?? ''),
                'short_description' => (string) ($productMetadata['short_description'] ?? ''),
                'long_description' => (string) ($productMetadata['long_description'] ?? ''),
                'category' => (string) ($productMetadata['category'] ?? 'operations'),
                'category_label' => (string) ($productMetadata['category_label'] ?? 'Operations'),
                'lifecycle' => (string) ($productMetadata['lifecycle'] ?? 'internal'),
                'lifecycle_label' => (string) ($productMetadata['lifecycle_label'] ?? 'Internal'),
                'setup_effort' => (string) ($productMetadata['setup_effort'] ?? 'standard'),
                'setup_effort_label' => (string) ($productMetadata['setup_effort_label'] ?? 'Standard setup'),
                'required_integrations' => (array) ($productMetadata['required_integrations'] ?? []),
                'required_integrations_label' => (string) ($productMetadata['required_integrations_label'] ?? 'No required integration'),
                'mobile_relevance' => (string) ($productMetadata['mobile_relevance'] ?? 'not_mobile_specific'),
                'mobile_relevance_label' => (string) ($productMetadata['mobile_relevance_label'] ?? 'Not mobile-specific'),
                'pricing_impact_label' => (string) ($productMetadata['pricing_impact_label'] ?? 'Pricing impact not configured'),
                'entitlement_requirement_label' => (string) ($productMetadata['entitlement_requirement_label'] ?? 'Access review required'),
                'tenant_visibility_label' => (string) ($productMetadata['tenant_visibility_label'] ?? 'Hidden unless explicitly safe'),
                'product_summary' => (string) ($productMetadata['product_summary'] ?? ''),
                'buyer_setup' => is_array($productMetadata['buyer_setup'] ?? null) ? (array) $productMetadata['buyer_setup'] : [],
                'blueprint_display_state' => 'unavailable',
                'blueprint_display_state_label' => 'Unavailable',
                'blueprint_recommendation_reason' => 'Catalog display state only.',
                'status' => strtolower(trim((string) ($definition['status'] ?? 'disabled'))),
                'channels' => array_values(array_map('strval', (array) ($definition['channels'] ?? []))),
                'included_in_plans' => array_values(array_map('strval', (array) ($definition['included_in_plans'] ?? []))),
                'billing_mode' => strtolower(trim((string) ($definition['billing_mode'] ?? 'unavailable'))),
                'market_state' => strtoupper(trim((string) ($definition['market_state'] ?? 'INTERNAL_ONLY'))),
                'visibility' => is_array($definition['visibility'] ?? null) ? (array) $definition['visibility'] : [],
                'module_state' => $presented,
                'state_bucket' => $this->stateBucket($presented),
            ];
        }

        $blueprintRecommendations = $this->blueprintRecommendations->forTenant($tenantId, $modules);
        $modules = $this->blueprintRecommendations->decorateCatalogModules($modules, $blueprintRecommendations);

        usort($modules, function (array $left, array $right): int {
            $bucketOrder = ['active' => 0, 'available' => 1, 'upgrade' => 2, 'request' => 3];
            $blueprintOrder = [
                'recommended' => 0,
                'requested' => 0,
                'active' => 1,
                'available' => 2,
                'requires_setup' => 3,
                'planned' => 4,
                'future' => 5,
                'not_active_yet' => 5,
                'unavailable' => 9,
            ];

            $bucketCompare = ($bucketOrder[$left['state_bucket'] ?? 'request'] ?? 99)
                <=> ($bucketOrder[$right['state_bucket'] ?? 'request'] ?? 99);
            if ($bucketCompare !== 0) {
                return $bucketCompare;
            }

            $blueprintCompare = ($blueprintOrder[$left['blueprint_display_state'] ?? 'unavailable'] ?? 99)
                <=> ($blueprintOrder[$right['blueprint_display_state'] ?? 'unavailable'] ?? 99);
            if ($blueprintCompare !== 0) {
                return $blueprintCompare;
            }

            return strcmp(
                strtolower(trim((string) ($left['display_name'] ?? ''))),
                strtolower(trim((string) ($right['display_name'] ?? '')))
            );
        });

        $planKey = strtolower(trim((string) ($resolved['plan_key'] ?? config('module_catalog.defaults.plan', 'starter'))));
        $planDefinition = is_array(config('module_catalog.plans.'.$planKey))
            ? (array) config('module_catalog.plans.'.$planKey)
            : [];

        return [
            'tenant_id' => $tenantId,
            'surface' => $surface,
            'current_plan' => [
                'key' => $planKey,
                'label' => (string) ($planDefinition['display_name'] ?? $planDefinition['label'] ?? Str::title($planKey)),
                'operating_mode' => (string) ($resolved['operating_mode'] ?? config('module_catalog.defaults.operating_mode', 'shopify')),
            ],
            'modules' => $modules,
            'blueprint_recommendations' => $blueprintRecommendations,
            'sections' => [
                'active' => array_values(array_filter($modules, static fn (array $row): bool => ($row['state_bucket'] ?? '') === 'active')),
                'available' => array_values(array_filter($modules, static fn (array $row): bool => ($row['state_bucket'] ?? '') === 'available')),
                'upgrade' => array_values(array_filter($modules, static fn (array $row): bool => ($row['state_bucket'] ?? '') === 'upgrade')),
                'request' => array_values(array_filter($modules, static fn (array $row): bool => ($row['state_bucket'] ?? '') === 'request')),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function publicCatalogPayload(): array
    {
        $modules = [];
        foreach ($this->storeVisibleModuleDefinitions('public_site') as $moduleKey => $definition) {
            $productMetadata = $this->moduleProductMetadata($moduleKey, $definition);

            $modules[] = [
                'key' => $moduleKey,
                'display_name' => (string) ($definition['display_name'] ?? Str::headline($moduleKey)),
                'description' => (string) ($definition['description'] ?? ''),
                'category_label' => (string) ($productMetadata['category_label'] ?? 'Operations'),
                'lifecycle_label' => (string) ($productMetadata['lifecycle_label'] ?? 'Internal'),
                'setup_effort_label' => (string) ($productMetadata['setup_effort_label'] ?? 'Standard setup'),
                'required_integrations_label' => (string) ($productMetadata['required_integrations_label'] ?? 'No required integration'),
                'mobile_relevance_label' => (string) ($productMetadata['mobile_relevance_label'] ?? 'Not mobile-specific'),
                'pricing_impact_label' => (string) ($productMetadata['pricing_impact_label'] ?? 'Pricing impact not configured'),
                'buyer_setup' => is_array($productMetadata['buyer_setup'] ?? null) ? (array) $productMetadata['buyer_setup'] : [],
                'status' => strtolower(trim((string) ($definition['status'] ?? 'disabled'))),
                'billing_mode' => strtolower(trim((string) ($definition['billing_mode'] ?? 'unavailable'))),
                'channels' => array_values(array_map('strval', (array) ($definition['channels'] ?? []))),
                'included_in_plans' => array_values(array_map('strval', (array) ($definition['included_in_plans'] ?? []))),
                'market_state' => strtoupper(trim((string) ($definition['market_state'] ?? 'INTERNAL_ONLY'))),
                'cta_routing' => strtolower(trim((string) ($definition['cta_routing'] ?? 'none'))),
            ];
        }

        $plans = [];
        foreach ((array) config('module_catalog.plans', []) as $planKey => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $includedModules = array_values(array_filter(array_map(function ($moduleKey) use ($modules): ?string {
                foreach ($modules as $module) {
                    if (($module['key'] ?? null) === $moduleKey) {
                        return (string) ($module['display_name'] ?? $moduleKey);
                    }
                }

                return null;
            }, (array) ($definition['included_modules'] ?? []))));

            $plans[] = [
                'key' => strtolower(trim((string) $planKey)),
                'display_name' => (string) ($definition['display_name'] ?? $definition['label'] ?? Str::headline((string) $planKey)),
                'included_modules' => $includedModules,
            ];
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'positioning' => [
                'headline' => 'Everbranch is a modular customer and business operating system.',
                'themes' => [
                    'Works with Shopify or independently.',
                    'Start with one workflow, add modules over time.',
                    'Keep product truth aligned with plan access and supported channels.',
                ],
                'supported_channel_types' => ['shopify', 'direct', 'hybrid'],
            ],
            'plans' => $plans,
            'modules' => $modules,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function activateModuleForTenant(int $tenantId, string $moduleKey, ?int $actorId = null, string $source = 'tenant_app_store'): array
    {
        $normalizedModuleKey = $this->canonicalModuleKey($moduleKey);
        try {
            $validatedAction = $this->validateSelfServeModuleAction($tenantId, $normalizedModuleKey, 'activate');
        } catch (ValidationException $exception) {
            $this->logDeniedStoreTransition(
                'activate',
                $tenantId,
                $normalizedModuleKey,
                'validation_failed',
                $source,
                $actorId
            );

            return [
                'ok' => false,
                'message' => (string) collect($exception->errors())->flatten()->first(),
            ];
        }

        $definition = (array) ($validatedAction['definition'] ?? []);
        $presented = (array) ($validatedAction['presented'] ?? []);

        if (($presented['enabled'] ?? false) === true) {
            return [
                'ok' => true,
                'message' => 'This module is already enabled.',
                'status' => 'already_enabled',
            ];
        }

        if (($presented['cta'] ?? 'none') !== 'add') {
            return [
                'ok' => false,
                'message' => 'This module cannot be self-served from the current tenant state.',
            ];
        }

        $entitlement = $this->commercialConfigService->setTenantModuleEntitlement(
            tenantId: $tenantId,
            moduleKey: $normalizedModuleKey,
            input: [
                'availability_status' => 'available',
                'enabled_status' => 'enabled',
                'billing_status' => strtolower(trim((string) ($definition['billing_mode'] ?? 'add_on'))) === 'add_on'
                    ? 'pending_billing'
                    : 'included_in_plan',
                'entitlement_source' => $source,
                'price_source' => 'catalog',
                'notes' => 'Tenant self-serve activation requested from the module store.',
                'metadata' => [
                    'requested_via' => $source,
                ],
            ],
            actorId: $actorId
        );
        $request = $this->resolvePendingAccessRequestByActivation($tenantId, $normalizedModuleKey, $source, $actorId);

        return [
            'ok' => true,
            'message' => 'Module activation saved.',
            'status' => 'activated',
            'entitlement_id' => (int) $entitlement->id,
            'request_id' => $request?->id,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function requestModuleAccessForTenant(int $tenantId, string $moduleKey, ?int $actorId = null, string $source = 'tenant_app_store_request'): array
    {
        $normalizedModuleKey = $this->canonicalModuleKey($moduleKey);
        try {
            $validatedAction = $this->validateSelfServeModuleAction($tenantId, $normalizedModuleKey, 'request');
        } catch (ValidationException $exception) {
            $this->logDeniedStoreTransition(
                'request',
                $tenantId,
                $normalizedModuleKey,
                'validation_failed',
                $source,
                $actorId
            );

            return [
                'ok' => false,
                'message' => (string) collect($exception->errors())->flatten()->first(),
            ];
        }

        $definition = (array) ($validatedAction['definition'] ?? []);
        $presented = (array) ($validatedAction['presented'] ?? []);

        if (! in_array((string) ($presented['cta'] ?? 'none'), ['request', 'upgrade'], true)) {
            return [
                'ok' => false,
                'message' => 'This module does not currently support an access request workflow.',
            ];
        }

        $requestRecord = $this->recordModuleAccessRequest(
            tenantId: $tenantId,
            moduleKey: $normalizedModuleKey,
            actorId: $actorId,
            source: $source,
            requestReason: (string) ($presented['reason'] ?? 'not_enabled')
        );

        $entitlement = $this->commercialConfigService->setTenantModuleEntitlement(
            tenantId: $tenantId,
            moduleKey: $normalizedModuleKey,
            input: [
                'availability_status' => 'requested',
                'enabled_status' => 'inherit',
                'billing_status' => null,
                'entitlement_source' => $source,
                'price_source' => null,
                'notes' => 'Tenant requested module access from the module store.',
                'metadata' => [
                    'requested_via' => $source,
                    'request_reason' => (string) ($presented['reason'] ?? 'not_enabled'),
                ],
            ],
            actorId: $actorId
        );

        return [
            'ok' => true,
            'message' => 'Access request recorded.',
            'status' => 'requested',
            'entitlement_id' => (int) $entitlement->id,
            'request_id' => (int) $requestRecord->id,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function validateSelfServeModuleAction(int $tenantId, string $moduleKey, string $action): array
    {
        $normalizedModuleKey = $this->canonicalModuleKey($moduleKey);
        if ($normalizedModuleKey === '') {
            throw ValidationException::withMessages([
                'moduleKey' => 'A valid module key is required.',
            ]);
        }

        $definition = $this->eligibleAppStoreDefinition($normalizedModuleKey);
        if ($definition === null) {
            throw ValidationException::withMessages([
                'moduleKey' => 'This module is not available on this surface.',
            ]);
        }

        $state = $this->accessResolver->module($tenantId, $normalizedModuleKey);
        $presented = TenantModuleActionPresenter::present($state, (string) ($definition['display_name'] ?? $normalizedModuleKey));

        if (($presented['reason'] ?? '') === 'channel_not_supported') {
            throw ValidationException::withMessages([
                'moduleKey' => 'This module is not supported for the current tenant channel.',
            ]);
        }

        if (($presented['enabled'] ?? false) === true && strtolower(trim($action)) === 'activate') {
            return [
                'module_key' => $normalizedModuleKey,
                'definition' => $definition,
                'state' => $state,
                'presented' => $presented,
            ];
        }

        $allowed = match (strtolower(trim($action))) {
            'activate' => ['add'],
            'request' => ['request', 'upgrade'],
            default => [],
        };

        if (! in_array((string) ($presented['cta'] ?? 'none'), $allowed, true)) {
            throw ValidationException::withMessages([
                'moduleKey' => 'This module cannot perform the requested action from the current tenant state.',
            ]);
        }

        return [
            'module_key' => $normalizedModuleKey,
            'definition' => $definition,
            'state' => $state,
            'presented' => $presented,
        ];
    }

    protected function stateBucket(array $presented): string
    {
        $uiState = strtolower(trim((string) ($presented['ui_state'] ?? 'locked')));
        if (in_array($uiState, ['active', 'setup_needed'], true)) {
            return 'active';
        }

        return match (strtolower(trim((string) ($presented['cta'] ?? 'none')))) {
            'add' => 'available',
            'upgrade' => 'upgrade',
            default => 'request',
        };
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    protected function storeVisibleModuleDefinitions(string $visibilityKey = 'app_store'): array
    {
        $visible = [];
        foreach ((array) config('module_catalog.modules', []) as $moduleKey => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            if (! $this->isSafeForSurface($definition, $visibilityKey)) {
                continue;
            }

            $visible[strtolower(trim((string) $moduleKey))] = $definition;
        }

        return $visible;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function eligibleAppStoreDefinition(string $moduleKey): ?array
    {
        $definitions = $this->storeVisibleModuleDefinitions('app_store');

        return is_array($definitions[$moduleKey] ?? null) ? (array) $definitions[$moduleKey] : null;
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    public function isSafeForSurface(array $definition, string $surface = 'app_store'): bool
    {
        $marketState = strtoupper(trim((string) ($definition['market_state'] ?? 'INTERNAL_ONLY')));
        $status = strtolower(trim((string) ($definition['status'] ?? 'disabled')));
        $isVisible = (bool) data_get($definition, 'visibility.'.strtolower(trim($surface)), false);

        return $isVisible && $marketState === 'SAFE_TO_MARKET' && in_array($status, ['live', 'beta'], true);
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>|null  $moduleState
     * @return array<string,mixed>
     */
    public function moduleProductMetadata(string $moduleKey, array $definition, ?array $moduleState = null): array
    {
        $billingMode = strtolower(trim((string) ($definition['billing_mode'] ?? 'unavailable')));
        $channels = array_values(array_map(
            static fn (mixed $channel): string => strtolower(trim((string) $channel)),
            (array) ($definition['channels'] ?? [])
        ));
        $includedPlans = array_values(array_filter(array_map(
            static fn (mixed $plan): string => strtolower(trim((string) $plan)),
            (array) ($definition['included_in_plans'] ?? [])
        )));
        $category = $this->categoryKey($definition);
        $requiredIntegrations = $this->requiredIntegrations($definition, $channels);
        $shortDescription = trim((string) ($definition['short_description'] ?? $definition['description'] ?? ''));
        $longDescription = trim((string) ($definition['long_description'] ?? $definition['description'] ?? $shortDescription));

        return [
            'category' => $category,
            'category_label' => $this->categoryLabel($category),
            'short_description' => $shortDescription,
            'long_description' => $longDescription,
            'lifecycle' => $this->lifecycleKey($definition),
            'lifecycle_label' => $this->lifecycleLabel($definition),
            'setup_effort' => $this->setupEffortKey($definition, $billingMode),
            'setup_effort_label' => $this->setupEffortLabel($definition, $billingMode),
            'required_integrations' => $requiredIntegrations,
            'required_integrations_label' => $this->requiredIntegrationsLabel($requiredIntegrations),
            'mobile_relevance' => $this->mobileRelevanceKey($definition),
            'mobile_relevance_label' => $this->mobileRelevanceLabel($definition),
            'pricing_impact_label' => $this->pricingImpactLabel($billingMode),
            'entitlement_requirement_label' => $this->entitlementRequirementLabel($includedPlans, $billingMode),
            'tenant_visibility_label' => $this->tenantVisibilityLabel($definition),
            'product_summary' => $this->productSummary($definition, $moduleState, $billingMode),
            'buyer_setup' => $this->buyerSetup($moduleKey, $definition, $moduleState, $billingMode),
        ];
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>|null  $moduleState
     * @return array<string,mixed>
     */
    protected function buyerSetup(string $moduleKey, array $definition, ?array $moduleState, string $billingMode): array
    {
        $configured = is_array($definition['buyer_setup'] ?? null) ? (array) $definition['buyer_setup'] : [];
        $displayName = trim((string) ($definition['display_name'] ?? Str::headline($moduleKey)));
        $description = trim((string) ($definition['description'] ?? ''));
        $stateDescription = trim((string) ($moduleState['reason_description'] ?? $moduleState['description'] ?? ''));
        $whatYouNeed = $this->normalizeBuyerSetupList((array) ($configured['what_you_need'] ?? []));
        $setupSteps = $this->normalizeBuyerSetupList((array) ($configured['setup_steps'] ?? []));

        if ($whatYouNeed === []) {
            $requiredIntegrations = $this->requiredIntegrations(
                $definition,
                array_values(array_map(
                    static fn (mixed $channel): string => strtolower(trim((string) $channel)),
                    (array) ($definition['channels'] ?? [])
                ))
            );
            $whatYouNeed = $requiredIntegrations === []
                ? ['A clear owner for setup and a few minutes to review the module.']
                : ['Access to '.strtolower($this->requiredIntegrationsLabel($requiredIntegrations)).' and a setup owner.'];
        }

        if ($setupSteps === []) {
            $setupSteps = [
                'Review the module fit for this workspace.',
                $billingMode === 'add_on' ? 'Request or add access when you are ready.' : 'Confirm the workspace has access.',
                'Open the module and complete any guided setup items.',
            ];
        }

        $outcome = trim((string) ($configured['outcome'] ?? ''));
        if ($outcome === '') {
            $outcome = $description !== ''
                ? $description
                : 'Give the workspace a clearer path for '.$displayName.'.';
        }

        $nextStep = trim((string) ($configured['next_step'] ?? ''));
        if ($nextStep === '') {
            $nextStep = $stateDescription !== ''
                ? $stateDescription
                : ($billingMode === 'add_on' ? 'Request access, then complete the guided setup.' : 'Review access and open the module when ready.');
        }

        return [
            'outcome' => $outcome,
            'best_for' => trim((string) ($configured['best_for'] ?? 'Teams that want '.$displayName.' available inside the same workspace.')),
            'what_you_need' => $whatYouNeed,
            'next_step' => $nextStep,
            'setup_steps' => $setupSteps,
            'primary_action' => trim((string) ($configured['primary_action'] ?? 'Review module')),
            'help_text' => trim((string) ($configured['help_text'] ?? 'You can review this module without changing billing or access.')),
        ];
    }

    /**
     * @param  array<int,mixed>  $items
     * @return array<int,string>
     */
    protected function normalizeBuyerSetupList(array $items): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $items
        )));
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    protected function categoryKey(array $definition): string
    {
        $configured = strtolower(trim((string) ($definition['category'] ?? '')));
        if ($configured !== '') {
            return $configured;
        }

        return match (strtolower(trim((string) ($definition['classification'] ?? 'shared-core')))) {
            'shopify-only' => 'shopify_growth',
            'integration-layer' => 'integrations',
            'add-on' => 'growth_add_on',
            'internal-admin' => 'operator_tools',
            default => 'customer_operations',
        };
    }

    protected function categoryLabel(string $category): string
    {
        return match (strtolower(trim($category))) {
            'shopify_growth' => 'Shopify growth',
            'integrations' => 'Integrations',
            'growth_add_on' => 'Growth add-on',
            'operator_tools' => 'Operator tools',
            'analytics' => 'Analytics',
            'customer_retention' => 'Customer retention',
            'mobile' => 'Mobile companion',
            default => 'Customer operations',
        };
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    protected function lifecycleKey(array $definition): string
    {
        $status = strtolower(trim((string) ($definition['status'] ?? 'disabled')));
        $marketState = strtoupper(trim((string) ($definition['market_state'] ?? 'INTERNAL_ONLY')));

        if ($status === 'deprecated') {
            return 'deprecated';
        }

        if ($marketState === 'INTERNAL_ONLY') {
            return 'internal';
        }

        if ($status === 'beta' && $marketState === 'SAFE_TO_MARKET') {
            return 'beta';
        }

        if ($status === 'live' && $marketState === 'SAFE_TO_MARKET') {
            return 'live';
        }

        if ($marketState === 'SAFE_TO_MARKET') {
            return 'safe_to_market';
        }

        return 'draft';
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    protected function lifecycleLabel(array $definition): string
    {
        return match ($this->lifecycleKey($definition)) {
            'deprecated' => 'Deprecated',
            'internal' => 'Internal only',
            'beta' => 'Beta',
            'live' => 'Live',
            'safe_to_market' => 'Safe to market',
            default => 'Draft or planned',
        };
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    protected function setupEffortKey(array $definition, string $billingMode): string
    {
        $configured = strtolower(trim((string) ($definition['setup_effort'] ?? '')));
        if ($configured !== '') {
            return $configured;
        }

        return match ($billingMode) {
            'add_on', 'custom' => 'everbranch_assisted',
            default => 'standard',
        };
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    protected function setupEffortLabel(array $definition, string $billingMode): string
    {
        return match ($this->setupEffortKey($definition, $billingMode)) {
            'none' => 'No setup needed',
            'light' => 'Light setup',
            'standard' => 'Standard setup',
            'everbranch_assisted' => 'Everbranch-assisted setup',
            'custom' => 'Custom setup review',
            default => Str::headline($this->setupEffortKey($definition, $billingMode)),
        };
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<int,string>  $channels
     * @return array<int,string>
     */
    protected function requiredIntegrations(array $definition, array $channels): array
    {
        $configured = array_values(array_filter(array_map(
            static fn (mixed $integration): string => strtolower(trim((string) $integration)),
            (array) ($definition['required_integrations'] ?? [])
        )));

        if ($configured !== []) {
            return array_values(array_unique($configured));
        }

        if (in_array('shopify', $channels, true) && ! in_array('both', $channels, true)) {
            return ['shopify'];
        }

        return [];
    }

    /**
     * @param  array<int,string>  $requiredIntegrations
     */
    protected function requiredIntegrationsLabel(array $requiredIntegrations): string
    {
        if ($requiredIntegrations === []) {
            return 'No required integration';
        }

        return implode(', ', array_map(
            static fn (string $integration): string => match ($integration) {
                'shopify' => 'Shopify',
                'square' => 'Square',
                'csv' => 'CSV/manual import',
                default => Str::headline($integration),
            },
            $requiredIntegrations
        ));
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    protected function mobileRelevanceKey(array $definition): string
    {
        $configured = strtolower(trim((string) ($definition['mobile_relevance'] ?? '')));

        return $configured !== '' ? $configured : 'not_mobile_specific';
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    protected function mobileRelevanceLabel(array $definition): string
    {
        return match ($this->mobileRelevanceKey($definition)) {
            'mobile_ready' => 'Mobile-ready when entitled',
            'future_mobile_companion' => 'Future mobile companion candidate',
            'mobile_admin' => 'Mobile operator candidate',
            default => 'Not mobile-specific',
        };
    }

    protected function pricingImpactLabel(string $billingMode): string
    {
        return match ($billingMode) {
            'included' => 'Included with eligible plan',
            'add_on' => 'Add-on pricing label only; checkout is not active here',
            'custom' => 'Custom pricing discussion required',
            default => 'No tenant pricing action available',
        };
    }

    /**
     * @param  array<int,string>  $includedPlans
     */
    protected function entitlementRequirementLabel(array $includedPlans, string $billingMode): string
    {
        if ($includedPlans !== []) {
            return 'Included on '.implode(', ', array_map(static fn (string $plan): string => Str::headline($plan), $includedPlans));
        }

        return match ($billingMode) {
            'add_on' => 'Requires add-on access or a request',
            'custom' => 'Requires Everbranch review',
            default => 'Requires access review',
        };
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    protected function tenantVisibilityLabel(array $definition): string
    {
        return $this->isSafeForSurface($definition, 'app_store')
            ? 'Visible in tenant App Store'
            : 'Hidden from tenant App Store unless explicitly made safe';
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>|null  $moduleState
     */
    protected function productSummary(array $definition, ?array $moduleState, string $billingMode): string
    {
        $stateDescription = trim((string) ($moduleState['reason_description'] ?? $moduleState['description'] ?? ''));
        if ($stateDescription !== '') {
            return $stateDescription;
        }

        return $billingMode === 'add_on'
            ? 'This module can be requested or added when access and billing readiness allow it.'
            : 'This module follows workspace plan, setup, and access rules.';
    }

    /**
     * @param  array<string,array<string,mixed>>  $moduleStates
     * @return array<string,array<string,mixed>>
     */
    protected function applyDisplayLabels(?int $tenantId, array $moduleStates): array
    {
        if ($moduleStates === []) {
            return $moduleStates;
        }

        $labels = $this->displayLabelResolver->moduleLabels($tenantId);
        if ($labels === []) {
            return $moduleStates;
        }

        foreach ($moduleStates as $moduleKey => $state) {
            if (! is_array($state)) {
                continue;
            }

            $resolvedLabel = trim((string) ($labels[strtolower(trim((string) ($state['module_key'] ?? $moduleKey)))] ?? ''));
            if ($resolvedLabel === '') {
                continue;
            }

            $moduleStates[$moduleKey]['label'] = $resolvedLabel;
        }

        return $moduleStates;
    }

    public function canonicalModuleKey(string $moduleKey): string
    {
        $normalized = strtolower(trim($moduleKey));
        if ($normalized === '') {
            return '';
        }

        $alias = config('module_catalog.legacy.module_aliases.'.$normalized);
        if (is_string($alias) && trim($alias) !== '') {
            return strtolower(trim($alias));
        }

        return $normalized;
    }

    protected function recordModuleAccessRequest(
        int $tenantId,
        string $moduleKey,
        ?int $actorId,
        string $source,
        string $requestReason
    ): TenantModuleAccessRequest {
        if (! Schema::hasTable('tenant_module_access_requests')) {
            $this->auditService->record(
                tenantId: $tenantId,
                actorUserId: $actorId,
                actionType: 'tenant_module_access_request_record_skipped',
                targetType: 'tenant_module_access_request',
                context: [
                    'tenant_id' => $tenantId,
                    'module_key' => $moduleKey,
                    'source' => $source,
                ],
                result: [
                    'reason' => 'request_table_missing',
                ]
            );

            return new TenantModuleAccessRequest([
                'tenant_id' => $tenantId,
                'module_key' => $moduleKey,
                'status' => 'pending',
                'requested_by' => $actorId,
                'source' => $source,
                'request_reason' => $requestReason,
                'requested_at' => now(),
            ]);
        }

        $existing = TenantModuleAccessRequest::query()
            ->forTenantId($tenantId)
            ->where('module_key', $moduleKey)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($existing instanceof TenantModuleAccessRequest) {
            $this->auditService->record(
                tenantId: $tenantId,
                actorUserId: $actorId,
                actionType: 'tenant_module_access_request_reused',
                targetType: 'tenant_module_access_request',
                targetId: $existing->id,
                context: [
                    'tenant_id' => $tenantId,
                    'module_key' => $moduleKey,
                    'source' => $source,
                    'request_reason' => $requestReason,
                ],
                afterState: $this->moduleAccessRequestState($existing)
            );

            return $existing;
        }

        $record = TenantModuleAccessRequest::query()->create([
            'tenant_id' => $tenantId,
            'module_key' => $moduleKey,
            'status' => 'pending',
            'requested_by' => $actorId,
            'source' => $source,
            'request_reason' => $requestReason,
            'requested_at' => now(),
            'metadata' => [
                'created_via' => $source,
            ],
        ]);

        $this->auditService->record(
            tenantId: $tenantId,
            actorUserId: $actorId,
            actionType: 'tenant_module_access_request_created',
            targetType: 'tenant_module_access_request',
            targetId: $record->id,
            context: [
                'tenant_id' => $tenantId,
                'module_key' => $moduleKey,
                'source' => $source,
                'request_reason' => $requestReason,
            ],
            afterState: $this->moduleAccessRequestState($record)
        );

        return $record;
    }

    protected function resolvePendingAccessRequestByActivation(
        int $tenantId,
        string $moduleKey,
        string $source,
        ?int $actorId
    ): ?TenantModuleAccessRequest {
        if (! Schema::hasTable('tenant_module_access_requests')) {
            return null;
        }

        $request = TenantModuleAccessRequest::query()
            ->forTenantId($tenantId)
            ->where('module_key', $moduleKey)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if (! $request instanceof TenantModuleAccessRequest) {
            return null;
        }

        $before = $this->moduleAccessRequestState($request);

        $request->forceFill([
            'status' => 'approved',
            'resolved_by' => $actorId,
            'resolved_at' => now(),
            'decision_note' => 'Resolved automatically by self-serve activation.',
        ])->save();

        $this->auditService->record(
            tenantId: $tenantId,
            actorUserId: $actorId,
            actionType: 'tenant_module_access_request_resolved',
            targetType: 'tenant_module_access_request',
            targetId: $request->id,
            context: [
                'tenant_id' => $tenantId,
                'module_key' => $moduleKey,
                'source' => $source,
                'resolution' => 'self_serve_activation',
            ],
            beforeState: $before,
            afterState: $this->moduleAccessRequestState($request)
        );

        return $request;
    }

    /**
     * @return array<string,mixed>
     */
    protected function moduleAccessRequestState(TenantModuleAccessRequest $request): array
    {
        return [
            'module_key' => (string) $request->module_key,
            'status' => (string) $request->status,
            'requested_by' => $request->requested_by,
            'resolved_by' => $request->resolved_by,
            'source' => (string) ($request->source ?? ''),
            'request_reason' => (string) ($request->request_reason ?? ''),
            'request_note' => $request->request_note,
            'decision_note' => $request->decision_note,
            'requested_at' => optional($request->requested_at)->toIso8601String(),
            'resolved_at' => optional($request->resolved_at)->toIso8601String(),
            'cancelled_at' => optional($request->cancelled_at)->toIso8601String(),
            'metadata' => is_array($request->metadata) ? $request->metadata : [],
        ];
    }

    protected function logDeniedStoreTransition(
        string $action,
        int $tenantId,
        string $moduleKey,
        string $reason,
        string $source,
        ?int $actorId
    ): void {
        Log::warning('tenant.module_store.transition_blocked', [
            'action' => strtolower(trim($action)),
            'tenant_id' => $tenantId,
            'module_key' => $moduleKey,
            'reason' => $reason,
            'source' => $source,
            'actor_id' => $actorId,
        ]);
    }

    /**
     * @return array<string,string>
     */
    protected function routeOptionsForSurface(string $surface): array
    {
        return match (strtolower(trim($surface))) {
            'marketing', 'direct' => [
                'store_route' => 'marketing.modules',
                'plans_route' => 'marketing.modules',
                'contact_route' => 'platform.contact',
            ],
            default => [
                'store_route' => 'shopify.app.store',
                'plans_route' => 'shopify.app.plans',
                'contact_route' => 'platform.contact',
            ],
        };
    }
}
