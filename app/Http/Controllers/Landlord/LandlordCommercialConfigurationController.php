<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\UpdateTenantModuleEntitlementRequest;
use App\Models\LandlordCatalogEntry;
use App\Models\MarketingAutomationEvent;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantBillingFulfillment;
use App\Services\Billing\StripeCommercialFulfillmentService;
use App\Services\Tenancy\LandlordCommercialConfigService;
use App\Services\Tenancy\TenantCommercialExperienceService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LandlordCommercialConfigurationController extends Controller
{
    public function index(
        LandlordCommercialConfigService $service,
        TenantModuleAccessResolver $moduleAccessResolver,
        TenantCommercialExperienceService $experienceService
    ): View
    {
        Gate::authorize('manage-landlord-commercial');
        $plans = $service->catalog(LandlordCatalogEntry::TYPE_PLAN);
        $addons = $service->catalog(LandlordCatalogEntry::TYPE_ADDON);
        $templates = $service->catalog(LandlordCatalogEntry::TYPE_TEMPLATE);
        $setupPackages = $service->catalog(LandlordCatalogEntry::TYPE_SETUP_PACKAGE);
        $billingOverview = $service->billingReadinessOverview();
        $tenantRows = $this->buildCommercialTenantRows($service, $moduleAccessResolver, $experienceService);

        $tenantManagement = $this->buildTenantManagementPayload(
            tenantRows: $tenantRows,
            plans: $plans,
            addons: $addons
        );

        return view('landlord.commercial.index', [
            'plans' => $plans,
            'addons' => $addons,
            'templates' => $templates,
            'setupPackages' => $setupPackages,
            'tenants' => $tenantRows,
            'moduleCatalog' => (array) config('module_catalog.modules', config('entitlements.modules', [])),
            'addonCatalog' => (array) config('entitlements.addons', []),
            'billingReadiness' => $billingOverview,
            'usageMetrics' => (array) config('commercial.usage_metrics', []),
            'tenantManagement' => $tenantManagement,
        ]);
    }

    public function tenantAnalyticsTable(
        Request $request,
        LandlordCommercialConfigService $service,
        TenantModuleAccessResolver $moduleAccessResolver,
        TenantCommercialExperienceService $experienceService
    ): JsonResponse {
        Gate::authorize('manage-landlord-commercial');

        $validated = $request->validate([
            'tenant' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:40'],
            'plan' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:120'],
            'module' => ['nullable', 'string', 'max:120'],
            'billing_health' => ['nullable', 'string', 'max:40'],
            'min_revenue' => ['nullable', 'numeric', 'min:0'],
            'min_users' => ['nullable', 'integer', 'min:0'],
            'rewards_state' => ['nullable', 'string', 'max:40'],
            'range' => ['nullable', 'string', 'max:20'],
            'sort' => ['nullable', 'string', 'max:80'],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $plans = $service->catalog(LandlordCatalogEntry::TYPE_PLAN);
        $addons = $service->catalog(LandlordCatalogEntry::TYPE_ADDON);
        $payload = $this->buildTenantManagementPayload(
            tenantRows: $this->buildCommercialTenantRows($service, $moduleAccessResolver, $experienceService),
            plans: $plans,
            addons: $addons
        );

        $filters = $this->normalizeTenantManagementFilters($validated);
        $rows = $this->filterTenantManagementRows((array) ($payload['rows'] ?? []), $filters);
        $rows = $this->sortTenantManagementRows(
            $rows,
            (string) ($validated['sort'] ?? 'sales_generated_cents'),
            (string) ($validated['direction'] ?? 'desc')
        );

        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($validated['per_page'] ?? 10)));
        $offset = ($page - 1) * $perPage;

        return response()->json([
            'data' => array_slice($rows, $offset, $perPage),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => count($rows),
                'from' => count($rows) === 0 ? 0 : $offset + 1,
                'to' => min($offset + $perPage, count($rows)),
                'sort' => (string) ($validated['sort'] ?? 'sales_generated_cents'),
                'direction' => (string) ($validated['direction'] ?? 'desc'),
            ],
            'summary' => $this->buildTenantManagementSummary($payload, $filters, $rows),
        ]);
    }

    public function tenantAnalyticsActivity(
        Request $request,
        LandlordCommercialConfigService $service,
        TenantModuleAccessResolver $moduleAccessResolver,
        TenantCommercialExperienceService $experienceService
    ): JsonResponse {
        Gate::authorize('manage-landlord-commercial');

        $validated = $request->validate([
            'tenant' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:40'],
            'plan' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:120'],
            'module' => ['nullable', 'string', 'max:120'],
            'billing_health' => ['nullable', 'string', 'max:40'],
            'min_revenue' => ['nullable', 'numeric', 'min:0'],
            'min_users' => ['nullable', 'integer', 'min:0'],
            'rewards_state' => ['nullable', 'string', 'max:40'],
            'dataset' => ['nullable', 'string', 'max:80'],
            'metric' => ['nullable', 'string', 'max:80'],
            'range' => ['nullable', 'string', 'max:20'],
            'group_by' => ['nullable', 'string', Rule::in(['hour', 'day', 'week', 'month'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $payload = $this->buildTenantManagementPayload(
            tenantRows: $this->buildCommercialTenantRows($service, $moduleAccessResolver, $experienceService),
            plans: $service->catalog(LandlordCatalogEntry::TYPE_PLAN),
            addons: $service->catalog(LandlordCatalogEntry::TYPE_ADDON)
        );

        $filters = $this->normalizeTenantManagementFilters($validated);

        return response()->json(
            $this->buildTenantManagementActivityResponse($payload, $filters)
        );
    }

    public function upsertCatalogEntry(
        Request $request,
        string $type,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        Gate::authorize('manage-landlord-commercial');
        $validated = $request->validate([
            'entry_key' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:190'],
            'status' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'recurring_price' => ['nullable', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'recurring_price_cents' => ['nullable', 'integer', 'min:0'],
            'recurring_interval' => ['nullable', 'string', 'max:40'],
            'setup_price' => ['nullable', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'setup_price_cents' => ['nullable', 'integer', 'min:0'],
            'payload_json' => ['nullable', 'string', 'json'],
        ]);

        $service->upsertCatalogEntry(
            type: $type,
            input: [
                ...$validated,
                'recurring_price_cents' => $this->resolvePriceCents(
                    dollars: $validated['recurring_price'] ?? null,
                    cents: $validated['recurring_price_cents'] ?? null
                ),
                'setup_price_cents' => $this->resolvePriceCents(
                    dollars: $validated['setup_price'] ?? null,
                    cents: $validated['setup_price_cents'] ?? null
                ),
                'payload' => $this->decodeJsonMap($validated['payload_json'] ?? ''),
            ],
            actorId: $request->user()?->id
        );

        return back()->with('status', 'Catalog entry saved.');
    }

    public function duplicateTemplate(
        Request $request,
        string $entryKey,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        Gate::authorize('manage-landlord-commercial');
        $validated = $request->validate([
            'new_key' => ['nullable', 'string', 'max:120'],
        ]);

        $service->duplicateTemplate(
            sourceKey: $entryKey,
            newKey: (string) ($validated['new_key'] ?? ''),
            actorId: $request->user()?->id
        );

        return back()->with('status', 'Template duplicated.');
    }

    public function setTemplateState(
        Request $request,
        string $entryKey,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        Gate::authorize('manage-landlord-commercial');
        $validated = $request->validate([
            'state' => ['required', 'string', 'in:active,inactive,archived'],
        ]);

        $service->setTemplateState(
            templateKey: $entryKey,
            state: (string) $validated['state'],
            actorId: $request->user()?->id
        );

        return back()->with('status', 'Template state updated.');
    }

    public function reorderTemplates(Request $request, LandlordCommercialConfigService $service): RedirectResponse
    {
        Gate::authorize('manage-landlord-commercial');
        $validated = $request->validate([
            'ordered_keys' => ['required', 'array'],
            'ordered_keys.*' => ['string', 'max:120'],
        ]);

        $service->reorderTemplates((array) $validated['ordered_keys'], $request->user()?->id);

        return back()->with('status', 'Template order updated.');
    }

    public function assignTenantPlan(
        Request $request,
        Tenant $tenant,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        Gate::authorize('manage-landlord-commercial');
        $planKeys = array_values(array_filter(array_map(
            static fn (array $entry): string => strtolower(trim((string) ($entry['entry_key'] ?? ''))),
            $service->catalog(LandlordCatalogEntry::TYPE_PLAN)
        )));

        $validated = $request->validate([
            'plan_key' => ['required', 'string', 'max:120', Rule::in($planKeys)],
            'operating_mode' => ['nullable', 'string', 'max:80', Rule::in(['shopify', 'direct'])],
        ]);

        $service->assignTenantPlan(
            tenantId: (int) $tenant->id,
            planKey: (string) $validated['plan_key'],
            operatingMode: (string) ($validated['operating_mode'] ?? 'shopify'),
            source: 'landlord_console',
            actorId: $request->user()?->id
        );

        return back()->with('status', 'Tenant plan assignment saved.');
    }

    public function updateTenantModuleState(
        Request $request,
        Tenant $tenant,
        string $moduleKey,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        Gate::authorize('manage-landlord-commercial');
        $request->merge([
            'module_key' => strtolower(trim($moduleKey)),
        ]);

        $catalogKeys = array_keys((array) config('module_catalog.modules', []));
        $validated = $request->validate([
            'module_key' => ['required', 'string', Rule::in($catalogKeys)],
            'enabled_override' => ['required', 'string', 'in:inherit,enabled,disabled'],
            'setup_status' => ['nullable', 'string', 'in:not_started,in_progress,configured,blocked'],
        ]);

        $override = match ((string) $validated['enabled_override']) {
            'enabled' => true,
            'disabled' => false,
            default => null,
        };

        $service->setTenantModuleState(
            tenantId: (int) $tenant->id,
            moduleKey: (string) $validated['module_key'],
            enabledOverride: $override,
            setupStatus: (string) ($validated['setup_status'] ?? ''),
            actorId: $request->user()?->id
        );

        return back()->with('status', 'Module state updated.');
    }

    public function updateTenantModuleEntitlement(
        UpdateTenantModuleEntitlementRequest $request,
        Tenant $tenant,
        string $moduleKey,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        Gate::authorize('manage-landlord-commercial');
        $validated = $request->validated();

        $definition = is_array(config('module_catalog.modules.'.(string) $validated['module_key']))
            ? (array) config('module_catalog.modules.'.(string) $validated['module_key'])
            : [];
        $moduleStatus = strtolower(trim((string) ($definition['status'] ?? 'disabled')));
        if ($moduleStatus === 'disabled') {
            return back()->withErrors([
                'module_key' => 'Disabled catalog modules cannot receive entitlement overrides.',
            ]);
        }

        $service->setTenantModuleEntitlement(
            tenantId: (int) $tenant->id,
            moduleKey: (string) $validated['module_key'],
            input: [
                'availability_status' => (string) $validated['availability_status'],
                'enabled_status' => (string) $validated['enabled_status'],
                'billing_status' => $validated['billing_status'] ?? null,
                'price_override_cents' => $validated['price_override_cents'] ?? null,
                'currency' => 'USD',
                'entitlement_source' => 'landlord_console',
                'price_source' => array_key_exists('price_override_cents', $validated) && $validated['price_override_cents'] !== null
                    ? 'manual'
                    : null,
                'notes' => $validated['notes'] ?? null,
                'metadata' => [
                    'updated_via' => 'landlord_commercial_console',
                ],
            ],
            actorId: $request->user()?->id
        );

        return back()->with('status', 'Module entitlement updated.');
    }

    public function updateTenantAddonState(
        Request $request,
        Tenant $tenant,
        string $addonKey,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        Gate::authorize('manage-landlord-commercial');
        $request->merge([
            'addon_key' => strtolower(trim($addonKey)),
        ]);

        $validated = $request->validate([
            'addon_key' => ['required', 'string', Rule::in(array_keys((array) config('entitlements.addons', [])))],
            'enabled' => ['required', 'boolean'],
        ]);

        $service->setTenantAddonState(
            tenantId: (int) $tenant->id,
            addonKey: (string) $validated['addon_key'],
            enabled: (bool) $validated['enabled'],
            source: 'landlord_console',
            actorId: $request->user()?->id
        );

        return back()->with('status', 'Addon state updated.');
    }

    public function updateTenantCommercialOverride(
        Request $request,
        Tenant $tenant,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        Gate::authorize('manage-landlord-commercial');
        $templateKeys = array_values(array_filter(array_map(
            static fn (array $entry): string => strtolower(trim((string) ($entry['entry_key'] ?? ''))),
            $service->catalog(LandlordCatalogEntry::TYPE_TEMPLATE)
        )));

        $validated = $request->validate([
            'template_key' => ['nullable', 'string', 'max:120', Rule::in(array_merge([''], $templateKeys))],
            'store_channel_allowance' => ['nullable', 'integer', 'min:1', 'max:500'],
            'plan_pricing_overrides_json' => ['nullable', 'string', 'json'],
            'addon_pricing_overrides_json' => ['nullable', 'string', 'json'],
            'included_usage_overrides_json' => ['nullable', 'string', 'json'],
            'display_labels_json' => ['nullable', 'string', 'json'],
            'billing_mapping_json' => ['nullable', 'string', 'json'],
            'metadata_json' => ['nullable', 'string', 'json'],
        ]);

        $service->updateTenantCommercialOverride((int) $tenant->id, [
            'template_key' => $validated['template_key'] ?? null,
            'store_channel_allowance' => $validated['store_channel_allowance'] ?? null,
            'plan_pricing_overrides' => $this->decodeJsonMap($validated['plan_pricing_overrides_json'] ?? ''),
            'addon_pricing_overrides' => $this->decodeJsonMap($validated['addon_pricing_overrides_json'] ?? ''),
            'included_usage_overrides' => $this->decodeJsonMap($validated['included_usage_overrides_json'] ?? ''),
            'display_labels' => $this->decodeJsonMap($validated['display_labels_json'] ?? ''),
            'billing_mapping' => $this->decodeJsonMap($validated['billing_mapping_json'] ?? ''),
            'metadata' => $this->decodeJsonMap($validated['metadata_json'] ?? ''),
        ], $request->user()?->id);

        return back()->with('status', 'Tenant commercial overrides updated.');
    }

    public function syncTenantStripeCustomer(
        Request $request,
        Tenant $tenant,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        Gate::authorize('manage-landlord-commercial');
        $result = $service->syncStripeCustomerReference($tenant, $request->user()?->id);

        if (! (bool) ($result['ok'] ?? false)) {
            return back()->with('status_error', (string) ($result['message'] ?? 'Stripe customer sync failed.'));
        }

        $customerReference = trim((string) ($result['customer_reference'] ?? ''));
        $statusMessage = $customerReference !== ''
            ? 'Stripe customer sync succeeded: '.$customerReference
            : 'Stripe customer sync succeeded.';

        return back()->with('status', $statusMessage);
    }

    public function syncTenantStripeSubscriptionPrep(
        Request $request,
        Tenant $tenant,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        Gate::authorize('manage-landlord-commercial');
        $result = $service->syncStripeSubscriptionPrepState($tenant, $request->user()?->id);

        if (! (bool) ($result['ok'] ?? false)) {
            return back()->with('status_error', (string) ($result['message'] ?? 'Stripe subscription prep sync failed.'));
        }

        $prepHash = trim((string) ($result['candidate_hash'] ?? ''));
        $statusMessage = $prepHash !== ''
            ? 'Stripe subscription prep synced: '.$prepHash
            : 'Stripe subscription prep synced.';

        return back()->with('status', $statusMessage);
    }

    public function syncTenantStripeLiveSubscription(
        Request $request,
        Tenant $tenant,
        LandlordCommercialConfigService $service
    ): RedirectResponse {
        Gate::authorize('manage-landlord-commercial');
        $result = $service->syncStripeLiveSubscriptionReference($tenant, $request->user()?->id);

        if (! (bool) ($result['ok'] ?? false)) {
            return back()->with('status_error', (string) ($result['message'] ?? 'Stripe live subscription sync failed.'));
        }

        $subscriptionReference = trim((string) ($result['subscription_reference'] ?? ''));
        $subscriptionStatus = trim((string) ($result['subscription_status'] ?? ''));

        $statusMessage = $subscriptionReference !== ''
            ? 'Stripe live subscription sync succeeded: '.$subscriptionReference
            : 'Stripe live subscription sync succeeded.';

        if ($subscriptionStatus !== '') {
            $statusMessage .= ' (status: '.$subscriptionStatus.')';
        }

        return back()->with('status', $statusMessage);
    }

    public function reconcileTenantStripeFulfillment(
        Request $request,
        Tenant $tenant,
        StripeCommercialFulfillmentService $service
    ): RedirectResponse {
        Gate::authorize('manage-landlord-commercial');

        $result = $service->reconcileTenant(
            tenantId: (int) $tenant->id,
            triggeredBy: 'landlord_repair',
            actorUserId: $request->user()?->id,
            sourceEventId: null,
            sourceEventType: null
        );

        if (! (bool) ($result['ok'] ?? false)) {
            return back()->with('status_error', (string) ($result['message'] ?? 'Stripe fulfillment reconcile failed.'));
        }

        $planKey = trim((string) ($result['plan_key'] ?? ''));
        $status = (string) ($result['status'] ?? 'ok');
        $message = $planKey !== ''
            ? 'Stripe fulfillment reconcile: '.$status.' (plan: '.$planKey.')'
            : 'Stripe fulfillment reconcile: '.$status;

        return back()->with('status', $message);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function buildCommercialTenantRows(
        LandlordCommercialConfigService $service,
        TenantModuleAccessResolver $moduleAccessResolver,
        TenantCommercialExperienceService $experienceService
    ): array {
        $tenantRows = [];
        $moduleCatalogKeys = array_keys((array) config('module_catalog.modules', config('entitlements.modules', [])));
        $billingOverview = $service->billingReadinessOverview();

        foreach ($service->tenantRowsForLandlord() as $tenant) {
            $usage = $service->tenantUsageSummary($tenant, false);
            $commercial = $service->tenantCommercialProfile((int) $tenant->id);
            $templateKey = (string) ($commercial['template_key'] ?? '');
            $templateExists = is_array($commercial['template'] ?? null);
            $templateLabels = $this->normalizeDisplayLabels((array) data_get($commercial, 'template.payload.default_labels', []));
            $explicitLabels = $this->normalizeDisplayLabels((array) ($commercial['display_labels'] ?? []));
            $effectiveLabels = array_replace($templateLabels, $explicitLabels);
            $labelSource = 'global_fallback';
            if ($explicitLabels !== []) {
                $labelSource = 'tenant_override';
            } elseif ($templateKey !== '' && $templateExists && $templateLabels !== []) {
                $labelSource = 'template_default';
            }
            $resolvedModuleState = $moduleAccessResolver->resolveForTenant((int) $tenant->id, $moduleCatalogKeys);
            $resolvedModules = is_array($resolvedModuleState['modules'] ?? null)
                ? (array) $resolvedModuleState['modules']
                : [];
            $moduleOverrides = [];
            foreach ($tenant->moduleStates as $moduleState) {
                $moduleKey = strtolower(trim((string) $moduleState->module_key));
                if ($moduleKey === '') {
                    continue;
                }

                $moduleOverrides[$moduleKey] = [
                    'enabled_override' => $moduleState->getRawOriginal('enabled_override') === null
                        ? null
                        : (bool) $moduleState->enabled_override,
                    'setup_status' => strtolower(trim((string) ($moduleState->setup_status ?? 'not_started'))),
                ];
            }

            $moduleEntitlements = [];
            foreach ($tenant->moduleEntitlements as $moduleEntitlement) {
                $moduleKey = strtolower(trim((string) $moduleEntitlement->module_key));
                if ($moduleKey === '') {
                    continue;
                }

                $moduleEntitlements[$moduleKey] = [
                    'availability_status' => strtolower(trim((string) ($moduleEntitlement->availability_status ?? 'available'))),
                    'enabled_status' => strtolower(trim((string) ($moduleEntitlement->enabled_status ?? 'inherit'))),
                    'billing_status' => trim((string) ($moduleEntitlement->billing_status ?? '')),
                    'price_override_cents' => $moduleEntitlement->price_override_cents,
                    'currency' => strtoupper(trim((string) ($moduleEntitlement->currency ?? 'USD'))),
                    'entitlement_source' => trim((string) ($moduleEntitlement->entitlement_source ?? '')),
                    'price_source' => trim((string) ($moduleEntitlement->price_source ?? '')),
                    'notes' => trim((string) ($moduleEntitlement->notes ?? '')),
                ];
            }

            $addonStates = [];
            foreach ($tenant->accessAddons as $addonState) {
                $addonKey = strtolower(trim((string) $addonState->addon_key));
                if ($addonKey === '') {
                    continue;
                }

                $addonStates[$addonKey] = (bool) $addonState->enabled;
            }

            $resolvedPlanKey = (string) ($resolvedModuleState['plan_key'] ?? config('entitlements.default_plan', 'starter'));
            $operatingMode = (string) ($tenant->accessProfile?->operating_mode ?? config('entitlements.default_operating_mode', 'shopify'));

            $commercialSummary = [];
            try {
                $commercialSummary = $experienceService->commercialSupportSummaryForTenant(
                    tenantId: (int) $tenant->id,
                    localPlanKey: $resolvedPlanKey,
                    localOperatingMode: $operatingMode
                );
            } catch (\Throwable) {
                $commercialSummary = [];
            }

            $lastFulfillment = null;
            if (Schema::hasTable('tenant_billing_fulfillments')) {
                $lastFulfillment = TenantBillingFulfillment::query()
                    ->where('tenant_id', (int) $tenant->id)
                    ->where('provider', 'stripe')
                    ->orderByDesc('id')
                    ->first();
            }

            $tenantRows[] = [
                'tenant' => $tenant,
                'plan_key' => (string) ($tenant->accessProfile?->plan_key ?? config('entitlements.default_plan', 'starter')),
                'resolved_plan_key' => $resolvedPlanKey,
                'operating_mode' => $operatingMode,
                'template_key' => (string) ($commercial['template_key'] ?? ''),
                'store_channel_allowance' => $commercial['store_channel_allowance'] ?? null,
                'usage' => $usage,
                'module_overrides' => $moduleOverrides,
                'module_entitlements' => $moduleEntitlements,
                'addon_states' => $addonStates,
                'resolved_module_states' => $resolvedModules,
                'plan_pricing_overrides_json' => $this->encodeJsonMap((array) ($commercial['plan_pricing_overrides'] ?? [])),
                'addon_pricing_overrides_json' => $this->encodeJsonMap((array) ($commercial['addon_pricing_overrides'] ?? [])),
                'included_usage_overrides_json' => $this->encodeJsonMap((array) ($commercial['included_usage_overrides'] ?? [])),
                'display_labels_json' => $this->encodeJsonMap((array) ($commercial['display_labels'] ?? [])),
                'effective_labels_json' => $this->encodeJsonMap($effectiveLabels),
                'billing_mapping_json' => $this->encodeJsonMap((array) ($commercial['billing_mapping'] ?? [])),
                'metadata_json' => $this->encodeJsonMap((array) ($commercial['metadata'] ?? [])),
                'template_default_labels_json' => $this->encodeJsonMap((array) data_get($commercial, 'template.payload.default_labels', [])),
                'label_source' => $labelSource,
                'template_missing' => $templateKey !== '' && ! $templateExists,
                'billing_readiness' => $service->tenantBillingReadiness(
                    tenantId: (int) $tenant->id,
                    resolvedPlanKey: $resolvedPlanKey,
                    addonStates: $addonStates,
                    commercialProfile: $commercial
                ),
                'stripe_customer_sync' => $service->stripeCustomerSyncReadiness(
                    tenant: $tenant,
                    commercialProfile: $commercial,
                    billingOverview: $billingOverview
                ),
                'stripe_subscription_prep' => $service->stripeSubscriptionPrepReadiness(
                    tenant: $tenant,
                    commercialProfile: $commercial,
                    billingOverview: $billingOverview
                ),
                'stripe_live_subscription_sync' => $service->stripeLiveSubscriptionSyncReadiness(
                    tenant: $tenant,
                    commercialProfile: $commercial,
                    billingOverview: $billingOverview
                ),
                'commercial_summary' => $commercialSummary,
                'last_fulfillment' => $lastFulfillment,
            ];
        }

        return $tenantRows;
    }

    /**
     * @param  array<int,array<string,mixed>>  $tenantRows
     * @param  array<int,array<string,mixed>>  $plans
     * @param  array<int,array<string,mixed>>  $addons
     * @return array<string,mixed>
     */
    protected function buildTenantManagementPayload(array $tenantRows, array $plans, array $addons): array
    {
        $tenantIds = array_values(array_filter(array_map(
            static fn (array $row): int => (int) data_get($row, 'tenant.id', 0),
            $tenantRows
        )));

        $analytics = $this->collectTenantManagementAnalytics($tenantIds);

        $planCatalog = [];
        foreach ($plans as $plan) {
            $planCatalog[strtolower(trim((string) ($plan['entry_key'] ?? '')))] = $plan;
        }

        $addonCatalog = [];
        foreach ($addons as $addon) {
            $addonCatalog[strtolower(trim((string) ($addon['entry_key'] ?? '')))] = $addon;
        }

        $rows = [];
        foreach ($tenantRows as $row) {
            /** @var Tenant $tenant */
            $tenant = $row['tenant'];
            $tenantId = (int) $tenant->id;
            $stats = $analytics['tenant_stats'][$tenantId] ?? $this->emptyTenantManagementAnalytics();
            $resolvedPlanKey = strtolower(trim((string) ($row['resolved_plan_key'] ?? $row['plan_key'] ?? 'starter')));
            $planDefinition = $planCatalog[$resolvedPlanKey] ?? [];
            $basePlanSubscriptionCents = $this->resolveBasePlanSubscriptionCents($tenant, $resolvedPlanKey, $planCatalog);
            $monthlySubscriptionCents = $basePlanSubscriptionCents;
            $status = $this->deriveTenantManagementStatus($row, $stats, $monthlySubscriptionCents);
            $moduleBreakdown = [];

            if ($basePlanSubscriptionCents > 0) {
                $moduleBreakdown[] = [
                    'label' => (string) ($planDefinition['name'] ?? Str::headline($resolvedPlanKey)),
                    'kind' => 'Base plan',
                    'amount_cents' => $basePlanSubscriptionCents,
                ];
            }

            foreach ((array) ($row['addon_states'] ?? []) as $addonKey => $enabled) {
                if (! $enabled) {
                    continue;
                }

                $normalizedAddonKey = strtolower(trim((string) $addonKey));
                $addonDefinition = $addonCatalog[$normalizedAddonKey] ?? [];
                $addonOverride = data_get($tenant, 'commercialOverride.addon_pricing_overrides.'.$normalizedAddonKey, []);
                $addonCents = (int) data_get($addonOverride, 'recurring_price_cents', $addonDefinition['recurring_price_cents'] ?? 0);
                $monthlySubscriptionCents += max(0, $addonCents);

                $moduleBreakdown[] = [
                    'label' => (string) ($addonDefinition['name'] ?? Str::headline($normalizedAddonKey)),
                    'kind' => 'Add-on',
                    'amount_cents' => max(0, $addonCents),
                ];
            }

            $rows[] = [
                'id' => $tenantId,
                'name' => (string) $tenant->name,
                'slug' => (string) $tenant->slug,
                'status' => $status['key'],
                'status_label' => $status['label'],
                'status_tone' => $status['tone'],
                'plan_key' => $resolvedPlanKey,
                'plan_label' => (string) ($planDefinition['name'] ?? Str::headline($resolvedPlanKey)),
                'monthly_subscription_amount_cents' => $monthlySubscriptionCents,
                'monthly_subscription_cents' => $monthlySubscriptionCents,
                'subscription_income_to_date_cents' => $monthlySubscriptionCents,
                'subscription_income_total_cents' => $monthlySubscriptionCents,
                'subscription_run_rate_cents' => $monthlySubscriptionCents,
                'subscription_income_note' => 'Configured recurring run rate proxy until captured billing history is modeled canonically.',
                'sales_generated_total_cents' => (int) ($stats['sales_generated_cents'] ?? 0),
                'sales_generated_cents' => (int) ($stats['sales_generated_cents'] ?? 0),
                'rewards_redeemed_total_cents' => (int) ($stats['rewards_redeemed_cents'] ?? 0),
                'rewards_redeemed_cents' => (int) ($stats['rewards_redeemed_cents'] ?? 0),
                'customers_onboarded_total' => (int) ($stats['customers_onboarded'] ?? 0),
                'customers_onboarded' => (int) ($stats['customers_onboarded'] ?? 0),
                'customer_count' => (int) ($stats['customer_count'] ?? 0),
                'active_users_count' => (int) ($stats['active_users_count'] ?? 0),
                'orders_count' => (int) ($stats['orders_count'] ?? 0),
                'team_user_count' => (int) ($tenant->users_count ?? 0),
                'mrr_contribution_cents' => $monthlySubscriptionCents,
                'last_active_at' => $stats['last_active_at'],
                'last_active_label' => $stats['last_active_label'],
                'billing_health' => (bool) data_get($row, 'billing_readiness.ready_for_activation_prep', false) ? 'ready' : 'needs_attention',
                'billing_health_label' => (bool) data_get($row, 'billing_readiness.ready_for_activation_prep', false)
                    ? 'Billing prep ready'
                    : 'Needs billing follow-up',
                'operating_mode' => (string) ($row['operating_mode'] ?? 'shopify'),
                'module_mix' => $moduleBreakdown,
                'module_revenue_breakdown' => $moduleBreakdown,
                'active_module_count' => collect((array) ($row['resolved_module_states'] ?? []))
                    ->filter(fn ($definition): bool => (bool) data_get($definition, 'enabled', false))
                    ->count(),
                'module_keys' => collect((array) ($row['resolved_module_states'] ?? []))
                    ->filter(fn ($definition): bool => (bool) data_get($definition, 'enabled', false))
                    ->map(function ($definition, $key): string {
                        $resolvedKey = strtolower(trim((string) data_get($definition, 'module_key', is_string($key) ? $key : '')));

                        return $resolvedKey;
                    })
                    ->filter()
                    ->values()
                    ->all(),
                'sales_sparkline' => $this->buildSparklinePoints((array) data_get($analytics, 'tenant_daily_metrics.'.$tenantId.'.sales_generated', [])),
                'customers_sparkline' => $this->buildSparklinePoints((array) data_get($analytics, 'tenant_daily_metrics.'.$tenantId.'.users_onboarded', [])),
                'recent_activity' => $this->summarizeRecentTenantActivity($stats),
                'detail_url' => route('landlord.tenants.show', ['tenant' => $tenantId]),
                'workspace_url' => route('landlord.commercial.index').'#tenant-overrides',
            ];
        }

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'rows' => $rows,
            'routes' => [
                'tenant_index' => route('landlord.tenants.index'),
                'table_endpoint' => route('landlord.commercial.analytics.tenants'),
                'activity_endpoint' => route('landlord.commercial.analytics.activity'),
            ],
            'filters' => [
                'tenants' => array_values(array_map(
                    static fn (array $row): array => [
                        'value' => (string) ($row['id'] ?? ''),
                        'label' => (string) ($row['name'] ?? 'Tenant'),
                    ],
                    $rows
                )),
                'plans' => array_values(array_filter(array_map(
                    static fn (array $row): array => [
                        'value' => strtolower(trim((string) ($row['entry_key'] ?? ''))),
                        'label' => (string) ($row['name'] ?? Str::headline((string) ($row['entry_key'] ?? ''))),
                    ],
                    $plans
                ), static fn (array $row): bool => $row['value'] !== '')),
                'statuses' => [
                    ['value' => 'active', 'label' => 'Active'],
                    ['value' => 'monitoring', 'label' => 'Monitoring'],
                    ['value' => 'quiet', 'label' => 'Quiet'],
                    ['value' => 'attention', 'label' => 'Needs attention'],
                ],
                'modules' => array_values(array_filter(array_map(
                    static fn (string $moduleKey, array $definition): array => [
                        'value' => $moduleKey,
                        'label' => (string) ($definition['label'] ?? $definition['display_name'] ?? Str::headline($moduleKey)),
                    ],
                    array_keys((array) config('module_catalog.modules', config('entitlements.modules', []))),
                    array_values((array) config('module_catalog.modules', config('entitlements.modules', [])))
                ), static fn (array $row): bool => $row['value'] !== '')),
            ],
            'chart' => [
                'datasets' => $this->tenantManagementDatasetDefinitions(),
                'metrics' => $this->tenantManagementMetricDefinitions(),
                'daily' => $analytics['tenant_daily_metrics'],
                'hourly' => $analytics['tenant_hourly_metrics'],
                'default_dataset' => 'all_activity',
                'default_metric' => 'sales_generated',
                'default_range' => '30d',
                'default_group' => 'day',
                'ranges' => $this->tenantManagementRangeDefinitions(),
                'currency_note' => 'Subscription run rate uses configured recurring value until captured billing history is modeled canonically.',
            ],
        ];

        $initialFilters = $this->normalizeTenantManagementFilters([
            'dataset' => 'all_activity',
            'metric' => 'sales_generated',
            'range' => '30d',
            'group_by' => 'day',
        ]);
        $initialRows = $this->sortTenantManagementRows(
            $this->filterTenantManagementRows($rows, $initialFilters),
            'sales_generated_cents',
            'desc'
        );
        $initialPerPage = 10;

        $payload['initial'] = [
            'table' => [
                'data' => array_slice($initialRows, 0, $initialPerPage),
                'meta' => [
                    'page' => 1,
                    'per_page' => $initialPerPage,
                    'total' => count($initialRows),
                    'from' => count($initialRows) === 0 ? 0 : 1,
                    'to' => min($initialPerPage, count($initialRows)),
                    'sort' => 'sales_generated_cents',
                    'direction' => 'desc',
                ],
                'summary' => $this->buildTenantManagementSummary($payload, $initialFilters, $initialRows),
            ],
            'activity' => $this->buildTenantManagementActivityResponse($payload, $initialFilters),
        ];

        return $payload;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    protected function tenantManagementRangeDefinitions(): array
    {
        return [
            '7d' => ['label' => '7D', 'days' => 7, 'groups' => ['hour', 'day']],
            '30d' => ['label' => '30D', 'days' => 30, 'groups' => ['day', 'week']],
            '90d' => ['label' => '90D', 'days' => 90, 'groups' => ['day', 'week', 'month']],
            '12m' => ['label' => '12M', 'months' => 12, 'groups' => ['week', 'month']],
            'custom' => ['label' => 'Custom range', 'groups' => ['day', 'week', 'month']],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    protected function normalizeTenantManagementFilters(array $input): array
    {
        $datasets = $this->tenantManagementDatasetDefinitions();
        $ranges = $this->tenantManagementRangeDefinitions();

        $dataset = strtolower(trim((string) ($input['dataset'] ?? 'all_activity')));
        if (! array_key_exists($dataset, $datasets)) {
            $dataset = 'all_activity';
        }

        $metric = strtolower(trim((string) ($input['metric'] ?? '')));
        $allowedMetrics = (array) ($datasets[$dataset]['metrics'] ?? []);
        if ($metric === '' || ! in_array($metric, $allowedMetrics, true)) {
            $metric = (string) ($allowedMetrics[0] ?? 'sales_generated');
        }

        $range = strtolower(trim((string) ($input['range'] ?? '30d')));
        if (! array_key_exists($range, $ranges)) {
            $range = '30d';
        }

        $groupBy = strtolower(trim((string) ($input['group_by'] ?? $this->defaultGroupForRange($range))));
        $allowedGroups = (array) ($ranges[$range]['groups'] ?? ['day']);
        if (! in_array($groupBy, $allowedGroups, true)) {
            $groupBy = (string) ($allowedGroups[0] ?? 'day');
        }

        $tenant = trim((string) ($input['tenant'] ?? 'all'));
        $tenant = $tenant !== '' ? $tenant : 'all';

        $status = strtolower(trim((string) ($input['status'] ?? 'all')));
        $plan = strtolower(trim((string) ($input['plan'] ?? 'all')));
        $module = strtolower(trim((string) ($input['module'] ?? 'all')));
        $billingHealth = strtolower(trim((string) ($input['billing_health'] ?? 'all')));
        $rewardsState = strtolower(trim((string) ($input['rewards_state'] ?? 'all')));
        $search = trim((string) ($input['search'] ?? ''));

        return [
            'tenant' => $tenant,
            'status' => $status !== '' ? $status : 'all',
            'plan' => $plan !== '' ? $plan : 'all',
            'search' => $search,
            'module' => $module !== '' ? $module : 'all',
            'billing_health' => $billingHealth !== '' ? $billingHealth : 'all',
            'min_revenue' => array_key_exists('min_revenue', $input) && $input['min_revenue'] !== null && $input['min_revenue'] !== ''
                ? (float) $input['min_revenue']
                : null,
            'min_users' => array_key_exists('min_users', $input) && $input['min_users'] !== null && $input['min_users'] !== ''
                ? (int) $input['min_users']
                : null,
            'rewards_state' => $rewardsState !== '' ? $rewardsState : 'all',
            'dataset' => $dataset,
            'metric' => $metric,
            'range' => $range,
            'group_by' => $groupBy,
            'from' => filled($input['from'] ?? null) ? CarbonImmutable::parse((string) $input['from'])->startOfDay() : null,
            'to' => filled($input['to'] ?? null) ? CarbonImmutable::parse((string) $input['to'])->endOfDay() : null,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    protected function filterTenantManagementRows(array $rows, array $filters): array
    {
        $search = Str::lower((string) ($filters['search'] ?? ''));

        return array_values(array_filter($rows, function (array $row) use ($filters, $search): bool {
            if (($filters['tenant'] ?? 'all') !== 'all' && (string) ($row['id'] ?? '') !== (string) $filters['tenant']) {
                return false;
            }

            if (($filters['status'] ?? 'all') !== 'all' && (string) ($row['status'] ?? '') !== (string) $filters['status']) {
                return false;
            }

            if (($filters['plan'] ?? 'all') !== 'all' && (string) ($row['plan_key'] ?? '') !== (string) $filters['plan']) {
                return false;
            }

            if (($filters['module'] ?? 'all') !== 'all' && ! in_array((string) $filters['module'], (array) ($row['module_keys'] ?? []), true)) {
                return false;
            }

            if (($filters['billing_health'] ?? 'all') !== 'all' && (string) ($row['billing_health'] ?? '') !== (string) $filters['billing_health']) {
                return false;
            }

            if (($filters['rewards_state'] ?? 'all') === 'redeeming' && (int) ($row['rewards_redeemed_cents'] ?? 0) <= 0) {
                return false;
            }

            if (($filters['rewards_state'] ?? 'all') === 'none' && (int) ($row['rewards_redeemed_cents'] ?? 0) > 0) {
                return false;
            }

            if (($filters['min_revenue'] ?? null) !== null && (int) ($row['sales_generated_cents'] ?? 0) < (int) round(((float) $filters['min_revenue']) * 100)) {
                return false;
            }

            if (($filters['min_users'] ?? null) !== null && (int) ($row['team_user_count'] ?? 0) < (int) $filters['min_users']) {
                return false;
            }

            if ($search !== '') {
                $haystack = Str::lower(implode(' ', array_filter([
                    (string) ($row['name'] ?? ''),
                    (string) ($row['slug'] ?? ''),
                    (string) ($row['plan_label'] ?? ''),
                    (string) ($row['status_label'] ?? ''),
                ])));

                if (! Str::contains($haystack, $search)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,array<string,mixed>>
     */
    protected function sortTenantManagementRows(array $rows, string $sort, string $direction): array
    {
        $allowedSorts = [
            'name',
            'status',
            'plan_label',
            'monthly_subscription_cents',
            'subscription_income_to_date_cents',
            'sales_generated_cents',
            'rewards_redeemed_cents',
            'customers_onboarded',
            'last_active_at',
            'mrr_contribution_cents',
            'orders_count',
            'team_user_count',
            'active_users_count',
        ];

        $sortKey = in_array($sort, $allowedSorts, true) ? $sort : 'sales_generated_cents';
        $multiplier = strtolower(trim($direction)) === 'asc' ? 1 : -1;

        usort($rows, function (array $left, array $right) use ($sortKey, $multiplier): int {
            $a = $left[$sortKey] ?? null;
            $b = $right[$sortKey] ?? null;

            if ($a === $b) {
                return 0;
            }

            if ($a === null || $a === '') {
                return 1;
            }

            if ($b === null || $b === '') {
                return -1;
            }

            if (is_numeric($a) || is_numeric($b)) {
                return (((float) $a <=> (float) $b) * $multiplier);
            }

            return (Str::lower((string) $a) <=> Str::lower((string) $b)) * $multiplier;
        });

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $filters
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    protected function buildTenantManagementSummary(array $payload, array $filters, array $rows): array
    {
        return [
            'result_count' => count($rows),
            'selected_tenant_ids' => array_values(array_map(
                static fn (array $row): int => (int) ($row['id'] ?? 0),
                $rows
            )),
            'totals' => [
                'monthly_subscription_cents' => array_sum(array_map(
                    static fn (array $row): int => (int) ($row['monthly_subscription_cents'] ?? 0),
                    $rows
                )),
                'subscription_income_to_date_cents' => array_sum(array_map(
                    static fn (array $row): int => (int) ($row['subscription_income_to_date_cents'] ?? 0),
                    $rows
                )),
                'sales_generated_cents' => array_sum(array_map(
                    static fn (array $row): int => (int) ($row['sales_generated_cents'] ?? 0),
                    $rows
                )),
                'rewards_redeemed_cents' => array_sum(array_map(
                    static fn (array $row): int => (int) ($row['rewards_redeemed_cents'] ?? 0),
                    $rows
                )),
                'customers_onboarded' => array_sum(array_map(
                    static fn (array $row): int => (int) ($row['customers_onboarded'] ?? 0),
                    $rows
                )),
            ],
            'filters' => [
                'tenant' => (string) ($filters['tenant'] ?? 'all'),
                'status' => (string) ($filters['status'] ?? 'all'),
                'plan' => (string) ($filters['plan'] ?? 'all'),
                'range' => (string) ($filters['range'] ?? '30d'),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    protected function buildTenantManagementActivityResponse(array $payload, array $filters): array
    {
        $filteredRows = $this->filterTenantManagementRows((array) ($payload['rows'] ?? []), $filters);
        $datasets = $this->tenantManagementDatasetDefinitions();
        $metrics = $this->tenantManagementMetricDefinitions();
        $datasetKey = (string) ($filters['dataset'] ?? 'all_activity');
        $metricKey = (string) ($filters['metric'] ?? 'sales_generated');
        $dataset = $datasets[$datasetKey] ?? $datasets['all_activity'];
        $metricDefinition = $metrics[$metricKey] ?? ($metrics['sales_generated'] ?? ['label' => 'Sales generated', 'unit' => 'currency']);

        if ($metricKey === 'module_revenue') {
            return $this->buildModuleRevenueActivityResponse($payload, $filteredRows, $filters, $dataset, $metricDefinition);
        }

        $series = $this->buildMetricSeries($payload, $filteredRows, $filters, $metricKey);
        $totals = $this->resolveMetricTotals($payload, $filteredRows, $filters, $metricKey, $series);
        $chartType = ($metricDefinition['unit'] ?? 'count') === 'currency' ? 'area' : 'bar';
        $note = $metricKey === 'subscription_income'
            ? 'Subscription income uses configured recurring run rate until captured billing history is modeled canonically.'
            : null;
        $emptyState = $filteredRows === []
            ? 'No tenants match the current filter combination yet.'
            : ($this->tenantManagementSeriesHasSignal($series)
                ? null
                : 'No '.$metricDefinition['label'].' data is available for the current filter and time window yet.');

        return [
            'filters' => $filters,
            'summary' => [
                'cards' => $this->buildTenantManagementKpiCards($payload, $filteredRows, $filters),
            ],
            'chart' => [
                'dataset' => $datasetKey,
                'dataset_label' => (string) ($dataset['label'] ?? Str::headline($datasetKey)),
                'metric' => $metricKey,
                'metric_label' => (string) ($metricDefinition['label'] ?? Str::headline($metricKey)),
                'unit' => (string) ($metricDefinition['unit'] ?? 'count'),
                'chart_type' => $chartType,
                'stacked' => false,
                'xaxis_type' => 'datetime',
                'categories' => (array) ($series['labels'] ?? []),
                'buckets' => (array) ($series['buckets'] ?? []),
                'series' => [
                    ['name' => 'Current period', 'data' => array_values((array) ($series['current_points'] ?? []))],
                    ['name' => 'Previous period', 'data' => array_values((array) ($series['previous_points'] ?? []))],
                ],
                'total' => $totals['current'],
                'previous_total' => $totals['previous'],
                'delta_label' => $this->formatTenantManagementDeltaLabel($totals['current'], $totals['previous']),
                'delta_tone' => $this->tenantManagementDeltaTone($totals['current'], $totals['previous']),
                'note' => $note,
                'empty_state' => $emptyState,
                'window_label' => $this->tenantManagementRangeLabel($filters),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,array<string,mixed>>  $filteredRows
     * @param  array<string,mixed>  $filters
     * @param  array<string,mixed>  $dataset
     * @param  array<string,mixed>  $metricDefinition
     * @return array<string,mixed>
     */
    protected function buildModuleRevenueActivityResponse(
        array $payload,
        array $filteredRows,
        array $filters,
        array $dataset,
        array $metricDefinition
    ): array {
        $breakdown = [];

        foreach ($filteredRows as $row) {
            foreach ((array) ($row['module_revenue_breakdown'] ?? $row['module_mix'] ?? []) as $item) {
                $label = trim((string) ($item['label'] ?? 'Configured recurring mix'));
                if ($label === '') {
                    continue;
                }

                $key = Str::slug($label.'-'.trim((string) ($item['kind'] ?? 'module')));
                $breakdown[$key] ??= [
                    'label' => $label,
                    'amount_cents' => 0,
                ];
                $breakdown[$key]['amount_cents'] += max(0, (int) ($item['amount_cents'] ?? 0));
            }
        }

        uasort($breakdown, static fn (array $left, array $right): int => ((int) $right['amount_cents']) <=> ((int) $left['amount_cents']));

        return [
            'filters' => $filters,
            'summary' => [
                'cards' => $this->buildTenantManagementKpiCards($payload, $filteredRows, $filters),
            ],
            'chart' => [
                'dataset' => (string) ($filters['dataset'] ?? 'module_revenue'),
                'dataset_label' => (string) ($dataset['label'] ?? 'Module revenue'),
                'metric' => 'module_revenue',
                'metric_label' => (string) ($metricDefinition['label'] ?? 'Revenue generated by module'),
                'unit' => 'currency',
                'chart_type' => 'bar',
                'stacked' => true,
                'xaxis_type' => 'category',
                'categories' => ['Configured recurring mix'],
                'buckets' => [
                    [
                        'label' => 'Configured recurring mix',
                        'start_at' => null,
                        'end_at' => null,
                    ],
                ],
                'series' => array_values(array_map(
                    static fn (array $item): array => [
                        'name' => (string) ($item['label'] ?? 'Module'),
                        'data' => [(int) ($item['amount_cents'] ?? 0)],
                    ],
                    $breakdown
                )),
                'total' => array_sum(array_map(
                    static fn (array $item): int => (int) ($item['amount_cents'] ?? 0),
                    $breakdown
                )),
                'previous_total' => array_sum(array_map(
                    static fn (array $item): int => (int) ($item['amount_cents'] ?? 0),
                    $breakdown
                )),
                'delta_label' => 'Current mix',
                'delta_tone' => 'neutral',
                'note' => 'Module revenue is currently shown as configured recurring commercial mix until module-attributed billing history is modeled canonically.',
                'empty_state' => $breakdown === []
                    ? 'No recurring commercial mix is configured for the current tenant selection yet.'
                    : null,
                'window_label' => $this->tenantManagementRangeLabel($filters),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,array<string,mixed>>  $filteredRows
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    protected function buildTenantManagementKpiCards(array $payload, array $filteredRows, array $filters): array
    {
        $sales = $this->resolveMetricTotals($payload, $filteredRows, $filters, 'sales_generated');
        $rewards = $this->resolveMetricTotals($payload, $filteredRows, $filters, 'rewards_cash_redeemed');
        $onboarded = $this->resolveMetricTotals($payload, $filteredRows, $filters, 'users_onboarded');
        $activeTenants = $this->resolveMetricTotals($payload, $filteredRows, $filters, 'active_tenants');
        $subscriptionRunRate = array_sum(array_map(
            static fn (array $row): int => (int) ($row['subscription_run_rate_cents'] ?? 0),
            $filteredRows
        ));
        $teamUsers = array_sum(array_map(
            static fn (array $row): int => (int) ($row['team_user_count'] ?? 0),
            $filteredRows
        ));

        return [
            [
                'key' => 'active_tenants',
                'label' => 'Active tenants',
                'value' => $activeTenants['current'],
                'unit' => 'count',
                'delta_label' => $this->formatTenantManagementDeltaLabel($activeTenants['current'], $activeTenants['previous']),
                'delta_tone' => $this->tenantManagementDeltaTone($activeTenants['current'], $activeTenants['previous']),
                'helper' => $this->tenantManagementRangeLabel($filters).' tenant activity footprint',
            ],
            [
                'key' => 'subscription_income',
                'label' => 'Subscription income',
                'value' => $subscriptionRunRate,
                'unit' => 'currency',
                'delta_label' => 'Current run rate',
                'delta_tone' => 'neutral',
                'helper' => 'Configured recurring value until billing history is modeled canonically',
            ],
            [
                'key' => 'sales_generated',
                'label' => 'Sales generated',
                'value' => $sales['current'],
                'unit' => 'currency',
                'delta_label' => $this->formatTenantManagementDeltaLabel($sales['current'], $sales['previous']),
                'delta_tone' => $this->tenantManagementDeltaTone($sales['current'], $sales['previous']),
                'helper' => $this->tenantManagementRangeLabel($filters).' attributed order revenue',
            ],
            [
                'key' => 'rewards_cash_redeemed',
                'label' => 'Rewards cash redeemed',
                'value' => $rewards['current'],
                'unit' => 'currency',
                'delta_label' => $this->formatTenantManagementDeltaLabel($rewards['current'], $rewards['previous']),
                'delta_tone' => $this->tenantManagementDeltaTone($rewards['current'], $rewards['previous']),
                'helper' => $this->tenantManagementRangeLabel($filters).' redemption volume',
            ],
            [
                'key' => 'users_onboarded',
                'label' => 'Users onboarded',
                'value' => $onboarded['current'],
                'unit' => 'count',
                'delta_label' => $this->formatTenantManagementDeltaLabel($onboarded['current'], $onboarded['previous']),
                'delta_tone' => $this->tenantManagementDeltaTone($onboarded['current'], $onboarded['previous']),
                'helper' => $this->tenantManagementRangeLabel($filters).' new customer profiles',
            ],
            [
                'key' => 'active_users',
                'label' => 'Active users in system',
                'value' => $teamUsers,
                'unit' => 'count',
                'delta_label' => 'Current',
                'delta_tone' => 'neutral',
                'helper' => 'Current landlord-visible team seats',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,array<string,mixed>>  $filteredRows
     * @param  array<string,mixed>  $filters
     * @param  array<string,mixed>|null  $series
     * @return array{current:int|float,previous:int|float}
     */
    protected function resolveMetricTotals(
        array $payload,
        array $filteredRows,
        array $filters,
        string $metric,
        ?array $series = null
    ): array {
        $resolvedSeries = $series ?? $this->buildMetricSeries($payload, $filteredRows, $filters, $metric);

        if ($metric === 'subscription_income') {
            $current = array_sum(array_map(
                static fn (array $row): int => (int) ($row['subscription_run_rate_cents'] ?? 0),
                $filteredRows
            ));

            return [
                'current' => $current,
                'previous' => $current,
            ];
        }

        if ($metric === 'active_tenants') {
            $current = count(array_filter((array) ($resolvedSeries['current'] ?? []), static fn ($value): bool => (int) $value > 0));
            $previous = count(array_filter((array) ($resolvedSeries['previous'] ?? []), static fn ($value): bool => (int) $value > 0));

            return [
                'current' => $current,
                'previous' => $previous,
            ];
        }

        if ($metric === 'average_revenue_per_tenant') {
            $sales = $this->resolveMetricTotals($payload, $filteredRows, $filters, 'sales_generated');
            $tenantCount = max(count($filteredRows), 1);

            return [
                'current' => (int) round($sales['current'] / $tenantCount),
                'previous' => (int) round($sales['previous'] / $tenantCount),
            ];
        }

        if ($metric === 'average_revenue_per_customer') {
            $sales = $this->resolveMetricTotals($payload, $filteredRows, $filters, 'sales_generated');
            $customerCount = max(array_sum(array_map(
                static fn (array $row): int => (int) ($row['customer_count'] ?? 0),
                $filteredRows
            )), 1);

            return [
                'current' => (int) round($sales['current'] / $customerCount),
                'previous' => (int) round($sales['previous'] / $customerCount),
            ];
        }

        return [
            'current' => array_sum(array_map('intval', (array) ($resolvedSeries['current'] ?? []))),
            'previous' => array_sum(array_map('intval', (array) ($resolvedSeries['previous'] ?? []))),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,array<string,mixed>>  $filteredRows
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    protected function buildMetricSeries(array $payload, array $filteredRows, array $filters, string $metric): array
    {
        $currentBuckets = $this->buildCurrentBuckets($filters);
        $previousBuckets = $this->shiftBucketsToPreviousPeriod($currentBuckets, (string) ($filters['group_by'] ?? 'day'));

        return [
            'buckets' => array_map(
                static fn (array $bucket): array => [
                    'label' => (string) ($bucket['label'] ?? ''),
                    'start_at' => $bucket['start'] instanceof CarbonImmutable
                        ? $bucket['start']->toIso8601String()
                        : null,
                    'end_at' => $bucket['end'] instanceof CarbonImmutable
                        ? $bucket['end']->toIso8601String()
                        : null,
                ],
                $currentBuckets
            ),
            'labels' => array_map(
                static fn (array $bucket): string => (string) ($bucket['label'] ?? ''),
                $currentBuckets
            ),
            'current' => array_map(
                fn (array $bucket): int => $this->metricValueForBucket($payload, $filteredRows, $metric, $bucket, (string) ($filters['group_by'] ?? 'day')),
                $currentBuckets
            ),
            'previous' => array_map(
                fn (array $bucket): int => $this->metricValueForBucket($payload, $filteredRows, $metric, $bucket, (string) ($filters['group_by'] ?? 'day')),
                $previousBuckets
            ),
            'current_points' => array_map(
                fn (array $bucket): array => [
                    'x' => $bucket['start'] instanceof CarbonImmutable ? $bucket['start']->toIso8601String() : null,
                    'y' => $this->metricValueForBucket($payload, $filteredRows, $metric, $bucket, (string) ($filters['group_by'] ?? 'day')),
                ],
                $currentBuckets
            ),
            'previous_points' => array_map(
                fn (array $bucket, int $value): array => [
                    'x' => $bucket['start'] instanceof CarbonImmutable ? $bucket['start']->toIso8601String() : null,
                    'y' => $value,
                ],
                $currentBuckets,
                array_map(
                    fn (array $bucket): int => $this->metricValueForBucket($payload, $filteredRows, $metric, $bucket, (string) ($filters['group_by'] ?? 'day')),
                    $previousBuckets
                )
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,array<string,mixed>>  $filteredRows
     * @param  array<string,mixed>  $bucket
     */
    protected function metricValueForBucket(array $payload, array $filteredRows, string $metric, array $bucket, string $groupBy): int
    {
        if ($filteredRows === []) {
            return 0;
        }

        if ($metric === 'subscription_income') {
            return array_sum(array_map(
                static fn (array $row): int => (int) ($row['subscription_run_rate_cents'] ?? 0),
                $filteredRows
            ));
        }

        if ($metric === 'average_revenue_per_tenant') {
            $sales = $this->metricValueForBucket($payload, $filteredRows, 'sales_generated', $bucket, $groupBy);

            return (int) round($sales / max(count($filteredRows), 1));
        }

        if ($metric === 'average_revenue_per_customer') {
            $sales = $this->metricValueForBucket($payload, $filteredRows, 'sales_generated', $bucket, $groupBy);
            $customerCount = max(array_sum(array_map(
                static fn (array $row): int => (int) ($row['customer_count'] ?? 0),
                $filteredRows
            )), 1);

            return (int) round($sales / $customerCount);
        }

        $tenantIds = array_values(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $filteredRows
        ));
        $useHourly = $groupBy === 'hour';
        $source = $useHourly
            ? (array) data_get($payload, 'chart.hourly', [])
            : (array) data_get($payload, 'chart.daily', []);

        if ($metric === 'active_tenants') {
            return count(array_filter($tenantIds, function (int $tenantId) use ($source, $bucket, $useHourly): bool {
                $entries = (array) data_get($source, $tenantId.'.activity_flag', []);

                return $this->sumEntriesInBucket($entries, $bucket, $useHourly) > 0;
            }));
        }

        return array_sum(array_map(function (int $tenantId) use ($source, $metric, $bucket, $useHourly): int {
            $entries = (array) data_get($source, $tenantId.'.'.$metric, []);

            return $this->sumEntriesInBucket($entries, $bucket, $useHourly);
        }, $tenantIds));
    }

    /**
     * @param  array<string,int>  $entries
     * @param  array<string,mixed>  $bucket
     */
    protected function sumEntriesInBucket(array $entries, array $bucket, bool $useHourly): int
    {
        $start = $bucket['start'];
        $end = $bucket['end'];

        return array_reduce(array_keys($entries), function (int $total, string $key) use ($entries, $start, $end, $useHourly): int {
            $timestamp = $useHourly
                ? CarbonImmutable::parse(str_replace(' ', 'T', $key).':00')
                : CarbonImmutable::parse($key)->startOfDay();

            if ($timestamp->lessThan($start) || ! $timestamp->lessThan($end)) {
                return $total;
            }

            return $total + (int) ($entries[$key] ?? 0);
        }, 0);
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    protected function buildCurrentBuckets(array $filters): array
    {
        ['start' => $start, 'end' => $end] = $this->resolveTenantManagementWindow($filters);
        $groupBy = (string) ($filters['group_by'] ?? 'day');
        $buckets = [];

        if ($groupBy === 'hour') {
            $cursor = $start->startOfHour();
            while ($cursor->lessThanOrEqualTo($end)) {
                $bucketEnd = $cursor->addHour();
                $buckets[] = [
                    'start' => $cursor,
                    'end' => $bucketEnd,
                    'label' => $cursor->format('n/j H:00'),
                ];
                $cursor = $bucketEnd;
            }

            return array_slice($buckets, -168);
        }

        if ($groupBy === 'day') {
            $cursor = $start->startOfDay();
            while ($cursor->lessThanOrEqualTo($end)) {
                $bucketEnd = $cursor->addDay();
                $buckets[] = [
                    'start' => $cursor,
                    'end' => $bucketEnd,
                    'label' => $cursor->format('n/j'),
                ];
                $cursor = $bucketEnd;
            }

            return $buckets;
        }

        if ($groupBy === 'week') {
            $cursor = $start->startOfWeek();
            while ($cursor->lessThanOrEqualTo($end)) {
                $bucketEnd = $cursor->addWeek();
                $buckets[] = [
                    'start' => $cursor,
                    'end' => $bucketEnd,
                    'label' => $cursor->format('n/j'),
                ];
                $cursor = $bucketEnd;
            }

            return $buckets;
        }

        $cursor = $start->startOfMonth();
        while ($cursor->lessThanOrEqualTo($end)) {
            $bucketEnd = $cursor->addMonth();
            $buckets[] = [
                'start' => $cursor,
                'end' => $bucketEnd,
                'label' => $cursor->format('M y'),
            ];
            $cursor = $bucketEnd;
        }

        return $buckets;
    }

    /**
     * @param  array<int,array<string,mixed>>  $buckets
     * @return array<int,array<string,mixed>>
     */
    protected function shiftBucketsToPreviousPeriod(array $buckets, string $groupBy): array
    {
        $count = count($buckets);

        return array_map(function (array $bucket) use ($count, $groupBy): array {
            /** @var CarbonImmutable $start */
            $start = $bucket['start'];
            /** @var CarbonImmutable $end */
            $end = $bucket['end'];

            $shiftedStart = match ($groupBy) {
                'hour' => $start->subHours($count),
                'week' => $start->subWeeks($count),
                'month' => $start->subMonths($count),
                default => $start->subDays($count),
            };
            $shiftedEnd = match ($groupBy) {
                'hour' => $end->subHours($count),
                'week' => $end->subWeeks($count),
                'month' => $end->subMonths($count),
                default => $end->subDays($count),
            };

            return [
                'start' => $shiftedStart,
                'end' => $shiftedEnd,
                'label' => $this->tenantManagementBucketLabel($shiftedStart, $groupBy),
            ];
        }, $buckets);
    }

    /**
     * @param  array<string,mixed>  $series
     */
    protected function tenantManagementSeriesHasSignal(array $series): bool
    {
        $current = array_sum(array_map('intval', (array) ($series['current'] ?? [])));
        $previous = array_sum(array_map('intval', (array) ($series['previous'] ?? [])));

        return $current > 0 || $previous > 0;
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array{start:CarbonImmutable,end:CarbonImmutable}
     */
    protected function resolveTenantManagementWindow(array $filters): array
    {
        $range = (string) ($filters['range'] ?? '30d');
        $end = $range === 'custom' && $filters['to'] instanceof CarbonImmutable
            ? $filters['to']->endOfDay()
            : now()->toImmutable();

        $start = match ($range) {
            '7d' => $end->subDays(6)->startOfDay(),
            '90d' => $end->subDays(89)->startOfDay(),
            '12m' => $end->subMonths(11)->startOfMonth(),
            'custom' => $filters['from'] instanceof CarbonImmutable
                ? $filters['from']->startOfDay()
                : $end->subDays(29)->startOfDay(),
            default => $end->subDays(29)->startOfDay(),
        };

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    protected function tenantManagementBucketLabel(CarbonImmutable $start, string $groupBy): string
    {
        return match ($groupBy) {
            'hour' => $start->format('n/j H:00'),
            'week' => $start->format('n/j'),
            'month' => $start->format('M y'),
            default => $start->format('n/j'),
        };
    }

    protected function defaultGroupForRange(string $range): string
    {
        return match ($range) {
            '7d' => 'day',
            '90d' => 'week',
            '12m' => 'month',
            default => 'day',
        };
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    protected function tenantManagementRangeLabel(array $filters): string
    {
        if (($filters['range'] ?? '30d') === 'custom' && $filters['from'] instanceof CarbonImmutable && $filters['to'] instanceof CarbonImmutable) {
            return $filters['from']->format('Y-m-d').' to '.$filters['to']->format('Y-m-d');
        }

        return (string) data_get($this->tenantManagementRangeDefinitions(), ($filters['range'] ?? '30d').'.label', '30D');
    }

    protected function formatTenantManagementDeltaLabel(int|float $current, int|float $previous): string
    {
        if ($previous == 0 && $current == 0) {
            return 'Flat';
        }

        if ($previous == 0) {
            return '+100%';
        }

        $delta = (($current - $previous) / abs($previous)) * 100;
        $prefix = $delta > 0 ? '+' : '';

        return $prefix.number_format($delta, 0).'%';
    }

    protected function tenantManagementDeltaTone(int|float $current, int|float $previous): string
    {
        if ($current == $previous) {
            return 'neutral';
        }

        return $current > $previous ? 'up' : 'down';
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    protected function tenantManagementDatasetDefinitions(): array
    {
        return [
            'all_activity' => [
                'label' => 'All activity',
                'metrics' => ['sales_generated', 'orders_placed', 'users_onboarded', 'active_users', 'rewards_cash_redeemed'],
            ],
            'tenant_activity' => [
                'label' => 'Tenant activity',
                'metrics' => ['active_tenants', 'sales_generated', 'subscription_income', 'average_revenue_per_tenant'],
            ],
            'customer_activity' => [
                'label' => 'Customer activity',
                'metrics' => ['users_onboarded', 'active_users', 'orders_placed', 'average_revenue_per_customer'],
            ],
            'reward_redemption' => [
                'label' => 'Reward redemption',
                'metrics' => ['rewards_cash_redeemed'],
            ],
            'revenue' => [
                'label' => 'Revenue',
                'metrics' => ['sales_generated', 'subscription_income', 'orders_placed', 'average_revenue_per_tenant'],
            ],
            'onboarding' => [
                'label' => 'Onboarding',
                'metrics' => ['users_onboarded', 'active_users'],
            ],
            'module_revenue' => [
                'label' => 'Module revenue',
                'metrics' => ['module_revenue'],
                'empty_state' => 'Module revenue is represented as the current recurring commercial mix until module-attributed billing history is modeled canonically.',
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    protected function tenantManagementMetricDefinitions(): array
    {
        return [
            'sales_generated' => ['label' => 'Sales generated', 'unit' => 'currency'],
            'orders_placed' => ['label' => 'Orders placed', 'unit' => 'count'],
            'users_onboarded' => ['label' => 'Users onboarded', 'unit' => 'count'],
            'rewards_cash_redeemed' => ['label' => 'Rewards cash redeemed', 'unit' => 'currency'],
            'active_tenants' => ['label' => 'Active tenants', 'unit' => 'count', 'distinct_presence' => true],
            'subscription_income' => ['label' => 'Subscription income', 'unit' => 'currency', 'proxy' => 'configured_run_rate'],
            'module_revenue' => ['label' => 'Revenue generated by module', 'unit' => 'currency', 'stacked' => true],
            'active_users' => ['label' => 'Active users', 'unit' => 'count'],
            'average_revenue_per_tenant' => ['label' => 'Average revenue per tenant', 'unit' => 'currency'],
            'average_revenue_per_customer' => ['label' => 'Average revenue per customer', 'unit' => 'currency'],
        ];
    }

    /**
     * @param  array<int,int>  $tenantIds
     * @return array<string,mixed>
     */
    protected function collectTenantManagementAnalytics(array $tenantIds): array
    {
        $tenantIds = array_values(array_unique(array_filter(array_map('intval', $tenantIds))));
        $tenantStats = [];
        $tenantDailyMetrics = [];
        $tenantHourlyMetrics = [];
        $tenantDailyActiveProfiles = [];
        $tenantHourlyActiveProfiles = [];

        foreach ($tenantIds as $tenantId) {
            $tenantStats[$tenantId] = $this->emptyTenantManagementAnalytics();
            $tenantDailyMetrics[$tenantId] = [];
            $tenantHourlyMetrics[$tenantId] = [];
            $tenantDailyActiveProfiles[$tenantId] = [];
            $tenantHourlyActiveProfiles[$tenantId] = [];
        }

        if ($tenantIds === []) {
            return [
                'tenant_stats' => $tenantStats,
                'tenant_daily_metrics' => $tenantDailyMetrics,
                'tenant_hourly_metrics' => $tenantHourlyMetrics,
            ];
        }

        $windowStart = CarbonImmutable::today()->subDays(364)->startOfDay();
        $hourWindowStart = CarbonImmutable::now()->subDays(7)->startOfHour();

        if (Schema::hasTable('marketing_profiles')) {
            $profileTotals = MarketingProfile::query()
                ->select('tenant_id', DB::raw('count(*) as aggregate'))
                ->whereIn('tenant_id', $tenantIds)
                ->groupBy('tenant_id')
                ->pluck('aggregate', 'tenant_id');

            foreach ($profileTotals as $tenantId => $count) {
                $tenantStats[(int) $tenantId]['customer_count'] = (int) $count;
            }
        }

        if (Schema::hasTable('orders')) {
            $orders = Order::query()
                ->select(['tenant_id', 'ordered_at', 'created_at', 'total_price'])
                ->whereIn('tenant_id', $tenantIds)
                ->where(function ($query) use ($windowStart): void {
                    $query
                        ->where('ordered_at', '>=', $windowStart)
                        ->orWhere(function ($fallback) use ($windowStart): void {
                            $fallback->whereNull('ordered_at')->where('created_at', '>=', $windowStart);
                        });
                })
                ->orderBy('tenant_id')
                ->get();

            foreach ($orders as $order) {
                $tenantId = (int) $order->tenant_id;
                $timestamp = $order->ordered_at
                    ? CarbonImmutable::parse($order->ordered_at)
                    : ($order->created_at ? CarbonImmutable::parse($order->created_at) : null);
                if ($timestamp === null) {
                    continue;
                }

                $bucketDay = $timestamp->toDateString();
                $bucketHour = $timestamp->format('Y-m-d H:00');
                $amountCents = (int) round(((float) $order->total_price) * 100);

                $tenantStats[$tenantId]['sales_generated_cents'] += $amountCents;
                $tenantStats[$tenantId]['orders_count'] += 1;
                $tenantStats[$tenantId]['latest_order_at'] = $this->maxTimestamp($tenantStats[$tenantId]['latest_order_at'], $timestamp);
                $tenantStats[$tenantId]['last_active_at'] = $this->maxTimestamp($tenantStats[$tenantId]['last_active_at'], $timestamp);

                $this->incrementTenantMetric($tenantDailyMetrics, $tenantId, 'sales_generated', $bucketDay, $amountCents);
                $this->incrementTenantMetric($tenantDailyMetrics, $tenantId, 'orders_placed', $bucketDay, 1);
                $this->incrementTenantMetric($tenantDailyMetrics, $tenantId, 'activity_flag', $bucketDay, 1);

                if ($timestamp->greaterThanOrEqualTo($hourWindowStart)) {
                    $this->incrementTenantMetric($tenantHourlyMetrics, $tenantId, 'sales_generated', $bucketHour, $amountCents);
                    $this->incrementTenantMetric($tenantHourlyMetrics, $tenantId, 'orders_placed', $bucketHour, 1);
                    $this->incrementTenantMetric($tenantHourlyMetrics, $tenantId, 'activity_flag', $bucketHour, 1);
                }
            }
        }

        if (Schema::hasTable('marketing_profiles')) {
            $profiles = MarketingProfile::query()
                ->select(['id', 'tenant_id', 'created_at'])
                ->whereIn('tenant_id', $tenantIds)
                ->where('created_at', '>=', $windowStart)
                ->orderBy('tenant_id')
                ->get();

            foreach ($profiles as $profile) {
                $tenantId = (int) $profile->tenant_id;
                $timestamp = $profile->created_at ? CarbonImmutable::parse($profile->created_at) : null;
                if ($timestamp === null) {
                    continue;
                }

                $bucketDay = $timestamp->toDateString();
                $bucketHour = $timestamp->format('Y-m-d H:00');

                $tenantStats[$tenantId]['customers_onboarded'] += 1;
                $tenantStats[$tenantId]['latest_profile_at'] = $this->maxTimestamp($tenantStats[$tenantId]['latest_profile_at'], $timestamp);
                $tenantStats[$tenantId]['last_active_at'] = $this->maxTimestamp($tenantStats[$tenantId]['last_active_at'], $timestamp);

                $this->incrementTenantMetric($tenantDailyMetrics, $tenantId, 'users_onboarded', $bucketDay, 1);
                $this->incrementTenantMetric($tenantDailyMetrics, $tenantId, 'activity_flag', $bucketDay, 1);

                if ($timestamp->greaterThanOrEqualTo($hourWindowStart)) {
                    $this->incrementTenantMetric($tenantHourlyMetrics, $tenantId, 'users_onboarded', $bucketHour, 1);
                    $this->incrementTenantMetric($tenantHourlyMetrics, $tenantId, 'activity_flag', $bucketHour, 1);
                }
            }
        }

        if (Schema::hasTable('marketing_automation_events')) {
            $events = MarketingAutomationEvent::query()
                ->select(['tenant_id', 'marketing_profile_id', 'occurred_at', 'created_at'])
                ->whereIn('tenant_id', $tenantIds)
                ->where(function ($query) use ($windowStart): void {
                    $query
                        ->where('occurred_at', '>=', $windowStart)
                        ->orWhere(function ($fallback) use ($windowStart): void {
                            $fallback->whereNull('occurred_at')->where('created_at', '>=', $windowStart);
                        });
                })
                ->orderBy('tenant_id')
                ->get();

            foreach ($events as $event) {
                $tenantId = (int) $event->tenant_id;
                $timestamp = $event->occurred_at
                    ? CarbonImmutable::parse($event->occurred_at)
                    : ($event->created_at ? CarbonImmutable::parse($event->created_at) : null);
                if ($timestamp === null) {
                    continue;
                }

                $bucketDay = $timestamp->toDateString();
                $bucketHour = $timestamp->format('Y-m-d H:00');

                $tenantStats[$tenantId]['latest_automation_at'] = $this->maxTimestamp($tenantStats[$tenantId]['latest_automation_at'], $timestamp);
                $tenantStats[$tenantId]['last_active_at'] = $this->maxTimestamp($tenantStats[$tenantId]['last_active_at'], $timestamp);

                $this->incrementTenantMetric($tenantDailyMetrics, $tenantId, 'activity_flag', $bucketDay, 1);

                if ((int) ($event->marketing_profile_id ?? 0) > 0) {
                    $tenantDailyActiveProfiles[$tenantId][$bucketDay][(int) $event->marketing_profile_id] = true;
                    if ($timestamp->greaterThanOrEqualTo($hourWindowStart)) {
                        $tenantHourlyActiveProfiles[$tenantId][$bucketHour][(int) $event->marketing_profile_id] = true;
                    }
                }

                if ($timestamp->greaterThanOrEqualTo($hourWindowStart)) {
                    $this->incrementTenantMetric($tenantHourlyMetrics, $tenantId, 'activity_flag', $bucketHour, 1);
                }
            }
        }

        if (Schema::hasTable('marketing_email_deliveries')) {
            $deliveries = MarketingEmailDelivery::query()
                ->select(['tenant_id', 'marketing_profile_id', 'sent_at', 'created_at'])
                ->whereIn('tenant_id', $tenantIds)
                ->where(function ($query) use ($windowStart): void {
                    $query
                        ->where('sent_at', '>=', $windowStart)
                        ->orWhere(function ($fallback) use ($windowStart): void {
                            $fallback->whereNull('sent_at')->where('created_at', '>=', $windowStart);
                        });
                })
                ->orderBy('tenant_id')
                ->get();

            foreach ($deliveries as $delivery) {
                $tenantId = (int) $delivery->tenant_id;
                $profileId = (int) ($delivery->marketing_profile_id ?? 0);
                if ($profileId <= 0) {
                    continue;
                }

                $timestamp = $delivery->sent_at
                    ? CarbonImmutable::parse($delivery->sent_at)
                    : ($delivery->created_at ? CarbonImmutable::parse($delivery->created_at) : null);
                if ($timestamp === null) {
                    continue;
                }

                $bucketDay = $timestamp->toDateString();
                $bucketHour = $timestamp->format('Y-m-d H:00');
                $tenantDailyActiveProfiles[$tenantId][$bucketDay][$profileId] = true;

                if ($timestamp->greaterThanOrEqualTo($hourWindowStart)) {
                    $tenantHourlyActiveProfiles[$tenantId][$bucketHour][$profileId] = true;
                }
            }
        }

        if (Schema::hasTable('candle_cash_transactions') && Schema::hasTable('marketing_profiles')) {
            $transactions = DB::table('candle_cash_transactions')
                ->join('marketing_profiles', 'marketing_profiles.id', '=', 'candle_cash_transactions.marketing_profile_id')
                ->select([
                    'marketing_profiles.tenant_id',
                    'candle_cash_transactions.created_at',
                    'candle_cash_transactions.candle_cash_delta',
                ])
                ->whereIn('marketing_profiles.tenant_id', $tenantIds)
                ->where('candle_cash_transactions.candle_cash_delta', '<', 0)
                ->where('candle_cash_transactions.created_at', '>=', $windowStart)
                ->orderBy('marketing_profiles.tenant_id')
                ->get();

            foreach ($transactions as $transaction) {
                $tenantId = (int) $transaction->tenant_id;
                $timestamp = $transaction->created_at ? CarbonImmutable::parse($transaction->created_at) : null;
                if ($timestamp === null) {
                    continue;
                }

                $bucketDay = $timestamp->toDateString();
                $bucketHour = $timestamp->format('Y-m-d H:00');
                $amountCents = (int) round(abs((float) $transaction->candle_cash_delta) * 100);

                $tenantStats[$tenantId]['rewards_redeemed_cents'] += $amountCents;
                $tenantStats[$tenantId]['latest_reward_at'] = $this->maxTimestamp($tenantStats[$tenantId]['latest_reward_at'], $timestamp);
                $tenantStats[$tenantId]['last_active_at'] = $this->maxTimestamp($tenantStats[$tenantId]['last_active_at'], $timestamp);

                $this->incrementTenantMetric($tenantDailyMetrics, $tenantId, 'rewards_cash_redeemed', $bucketDay, $amountCents);
                $this->incrementTenantMetric($tenantDailyMetrics, $tenantId, 'activity_flag', $bucketDay, 1);

                if ($timestamp->greaterThanOrEqualTo($hourWindowStart)) {
                    $this->incrementTenantMetric($tenantHourlyMetrics, $tenantId, 'rewards_cash_redeemed', $bucketHour, $amountCents);
                    $this->incrementTenantMetric($tenantHourlyMetrics, $tenantId, 'activity_flag', $bucketHour, 1);
                }
            }
        }

        foreach ($tenantIds as $tenantId) {
            foreach (($tenantDailyActiveProfiles[$tenantId] ?? []) as $bucket => $profiles) {
                $tenantDailyMetrics[$tenantId]['active_users'][$bucket] = count($profiles);
            }

            foreach (($tenantHourlyActiveProfiles[$tenantId] ?? []) as $bucket => $profiles) {
                $tenantHourlyMetrics[$tenantId]['active_users'][$bucket] = count($profiles);
            }

            $lastActiveAt = $tenantStats[$tenantId]['last_active_at'];
            $tenantStats[$tenantId]['last_active_at'] = $lastActiveAt?->toIso8601String();
            $tenantStats[$tenantId]['last_active_label'] = $lastActiveAt?->diffForHumans() ?? 'No recent activity';
            $tenantStats[$tenantId]['latest_order_at'] = $tenantStats[$tenantId]['latest_order_at']?->toIso8601String();
            $tenantStats[$tenantId]['latest_profile_at'] = $tenantStats[$tenantId]['latest_profile_at']?->toIso8601String();
            $tenantStats[$tenantId]['latest_automation_at'] = $tenantStats[$tenantId]['latest_automation_at']?->toIso8601String();
            $tenantStats[$tenantId]['latest_reward_at'] = $tenantStats[$tenantId]['latest_reward_at']?->toIso8601String();
            $tenantStats[$tenantId]['active_users_count'] = collect((array) ($tenantDailyActiveProfiles[$tenantId] ?? []))
                ->reduce(function (array $carry, array $profiles): array {
                    foreach (array_keys($profiles) as $profileId) {
                        $carry[$profileId] = true;
                    }

                    return $carry;
                }, []) !== []
                ? count(collect((array) ($tenantDailyActiveProfiles[$tenantId] ?? []))
                    ->reduce(function (array $carry, array $profiles): array {
                        foreach (array_keys($profiles) as $profileId) {
                            $carry[$profileId] = true;
                        }

                        return $carry;
                    }, []))
                : 0;
        }

        return [
            'tenant_stats' => $tenantStats,
            'tenant_daily_metrics' => $tenantDailyMetrics,
            'tenant_hourly_metrics' => $tenantHourlyMetrics,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function emptyTenantManagementAnalytics(): array
    {
        return [
            'sales_generated_cents' => 0,
            'orders_count' => 0,
            'customers_onboarded' => 0,
            'customer_count' => 0,
            'active_users_count' => 0,
            'rewards_redeemed_cents' => 0,
            'latest_order_at' => null,
            'latest_profile_at' => null,
            'latest_automation_at' => null,
            'latest_reward_at' => null,
            'last_active_at' => null,
            'last_active_label' => 'No recent activity',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $metrics
     */
    protected function incrementTenantMetric(array &$metrics, int $tenantId, string $metric, string $bucket, int $value): void
    {
        if (! isset($metrics[$tenantId][$metric])) {
            $metrics[$tenantId][$metric] = [];
        }

        $metrics[$tenantId][$metric][$bucket] = ((int) ($metrics[$tenantId][$metric][$bucket] ?? 0)) + $value;
    }

    protected function maxTimestamp(?CarbonImmutable $current, CarbonImmutable $candidate): CarbonImmutable
    {
        if ($current === null || $candidate->greaterThan($current)) {
            return $candidate;
        }

        return $current;
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,array<string,mixed>>  $planCatalog
     * @param  array<string,array<string,mixed>>  $addonCatalog
     */
    protected function resolveMonthlySubscriptionCents(array $row, array $planCatalog, array $addonCatalog): int
    {
        /** @var Tenant $tenant */
        $tenant = $row['tenant'];
        $resolvedPlanKey = strtolower(trim((string) ($row['resolved_plan_key'] ?? $row['plan_key'] ?? 'starter')));
        $monthlyCents = $this->resolveBasePlanSubscriptionCents($tenant, $resolvedPlanKey, $planCatalog);

        foreach ((array) ($row['addon_states'] ?? []) as $addonKey => $enabled) {
            if (! $enabled) {
                continue;
            }

            $normalizedAddonKey = strtolower(trim((string) $addonKey));
            $addonDefinition = $addonCatalog[$normalizedAddonKey] ?? [];
            $addonOverride = data_get($tenant, 'commercialOverride.addon_pricing_overrides.'.$normalizedAddonKey, []);
            $monthlyCents += (int) data_get($addonOverride, 'recurring_price_cents', $addonDefinition['recurring_price_cents'] ?? 0);
        }

        return max(0, $monthlyCents);
    }

    /**
     * @param  array<string,array<string,mixed>>  $planCatalog
     */
    protected function resolveBasePlanSubscriptionCents(Tenant $tenant, string $resolvedPlanKey, array $planCatalog): int
    {
        $planDefinition = $planCatalog[$resolvedPlanKey] ?? [];
        $planOverride = data_get($tenant, 'commercialOverride.plan_pricing_overrides.'.$resolvedPlanKey, []);

        return max(0, (int) data_get($planOverride, 'recurring_price_cents', $planDefinition['recurring_price_cents'] ?? 0));
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $stats
     * @return array{key:string,label:string,tone:string}
     */
    protected function deriveTenantManagementStatus(array $row, array $stats, int $monthlySubscriptionCents): array
    {
        $lastActiveAt = filled($stats['last_active_at'] ?? null)
            ? CarbonImmutable::parse((string) $stats['last_active_at'])
            : null;
        $billingReady = (bool) data_get($row, 'billing_readiness.ready_for_activation_prep', false);

        if ($monthlySubscriptionCents > 0 && ! $billingReady) {
            return ['key' => 'attention', 'label' => 'Needs attention', 'tone' => 'amber'];
        }

        if ($lastActiveAt && $lastActiveAt->greaterThanOrEqualTo(now()->subDays(14))) {
            return ['key' => 'active', 'label' => 'Active', 'tone' => 'emerald'];
        }

        if ($lastActiveAt && $lastActiveAt->greaterThanOrEqualTo(now()->subDays(45))) {
            return ['key' => 'monitoring', 'label' => 'Monitoring', 'tone' => 'sky'];
        }

        return ['key' => 'quiet', 'label' => 'Quiet', 'tone' => 'zinc'];
    }

    /**
     * @param  array<string,mixed>  $stats
     * @return array<int,array<string,string>>
     */
    protected function summarizeRecentTenantActivity(array $stats): array
    {
        $items = [
            ['label' => 'Latest order', 'at' => (string) ($stats['latest_order_at'] ?? '')],
            ['label' => 'Latest onboarding', 'at' => (string) ($stats['latest_profile_at'] ?? '')],
            ['label' => 'Latest automation activity', 'at' => (string) ($stats['latest_automation_at'] ?? '')],
            ['label' => 'Latest reward redemption', 'at' => (string) ($stats['latest_reward_at'] ?? '')],
        ];

        return collect($items)
            ->filter(fn (array $item): bool => $item['at'] !== '')
            ->sortByDesc('at')
            ->map(fn (array $item): array => [
                'label' => $item['label'],
                'at' => CarbonImmutable::parse($item['at'])->diffForHumans(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,int>  $dailySeries
     * @return array<int,int>
     */
    protected function buildSparklinePoints(array $dailySeries): array
    {
        $points = [];
        $cursor = CarbonImmutable::today()->startOfWeek()->subWeeks(7);

        for ($index = 0; $index < 8; $index++) {
            $weekStart = $cursor->addWeeks($index);
            $weekEnd = $weekStart->endOfWeek();
            $total = 0;

            foreach ($dailySeries as $day => $value) {
                $dayDate = CarbonImmutable::parse($day)->startOfDay();
                if ($dayDate->betweenIncluded($weekStart, $weekEnd)) {
                    $total += (int) $value;
                }
            }

            $points[] = $total;
        }

        return $points;
    }

    /**
     * @return array<string,mixed>
     */
    protected function decodeJsonMap(?string $raw): array
    {
        $normalized = trim((string) $raw);
        if ($normalized === '') {
            return [];
        }

        $decoded = json_decode($normalized, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $value
     */
    protected function encodeJsonMap(array $value): string
    {
        if ($value === []) {
            return '';
        }

        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * @param  array<mixed,mixed>  $labels
     * @return array<string,string>
     */
    protected function normalizeDisplayLabels(array $labels): array
    {
        $normalized = [];
        foreach ($labels as $key => $value) {
            if (is_int($key)) {
                continue;
            }

            $moduleKey = strtolower(trim((string) $key));
            if ($moduleKey === '' || ctype_digit($moduleKey)) {
                continue;
            }

            $label = trim((string) $value);
            if ($label === '') {
                continue;
            }

            $normalized[$moduleKey] = $label;
        }

        return $normalized;
    }

    protected function resolvePriceCents(?string $dollars, mixed $cents): ?int
    {
        $dollarsCents = $this->dollarsToCents($dollars);
        if ($dollarsCents !== null) {
            return $dollarsCents;
        }

        if ($cents === null || $cents === '') {
            return null;
        }

        return max(0, (int) $cents);
    }

    protected function dollarsToCents(?string $value): ?int
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        $parts = explode('.', $normalized, 2);
        $whole = (int) ($parts[0] ?? '0');
        $fraction = str_pad(substr((string) ($parts[1] ?? ''), 0, 2), 2, '0');

        return ($whole * 100) + (int) $fraction;
    }
}
