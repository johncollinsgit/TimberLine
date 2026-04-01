<?php

namespace App\Services\Tenancy;

use App\Models\TenantModuleAccessRequest;
use App\Support\Tenancy\TenantModuleActionPresenter;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TenantModuleCatalogService
{
    public function __construct(
        protected TenantModuleAccessResolver $accessResolver,
        protected TenantDisplayLabelResolver $displayLabelResolver,
        protected LandlordCommercialConfigService $commercialConfigService,
        protected LandlordOperatorActionAuditService $auditService
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function tenantStorePayload(?int $tenantId, string $surface = 'shopify'): array
    {
        $moduleDefinitions = $this->storeVisibleModuleDefinitions();
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

            $modules[] = [
                'module_key' => $moduleKey,
                'display_name' => (string) ($moduleState['label'] ?? $definition['display_name'] ?? Str::headline($moduleKey)),
                'description' => (string) ($definition['description'] ?? ''),
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

        usort($modules, function (array $left, array $right): int {
            $bucketOrder = ['active' => 0, 'available' => 1, 'upgrade' => 2, 'request' => 3];

            $bucketCompare = ($bucketOrder[$left['state_bucket'] ?? 'request'] ?? 99)
                <=> ($bucketOrder[$right['state_bucket'] ?? 'request'] ?? 99);
            if ($bucketCompare !== 0) {
                return $bucketCompare;
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
            $modules[] = [
                'key' => $moduleKey,
                'display_name' => (string) ($definition['display_name'] ?? Str::headline($moduleKey)),
                'description' => (string) ($definition['description'] ?? ''),
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
                'headline' => 'Forestry Backstage is a modular customer and business operating system.',
                'themes' => [
                    'Works with Shopify or independently.',
                    'Start with one workflow, add modules over time.',
                    'Keep product truth aligned with live entitlements and supported channels.',
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

            $marketState = strtoupper(trim((string) ($definition['market_state'] ?? 'INTERNAL_ONLY')));
            $status = strtolower(trim((string) ($definition['status'] ?? 'disabled')));
            $isVisible = (bool) data_get($definition, 'visibility.'.$visibilityKey, false);

            if (! $isVisible || $marketState !== 'SAFE_TO_MARKET' || ! in_array($status, ['live', 'beta'], true)) {
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
