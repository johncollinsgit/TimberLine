<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\IntegrationHealthEvent;
use App\Models\LandlordOperatorAction;
use App\Models\MarketingProfile;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleAccessRequest;
use App\Models\TenantModuleState;
use App\Models\TenantOnboardingJourneyEvent;
use App\Models\User;
use App\Services\Onboarding\OnboardingJourneyDiagnosticsService;
use App\Services\Onboarding\OnboardingJourneyEventPresenter;
use App\Services\Tenancy\LandlordCommercialConfigService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Services\Tenancy\LandlordTenantOperationsService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class LandlordTenantDirectoryController extends Controller
{
    /**
     * @var array<int,string>
     */
    protected const TABS = [
        'overview',
        'onboarding_journey',
        'applications',
        'customers',
        'activity',
        'performance',
        'settings',
    ];

    public function dashboard(): View
    {
        $tenants = $this->tenantDirectoryRows();

        return view('landlord.dashboard', [
            'metrics' => [
                'total_tenants' => $tenants->count(),
                'healthy_tenants' => $tenants->where('status', 'healthy')->count(),
                'tenants_with_connected_shopify' => $tenants->where('connected_shopify_stores_count', '>', 0)->count(),
                'tenants_needing_attention' => $tenants->whereIn('status', [
                    'attention_needed',
                    'shopify_connection_pending',
                    'users_pending',
                    'access_profile_missing',
                ])->count(),
            ],
            'recent_tenants' => $tenants
                ->sortByDesc('created_at')
                ->take(6)
                ->values(),
        ]);
    }

    public function index(): View
    {
        return view('landlord.tenants.index', [
            'tenants' => $this->tenantDirectoryRows(),
            'tenantRoleOptions' => $this->tenantRoleOptions(),
            'tenantTypeOptions' => $this->tenantTypeOptions(),
            'tenantStatusOptions' => $this->tenantStatusOptions(),
            'defaultTenantType' => $this->defaultTenantType(),
            'defaultTenantRole' => $this->defaultTenantRole(),
            'defaultTenantStatus' => 'active',
        ]);
    }

    public function store(Request $request, LandlordCommercialConfigService $commercialService): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'primary_contact_email' => ['nullable', 'email', 'max:255'],
            'tenant_type' => ['required', 'string', 'in:'.implode(',', array_keys($this->tenantTypeOptions()))],
            'role' => ['required', 'string', 'in:'.implode(',', array_keys($this->tenantRoleOptions()))],
            'status' => ['required', 'string', 'in:'.implode(',', array_keys($this->tenantStatusOptions()))],
        ]);

        $requestedSlug = trim((string) ($validated['slug'] ?? ''));
        $slug = $this->uniqueTenantSlug($requestedSlug !== '' ? $requestedSlug : (string) $validated['name']);
        $tenantId = null;

        DB::transaction(function () use ($validated, $slug, $commercialService, $request, &$tenantId): void {
            $tenant = Tenant::query()->create([
                'name' => trim((string) $validated['name']),
                'slug' => $slug,
            ]);

            $tenantId = (int) $tenant->id;

            $profile = $commercialService->assignTenantPlan(
                tenantId: $tenantId,
                planKey: (string) config('entitlements.default_plan', 'starter'),
                operatingMode: (string) $validated['tenant_type'],
                source: 'landlord_tenant_workspace',
                actorId: $request->user()?->id
            );

            $this->mergeAccessProfileAdminMetadata($profile, [
                'primary_contact_email' => $validated['primary_contact_email'] ?? null,
                'status' => (string) $validated['status'],
                'default_role' => (string) $validated['role'],
            ]);

            if ($request->user()) {
                $tenant->users()->syncWithoutDetaching([
                    (int) $request->user()->id => [
                        'role' => (string) $validated['role'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ]);
            }

            $this->applyTenantRoleAcrossMemberships($tenant, (string) $validated['role']);
        });

        if (! is_int($tenantId) || $tenantId <= 0) {
            return back()->withErrors(['tenant_create' => 'Tenant creation did not complete. Please try again.'])->withInput();
        }

        return redirect()
            ->route('landlord.tenants.show', ['tenant' => $tenantId, 'tab' => 'overview'])
            ->with('status', 'Tenant created. You can now manage role, modules, and settings from this workspace.');
    }

    public function update(
        Request $request,
        Tenant $tenant,
        LandlordCommercialConfigService $commercialService
    ): RedirectResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'primary_contact_email' => ['nullable', 'email', 'max:255'],
            'tenant_type' => ['required', 'string', 'in:'.implode(',', array_keys($this->tenantTypeOptions()))],
            'role' => ['required', 'string', 'in:'.implode(',', array_keys($this->tenantRoleOptions()))],
            'status' => ['required', 'string', 'in:'.implode(',', array_keys($this->tenantStatusOptions()))],
        ]);

        DB::transaction(function () use ($validated, $tenant, $commercialService, $request): void {
            $newSlug = trim((string) ($validated['slug'] ?? ''));
            $tenant->name = trim((string) $validated['name']);
            if ($newSlug !== '' && $newSlug !== $tenant->slug) {
                $tenant->slug = $this->uniqueTenantSlug($newSlug, (int) $tenant->id);
            }
            $tenant->save();

            $existingPlanKey = strtolower(trim((string) ($tenant->accessProfile?->plan_key ?? config('entitlements.default_plan', 'starter'))));
            $profile = $commercialService->assignTenantPlan(
                tenantId: (int) $tenant->id,
                planKey: $existingPlanKey,
                operatingMode: (string) $validated['tenant_type'],
                source: 'landlord_tenant_workspace',
                actorId: $request->user()?->id
            );

            $this->mergeAccessProfileAdminMetadata($profile, [
                'primary_contact_email' => $validated['primary_contact_email'] ?? null,
                'status' => (string) $validated['status'],
                'default_role' => (string) $validated['role'],
            ]);

            $this->applyTenantRoleAcrossMemberships($tenant, (string) $validated['role']);
        });

        return back()->with('status', 'Tenant details updated.');
    }

    public function updateRole(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', 'in:'.implode(',', array_keys($this->tenantRoleOptions()))],
        ]);

        $role = (string) $validated['role'];
        $this->applyTenantRoleAcrossMemberships($tenant, $role);

        $profile = $this->accessProfileForTenant($tenant);
        $this->mergeAccessProfileAdminMetadata($profile, [
            'default_role' => $role,
        ]);

        return back()->with('status', 'Tenant role updated.');
    }

    public function updateType(
        Request $request,
        Tenant $tenant,
        LandlordCommercialConfigService $commercialService
    ): RedirectResponse {
        $validated = $request->validate([
            'tenant_type' => ['required', 'string', 'in:'.implode(',', array_keys($this->tenantTypeOptions()))],
        ]);

        $tenantType = strtolower(trim((string) $validated['tenant_type']));
        $planKey = strtolower(trim((string) ($tenant->accessProfile?->plan_key ?? config('entitlements.default_plan', 'starter'))));

        $profile = $commercialService->assignTenantPlan(
            tenantId: (int) $tenant->id,
            planKey: $planKey,
            operatingMode: $tenantType,
            source: 'landlord_tenant_workspace',
            actorId: $request->user()?->id
        );

        // Future extension point: map tenant type to module preset templates.
        $this->mergeAccessProfileAdminMetadata($profile, [
            'tenant_type_template_hint' => 'manual',
        ]);

        return back()->with('status', 'Tenant type updated.');
    }

    public function updateModules(
        Request $request,
        Tenant $tenant,
        LandlordCommercialConfigService $commercialService
    ): RedirectResponse {
        $catalogKeys = array_values(array_filter(array_map(
            static fn ($key): string => strtolower(trim((string) $key)),
            array_keys((array) config('module_catalog.modules', []))
        )));

        $validated = $request->validate([
            'modules' => ['nullable', 'array'],
            'modules.*' => ['nullable'],
        ]);

        $requested = (array) ($validated['modules'] ?? []);
        $enabledLookup = [];

        foreach ($requested as $moduleKey => $value) {
            $normalizedKey = strtolower(trim((string) $moduleKey));
            if (! in_array($normalizedKey, $catalogKeys, true)) {
                continue;
            }

            if ($value === true || $value === 1 || $value === '1' || $value === 'on' || $value === 'true') {
                $enabledLookup[$normalizedKey] = true;
            }
        }

        $existingStateByKey = TenantModuleState::query()
            ->forTenantId((int) $tenant->id)
            ->get()
            ->keyBy(fn (TenantModuleState $row): string => strtolower(trim((string) $row->module_key)));

        DB::transaction(function () use ($catalogKeys, $enabledLookup, $existingStateByKey, $commercialService, $tenant, $request): void {
            foreach ($catalogKeys as $moduleKey) {
                $enabled = array_key_exists($moduleKey, $enabledLookup);
                /** @var TenantModuleState|null $existing */
                $existing = $existingStateByKey->get($moduleKey);

                $commercialService->setTenantModuleState(
                    tenantId: (int) $tenant->id,
                    moduleKey: $moduleKey,
                    enabledOverride: $enabled,
                    setupStatus: $existing?->setup_status,
                    actorId: $request->user()?->id
                );
            }
        });

        return back()->with('status', 'Module access updated.');
    }

    public function removeUser(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'action' => ['required', 'string', 'in:detach,delete_account'],
            'confirmation' => ['required', 'string', 'max:255'],
        ]);

        /** @var User|null $user */
        $user = User::query()->find((int) $validated['user_id']);
        if (! $user) {
            return back()->withErrors(['tenant_user' => 'Selected user was not found.']);
        }

        $isMember = $tenant->users()->where('users.id', (int) $user->id)->exists();
        if (! $isMember) {
            return back()->withErrors(['tenant_user' => 'Selected user is not assigned to this tenant.']);
        }

        $confirmation = strtolower(trim((string) $validated['confirmation']));
        $expectedConfirmation = strtolower(trim((string) ($user->email ?? '')));
        if ($confirmation === '' || $confirmation !== $expectedConfirmation) {
            return back()->withErrors([
                'tenant_user' => 'Type the selected user email exactly to confirm this action.',
            ]);
        }

        $action = (string) $validated['action'];

        if ($action === 'delete_account') {
            $otherMemberships = $user->tenants()
                ->where('tenants.id', '!=', (int) $tenant->id)
                ->count();

            if ($otherMemberships > 0) {
                return back()->withErrors([
                    'tenant_user' => 'This user still belongs to other tenants. Remove those memberships before deleting the account.',
                ]);
            }

            DB::transaction(function () use ($tenant, $user): void {
                $tenant->users()->detach((int) $user->id);
                $user->delete();
            });

            return back()->with('status', 'User account deleted.');
        }

        $tenant->users()->detach((int) $user->id);

        return back()->with('status', 'User removed from this tenant.');
    }

    public function destroy(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'confirmation' => ['required', 'string', 'max:255'],
        ]);

        $confirmation = trim((string) $validated['confirmation']);
        if (mb_strtolower($confirmation) !== mb_strtolower((string) $tenant->name)) {
            return back()->withErrors([
                'tenant_delete' => 'Type the tenant name exactly to confirm deletion.',
            ]);
        }

        $tenantName = (string) $tenant->name;

        try {
            $tenant->delete();
        } catch (Throwable $exception) {
            return back()->withErrors([
                'tenant_delete' => 'Tenant deletion was blocked: '.$exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('landlord.tenants.index')
            ->with('status', sprintf('Tenant "%s" deleted.', $tenantName));
    }

    public function show(
        Request $request,
        Tenant $tenant,
        LandlordTenantOperationsService $operationsService,
        LandlordOperatorActionAuditService $auditService,
        TenantModuleAccessResolver $moduleAccessResolver,
        OnboardingJourneyDiagnosticsService $journeyDiagnostics
    ): View {
        $hydratedTenant = $this->tenantDetailQuery()->findOrFail($tenant->getKey());
        $hydratedTenant->load([
            'users' => fn ($query) => $query
                ->select([
                    'users.id',
                    'users.name',
                    'users.email',
                    'users.role',
                    'users.is_active',
                ])
                ->orderBy('users.name'),
        ]);

        $summary = $this->presentTenant($hydratedTenant);

        /** @var Collection<int,ShopifyStore> $storeRows */
        $storeRows = $hydratedTenant->relationLoaded('shopifyStores')
            ? $hydratedTenant->shopifyStores
            : collect();

        $recentOperatorActions = $auditService->recentForTenant((int) $hydratedTenant->id, 25);
        $activeTab = $this->resolveTab((string) $request->query('tab', 'overview'));
        $tenantType = $this->resolvedTenantType($hydratedTenant);
        $tenantRole = $this->resolvedTenantRole($hydratedTenant);
        $tenantStatus = $this->resolvedTenantAdminStatus($hydratedTenant, (string) ($summary['status_label'] ?? 'Healthy'));
        $primaryContactEmail = $this->tenantPrimaryContactEmail($hydratedTenant);

        $moduleResolution = $moduleAccessResolver->resolveForTenant((int) $hydratedTenant->id);
        $moduleGroups = $this->groupResolvedModules((array) ($moduleResolution['modules'] ?? []));
        $enabledModuleCount = collect((array) ($moduleResolution['modules'] ?? []))
            ->filter(fn (array $module): bool => (bool) ($module['enabled'] ?? false))
            ->count();

        $customerSearch = trim((string) $request->query('customer_search', ''));

        $journeyOverview = null;
        if ($activeTab === 'overview') {
            $journeyOverview = $journeyDiagnostics->overview((int) $hydratedTenant->id);
        }

        $journeyDetail = null;
        if ($activeTab === 'onboarding_journey') {
            $requestedBlueprintId = $request->query('final_blueprint_id');
            $finalBlueprintId = is_numeric($requestedBlueprintId) ? (int) $requestedBlueprintId : null;

            $journeyDetail = $journeyDiagnostics->detail(
                tenantId: (int) $hydratedTenant->id,
                finalBlueprintId: $finalBlueprintId,
                limit: 200
            );

            $resolvedBlueprintId = is_numeric(data_get($journeyDetail, 'final_blueprint_id')) ? (int) data_get($journeyDetail, 'final_blueprint_id') : null;

            if ($finalBlueprintId !== null && $finalBlueprintId > 0 && $resolvedBlueprintId !== $finalBlueprintId) {
                abort(404);
            }

            if ($finalBlueprintId !== null && $finalBlueprintId > 0 && (int) data_get($journeyDetail, 'meta.raw_event_count', 0) <= 0) {
                abort(404);
            }
        }

        return view('landlord.tenants.show', [
            'tenant' => $hydratedTenant,
            'summary' => $summary,
            'shopifyStores' => $storeRows,
            'tenantConfirmationPhrase' => $operationsService->confirmationPhraseForTenant($hydratedTenant),
            'tenantApplyRestorePhrase' => $operationsService->applyRestorePhraseForTenant($hydratedTenant),
            'tenantOverwritePhrase' => $operationsService->overwritePhraseForTenant($hydratedTenant),
            'snapshotRetentionDays' => $operationsService->snapshotRetentionDays(),
            'snapshotMaxBytes' => $operationsService->snapshotMaxBytes(),
            'snapshotTables' => $operationsService->snapshotTables(),
            'recentTenantCustomers' => $operationsService->recentTenantCustomerRows($hydratedTenant, 25),
            'recentOperatorActions' => $recentOperatorActions,
            'operatorActionSummary' => [
                'total' => $recentOperatorActions->count(),
                'success' => $recentOperatorActions->where('status', 'success')->count(),
                'blocked' => $recentOperatorActions->where('status', 'blocked')->count(),
                'failed' => $recentOperatorActions->where('status', 'failed')->count(),
            ],
            'tabs' => self::TABS,
            'activeTab' => $activeTab,
            'tenantType' => $tenantType,
            'tenantRole' => $tenantRole,
            'tenantStatus' => $tenantStatus,
            'primaryContactEmail' => $primaryContactEmail,
            'tenantRoleOptions' => $this->tenantRoleOptions(),
            'tenantTypeOptions' => $this->tenantTypeOptions(),
            'tenantStatusOptions' => $this->tenantStatusOptions(),
            'moduleGroups' => $moduleGroups,
            'enabledModuleCount' => $enabledModuleCount,
            'applications' => $this->tenantApplications((int) $hydratedTenant->id),
            'activityRows' => $this->tenantActivityRows((int) $hydratedTenant->id),
            'tenantCustomers' => $this->tenantCustomers((int) $hydratedTenant->id, $customerSearch),
            'customerSearch' => $customerSearch,
            'performanceRange' => $this->normalizePerformanceRange((string) $request->query('range', '30d')),
            'performanceRanges' => $this->performanceRangeOptions(),
            'performance' => $this->tenantPerformance((int) $hydratedTenant->id, (string) $request->query('range', '30d')),
            'onboardingJourneyDetail' => $journeyDetail,
            'onboardingJourneyOverview' => $journeyOverview,
        ]);
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    protected function tenantDirectoryRows(): Collection
    {
        return $this->tenantListQuery()
            ->orderBy('name')
            ->get()
            ->map(fn (Tenant $tenant): array => $this->presentTenant($tenant))
            ->values();
    }

    protected function tenantListQuery(): Builder
    {
        $query = Tenant::query()
            ->withCount([
                'users',
                'shopifyStores',
                'shopifyStores as connected_shopify_stores_count' => fn (Builder $query): Builder => $query->whereNotNull('installed_at'),
            ])
            ->with([
                'shopifyStores' => fn ($query) => $query
                    ->select([
                        'id',
                        'tenant_id',
                        'store_key',
                        'shop_domain',
                        'installed_at',
                        'created_at',
                        'updated_at',
                    ])
                    ->orderByRaw('case when installed_at is null then 1 else 0 end')
                    ->orderByDesc('installed_at')
                    ->orderBy('id'),
            ]);

        if (Schema::hasTable('tenant_access_profiles')) {
            $query->with('accessProfile');
        }

        if (Schema::hasTable('tenant_module_states')) {
            $query->with([
                'moduleStates' => fn ($query) => $query->select([
                    'id',
                    'tenant_id',
                    'module_key',
                    'setup_status',
                    'enabled_override',
                    'updated_at',
                ]),
            ]);
        }

        if (Schema::hasTable('integration_health_events')) {
            $query->withCount([
                'integrationHealthEvents as open_integration_health_events_count' => fn (Builder $query): Builder => $query->where('status', 'open'),
            ]);
        }

        return $query;
    }

    protected function tenantDetailQuery(): Builder
    {
        return $this->tenantListQuery();
    }

    /**
     * @return array<string,mixed>
     */
    protected function presentTenant(Tenant $tenant): array
    {
        /** @var Collection<int,ShopifyStore> $stores */
        $stores = $tenant->relationLoaded('shopifyStores')
            ? $tenant->shopifyStores
            : collect();

        $primaryStore = $stores->first();
        $userCount = (int) ($tenant->users_count ?? 0);
        $shopifyStoreCount = (int) ($tenant->shopify_stores_count ?? 0);
        $connectedShopifyStoreCount = (int) ($tenant->connected_shopify_stores_count ?? 0);
        $openHealthEventCount = (int) ($tenant->open_integration_health_events_count ?? 0);

        $accessProfile = $tenant->relationLoaded('accessProfile') ? $tenant->accessProfile : null;
        $operatingMode = strtolower(trim((string) ($accessProfile?->operating_mode ?? 'shopify')));
        $metadata = is_array($accessProfile?->metadata) ? $accessProfile->metadata : [];
        $adminMetadata = is_array($metadata['admin'] ?? null) ? $metadata['admin'] : [];

        $status = $this->derivedStatus(
            hasAccessProfile: $accessProfile !== null,
            operatingMode: $operatingMode,
            userCount: $userCount,
            connectedShopifyStoreCount: $connectedShopifyStoreCount,
            openHealthEventCount: $openHealthEventCount,
        );

        $tenantType = array_key_exists($operatingMode, $this->tenantTypeOptions())
            ? $operatingMode
            : $this->defaultTenantType();

        $tenantRole = strtolower(trim((string) ($adminMetadata['default_role'] ?? 'member')));
        if (! array_key_exists($tenantRole, $this->tenantRoleOptions())) {
            $tenantRole = 'member';
        }

        $tenantAdminStatus = strtolower(trim((string) ($adminMetadata['status'] ?? '')));
        if (! array_key_exists($tenantAdminStatus, $this->tenantStatusOptions())) {
            $tenantAdminStatus = $status === 'healthy' ? 'active' : 'inactive';
        }

        $primaryContactEmail = trim((string) ($adminMetadata['primary_contact_email'] ?? ''));

        $moduleSetup = $this->moduleSetupSummary($tenant);

        return [
            'tenant' => $tenant,
            'id' => (int) $tenant->id,
            'name' => (string) $tenant->name,
            'slug' => (string) $tenant->slug,
            'subdomain' => (string) $tenant->slug.'.'.$this->tenantBaseHost(),
            'created_at' => optional($tenant->created_at)->toDateTimeString(),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'tenant_type' => $tenantType,
            'tenant_type_label' => (string) data_get($this->tenantTypeOptions(), $tenantType, Str::headline($tenantType)),
            'tenant_role' => $tenantRole,
            'tenant_role_label' => (string) data_get($this->tenantRoleOptions(), $tenantRole, Str::headline($tenantRole)),
            'tenant_status' => $tenantAdminStatus,
            'tenant_status_label' => (string) data_get($this->tenantStatusOptions(), $tenantAdminStatus, Str::headline($tenantAdminStatus)),
            'primary_contact_email' => $primaryContactEmail !== '' ? $primaryContactEmail : null,
            'user_count' => $userCount,
            'shopify_stores_count' => $shopifyStoreCount,
            'connected_shopify_stores_count' => $connectedShopifyStoreCount,
            'primary_store_key' => $primaryStore?->store_key,
            'primary_shopify_domain' => $primaryStore?->shop_domain,
            'open_integration_health_events_count' => $openHealthEventCount,
            'access_profile' => [
                'plan_key' => $accessProfile?->plan_key,
                'operating_mode' => $accessProfile?->operating_mode,
                'source' => $accessProfile?->source,
            ],
            'health' => [
                'has_users' => $userCount > 0,
                'has_connected_shopify_store' => $connectedShopifyStoreCount > 0,
                'has_access_profile' => $accessProfile !== null,
                'open_integration_health_events' => $openHealthEventCount,
            ],
            'module_setup' => $moduleSetup,
        ];
    }

    protected function tenantBaseHost(): string
    {
        $landlordHost = strtolower(trim((string) config('tenancy.landlord.primary_host', 'app.forestrybackstage.com')));
        if ($landlordHost === '') {
            return 'forestrybackstage.com';
        }

        if (str_starts_with($landlordHost, 'app.')) {
            $base = substr($landlordHost, 4);

            return $base !== '' ? $base : 'forestrybackstage.com';
        }

        return $landlordHost;
    }

    /**
     * @return array{configured:int,in_progress:int,not_started:int,other:int}
     */
    protected function moduleSetupSummary(Tenant $tenant): array
    {
        $counts = [
            'configured' => 0,
            'in_progress' => 0,
            'not_started' => 0,
            'other' => 0,
        ];

        if (! $tenant->relationLoaded('moduleStates')) {
            return $counts;
        }

        foreach ($tenant->moduleStates as $moduleState) {
            $status = strtolower(trim((string) ($moduleState->setup_status ?? 'not_started')));

            if (! array_key_exists($status, $counts)) {
                $status = 'other';
            }

            $counts[$status]++;
        }

        return $counts;
    }

    protected function derivedStatus(
        bool $hasAccessProfile,
        string $operatingMode,
        int $userCount,
        int $connectedShopifyStoreCount,
        int $openHealthEventCount,
    ): string {
        if (! $hasAccessProfile) {
            return 'access_profile_missing';
        }

        if ($userCount <= 0) {
            return 'users_pending';
        }

        if ($operatingMode === 'shopify' && $connectedShopifyStoreCount <= 0) {
            return 'shopify_connection_pending';
        }

        if ($openHealthEventCount > 0) {
            return 'attention_needed';
        }

        return 'healthy';
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'access_profile_missing' => 'Access Profile Missing',
            'users_pending' => 'Users Pending',
            'shopify_connection_pending' => 'Shopify Connection Pending',
            'attention_needed' => 'Attention Needed',
            default => 'Healthy',
        };
    }

    protected function resolveTab(string $tab): string
    {
        $normalized = str_replace('-', '_', strtolower(trim($tab)));

        return in_array($normalized, self::TABS, true) ? $normalized : 'overview';
    }

    /**
     * @return array<string,string>
     */
    protected function tenantRoleOptions(): array
    {
        return [
            'admin' => 'Admin',
            'manager' => 'Manager',
            'marketing_manager' => 'Marketing Manager',
            'member' => 'Member',
        ];
    }

    /**
     * @return array<string,string>
     */
    protected function tenantTypeOptions(): array
    {
        return [
            'shopify' => 'Shopify',
            'direct' => 'Direct',
        ];
    }

    /**
     * @return array<string,string>
     */
    protected function tenantStatusOptions(): array
    {
        return [
            'active' => 'Active',
            'inactive' => 'Inactive',
            'suspended' => 'Suspended',
        ];
    }

    protected function defaultTenantType(): string
    {
        $default = strtolower(trim((string) config('entitlements.default_operating_mode', 'shopify')));

        return array_key_exists($default, $this->tenantTypeOptions()) ? $default : 'shopify';
    }

    protected function defaultTenantRole(): string
    {
        return 'manager';
    }

    protected function resolvedTenantType(Tenant $tenant): string
    {
        $type = strtolower(trim((string) ($tenant->accessProfile?->operating_mode ?? $this->defaultTenantType())));

        return array_key_exists($type, $this->tenantTypeOptions()) ? $type : $this->defaultTenantType();
    }

    protected function resolvedTenantRole(Tenant $tenant): string
    {
        $roleCounts = [];

        foreach ($tenant->users as $user) {
            $role = strtolower(trim((string) ($user->pivot->role ?? '')));
            if ($role === '') {
                continue;
            }

            $roleCounts[$role] = (int) ($roleCounts[$role] ?? 0) + 1;
        }

        if ($roleCounts !== []) {
            arsort($roleCounts);
            $resolved = (string) array_key_first($roleCounts);

            return array_key_exists($resolved, $this->tenantRoleOptions()) ? $resolved : 'member';
        }

        $metadataRole = strtolower(trim((string) data_get($tenant->accessProfile?->metadata, 'admin.default_role', '')));
        if ($metadataRole !== '' && array_key_exists($metadataRole, $this->tenantRoleOptions())) {
            return $metadataRole;
        }

        return 'member';
    }

    protected function tenantPrimaryContactEmail(Tenant $tenant): ?string
    {
        $email = trim((string) data_get($tenant->accessProfile?->metadata, 'admin.primary_contact_email', ''));

        return $email !== '' ? $email : null;
    }

    protected function resolvedTenantAdminStatus(Tenant $tenant, string $fallbackStatusLabel): string
    {
        $status = strtolower(trim((string) data_get($tenant->accessProfile?->metadata, 'admin.status', '')));
        if (array_key_exists($status, $this->tenantStatusOptions())) {
            return $status;
        }

        return strtolower(trim($fallbackStatusLabel)) === 'healthy' ? 'active' : 'inactive';
    }

    protected function uniqueTenantSlug(string $base, ?int $ignoreTenantId = null): string
    {
        $slugBase = Str::slug($base);
        if ($slugBase === '') {
            $slugBase = 'tenant';
        }

        $candidate = $slugBase;
        $counter = 2;

        while (Tenant::query()
            ->when($ignoreTenantId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreTenantId))
            ->where('slug', $candidate)
            ->exists()) {
            $candidate = $slugBase.'-'.$counter;
            $counter++;
        }

        return $candidate;
    }

    protected function accessProfileForTenant(Tenant $tenant): TenantAccessProfile
    {
        return TenantAccessProfile::query()->updateOrCreate(
            [
                'tenant_id' => (int) $tenant->id,
            ],
            [
                'plan_key' => strtolower(trim((string) ($tenant->accessProfile?->plan_key ?? config('entitlements.default_plan', 'starter')))),
                'operating_mode' => strtolower(trim((string) ($tenant->accessProfile?->operating_mode ?? $this->defaultTenantType()))),
                'source' => (string) ($tenant->accessProfile?->source ?? 'landlord_tenant_workspace'),
                'metadata' => is_array($tenant->accessProfile?->metadata) ? $tenant->accessProfile->metadata : [],
            ]
        );
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    protected function mergeAccessProfileAdminMetadata(TenantAccessProfile $profile, array $attributes): void
    {
        $metadata = is_array($profile->metadata) ? $profile->metadata : [];
        $admin = is_array($metadata['admin'] ?? null) ? $metadata['admin'] : [];

        foreach ($attributes as $key => $value) {
            if ($value === null || trim((string) $value) === '') {
                unset($admin[$key]);
                continue;
            }

            $admin[$key] = trim((string) $value);
        }

        $metadata['admin'] = $admin;
        $profile->metadata = $metadata;
        $profile->save();
    }

    protected function applyTenantRoleAcrossMemberships(Tenant $tenant, string $role): void
    {
        $normalizedRole = strtolower(trim($role));
        if (! array_key_exists($normalizedRole, $this->tenantRoleOptions())) {
            return;
        }

        $hasMembers = DB::table('tenant_user')
            ->where('tenant_id', (int) $tenant->id)
            ->exists();

        if (! $hasMembers) {
            return;
        }

        DB::table('tenant_user')
            ->where('tenant_id', (int) $tenant->id)
            ->update([
                'role' => $normalizedRole,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string,array<string,mixed>>  $modules
     * @return array<int,array<string,mixed>>
     */
    protected function groupResolvedModules(array $modules): array
    {
        $groups = [
            'shared-core' => [
                'label' => 'Core modules',
                'items' => [],
            ],
            'shopify-only' => [
                'label' => 'Shopify modules',
                'items' => [],
            ],
            'integration-layer' => [
                'label' => 'Integrations',
                'items' => [],
            ],
            'add-on' => [
                'label' => 'Add-on modules',
                'items' => [],
            ],
            'internal-admin' => [
                'label' => 'Internal modules',
                'items' => [],
            ],
            'other' => [
                'label' => 'Other modules',
                'items' => [],
            ],
        ];

        foreach ($modules as $moduleKey => $module) {
            $classification = strtolower(trim((string) ($module['classification'] ?? 'other')));
            if (! array_key_exists($classification, $groups)) {
                $classification = 'other';
            }

            $groups[$classification]['items'][] = [
                'key' => (string) ($module['module_key'] ?? $moduleKey),
                'label' => (string) ($module['label'] ?? Str::headline((string) $moduleKey)),
                'description' => (string) ($module['description'] ?? ''),
                'enabled' => (bool) ($module['enabled'] ?? false),
                'status' => (string) ($module['status'] ?? 'disabled'),
                'source' => (string) ($module['source'] ?? 'flag'),
            ];
        }

        return collect($groups)
            ->map(function (array $group): array {
                $items = collect((array) ($group['items'] ?? []))
                    ->sortBy('label')
                    ->values()
                    ->all();

                return [
                    'label' => (string) ($group['label'] ?? 'Modules'),
                    'items' => $items,
                ];
            })
            ->filter(fn (array $group): bool => $group['items'] !== [])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    protected function tenantApplications(int $tenantId): Collection
    {
        if (! Schema::hasTable('tenant_module_access_requests')) {
            return collect();
        }

        /** @var Collection<int,TenantModuleAccessRequest> $rows */
        $rows = TenantModuleAccessRequest::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'requester:id,name,email',
                'resolver:id,name,email',
            ])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return $rows->map(function (TenantModuleAccessRequest $row) use ($tenantId): array {
            $moduleKey = strtolower(trim((string) $row->module_key));
            $moduleName = (string) data_get(config('module_catalog.modules'), $moduleKey.'.display_name', Str::headline($moduleKey));

            return [
                'id' => (int) $row->id,
                'title' => $moduleName,
                'module_key' => $moduleKey,
                'status' => (string) ($row->status ?? 'pending'),
                'status_label' => Str::headline((string) ($row->status ?? 'pending')),
                'created_at' => optional($row->requested_at ?? $row->created_at)?->toDateTimeString(),
                'updated_at' => optional($row->resolved_at ?? $row->updated_at)?->toDateTimeString(),
                'actor' => $row->requester?->name ?: ($row->requester?->email ?: 'Unknown'),
                'action_url' => route('landlord.tenants.show', [
                    'tenant' => $tenantId,
                    'tab' => 'settings',
                ]),
            ];
        })->values();
    }

    /**
     * @return Collection<int,MarketingProfile>
     */
    protected function tenantCustomers(int $tenantId, string $search = ''): Collection
    {
        if (! Schema::hasTable('marketing_profiles')) {
            return collect();
        }

        $query = MarketingProfile::query()
            ->forTenantId($tenantId)
            ->select([
                'id',
                'tenant_id',
                'first_name',
                'last_name',
                'email',
                'phone',
                'updated_at',
                'created_at',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $searchToken = trim($search);
        if ($searchToken !== '') {
            $query->where(function (Builder $builder) use ($searchToken): void {
                $builder
                    ->where('first_name', 'like', '%'.$searchToken.'%')
                    ->orWhere('last_name', 'like', '%'.$searchToken.'%')
                    ->orWhere('email', 'like', '%'.$searchToken.'%')
                    ->orWhere('phone', 'like', '%'.$searchToken.'%');
            });
        }

        return $query->limit(150)->get();
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    protected function tenantActivityRows(int $tenantId): Collection
    {
        $entries = [];
        $onboardingPresenter = app(OnboardingJourneyEventPresenter::class);

        if (Schema::hasTable('landlord_operator_actions')) {
            $operatorRows = LandlordOperatorAction::query()
                ->where('tenant_id', $tenantId)
                ->with('actor:id,name,email')
                ->orderByDesc('id')
                ->limit(80)
                ->get();

            foreach ($operatorRows as $row) {
                $timestamp = $row->created_at ? CarbonImmutable::parse($row->created_at) : null;
                $relatedTenantId = $this->relatedTenantIdFromOperatorAction($row, $tenantId);

                $entries[] = [
                    'key' => 'operator-'.$row->id,
                    'event' => Str::headline((string) $row->action_type),
                    'actor' => $row->actor?->name ?: ($row->actor?->email ?: ($row->actor_user_id ? 'User #'.$row->actor_user_id : 'System')),
                    'time' => $timestamp?->toDateTimeString(),
                    'time_label' => $timestamp?->diffForHumans() ?? 'n/a',
                    'related_entity' => trim(implode(' ', array_filter([
                        $row->target_type,
                        $row->target_id ? '#'.$row->target_id : null,
                    ]))) ?: 'tenant',
                    'status' => (string) ($row->status ?? 'recorded'),
                    'related_tenant_id' => $relatedTenantId,
                    'related_tenant_url' => $relatedTenantId !== null
                        ? route('landlord.tenants.show', ['tenant' => $relatedTenantId, 'tab' => 'overview'])
                        : null,
                    'action_url' => null,
                    'timestamp' => $timestamp?->timestamp ?? 0,
                ];
            }
        }

        if (Schema::hasTable('tenant_onboarding_journey_events')) {
            $telemetryRows = TenantOnboardingJourneyEvent::query()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('occurred_at')
                ->limit(80)
                ->get();

            $actorIds = $telemetryRows
                ->pluck('actor_user_id')
                ->filter(fn ($value) => is_numeric($value) && (int) $value > 0)
                ->map(fn ($value) => (int) $value)
                ->unique()
                ->values()
                ->all();

            $actorLookup = $actorIds !== []
                ? User::query()->whereIn('id', $actorIds)->get(['id', 'name', 'email'])->keyBy('id')
                : collect();

            foreach ($telemetryRows as $row) {
                $timestamp = $row->occurred_at ? CarbonImmutable::parse($row->occurred_at) : null;
                $actorId = is_numeric($row->actor_user_id) ? (int) $row->actor_user_id : null;
                $actor = $actorId !== null ? $actorLookup->get($actorId) : null;

                $payload = is_array($row->payload ?? null) ? (array) $row->payload : [];
                $payloadSummary = $onboardingPresenter->payloadSummary((string) $row->event_key, $payload);
                $contextItems = $onboardingPresenter->contextSummaryItems((string) $row->event_key, $payloadSummary);

                $finalBlueprintId = is_numeric($row->final_blueprint_id) ? (int) $row->final_blueprint_id : null;
                $actionUrl = $finalBlueprintId !== null && $finalBlueprintId > 0
                    ? route('landlord.tenants.show', [
                        'tenant' => $tenantId,
                        'tab' => 'onboarding_journey',
                        'final_blueprint_id' => $finalBlueprintId,
                    ])
                    : null;

                $entries[] = [
                    'key' => 'onboarding-journey-'.$row->id,
                    'event' => $onboardingPresenter->labelForEventKey((string) $row->event_key),
                    'actor' => $actor?->name ?: ($actor?->email ?: ($actorId ? 'User #'.$actorId : 'System')),
                    'time' => $timestamp?->toDateTimeString(),
                    'time_label' => $timestamp?->diffForHumans() ?? 'n/a',
                    'related_entity' => $onboardingPresenter->activityRelatedEntity($finalBlueprintId, $contextItems),
                    'status' => $onboardingPresenter->activityStatusForEventKey((string) $row->event_key),
                    'related_tenant_id' => null,
                    'related_tenant_url' => null,
                    'action_url' => $actionUrl,
                    'timestamp' => $timestamp?->timestamp ?? 0,
                ];
            }
        }

        if (Schema::hasTable('tenant_module_access_requests')) {
            $requestRows = TenantModuleAccessRequest::query()
                ->where('tenant_id', $tenantId)
                ->with([
                    'requester:id,name,email',
                    'resolver:id,name,email',
                ])
                ->orderByDesc('id')
                ->limit(60)
                ->get();

            foreach ($requestRows as $row) {
                $timestamp = $row->requested_at
                    ? CarbonImmutable::parse($row->requested_at)
                    : ($row->created_at ? CarbonImmutable::parse($row->created_at) : null);

                $moduleKey = strtolower(trim((string) $row->module_key));
                $moduleLabel = (string) data_get(config('module_catalog.modules'), $moduleKey.'.display_name', Str::headline($moduleKey));

                $entries[] = [
                    'key' => 'module-request-'.$row->id,
                    'event' => 'Module application '.Str::headline((string) ($row->status ?? 'pending')),
                    'actor' => $row->requester?->name ?: ($row->requester?->email ?: 'Unknown'),
                    'time' => $timestamp?->toDateTimeString(),
                    'time_label' => $timestamp?->diffForHumans() ?? 'n/a',
                    'related_entity' => $moduleLabel,
                    'status' => (string) ($row->status ?? 'pending'),
                    'related_tenant_id' => null,
                    'related_tenant_url' => null,
                    'action_url' => null,
                    'timestamp' => $timestamp?->timestamp ?? 0,
                ];
            }
        }

        if (Schema::hasTable('integration_health_events')) {
            $healthRows = IntegrationHealthEvent::query()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('id')
                ->limit(60)
                ->get();

            foreach ($healthRows as $row) {
                $timestamp = $row->occurred_at
                    ? CarbonImmutable::parse($row->occurred_at)
                    : ($row->created_at ? CarbonImmutable::parse($row->created_at) : null);

                $related = trim(implode(' ', array_filter([
                    $row->provider ? strtoupper((string) $row->provider) : null,
                    $row->store_key ?: null,
                    $row->related_model_type && $row->related_model_id
                        ? '#'.$row->related_model_id
                        : null,
                ])));

                $entries[] = [
                    'key' => 'health-'.$row->id,
                    'event' => Str::headline((string) $row->event_type),
                    'actor' => $row->provider ? strtoupper((string) $row->provider) : 'System',
                    'time' => $timestamp?->toDateTimeString(),
                    'time_label' => $timestamp?->diffForHumans() ?? 'n/a',
                    'related_entity' => $related !== '' ? $related : 'integration',
                    'status' => (string) ($row->status ?? 'open'),
                    'related_tenant_id' => null,
                    'related_tenant_url' => null,
                    'action_url' => null,
                    'timestamp' => $timestamp?->timestamp ?? 0,
                ];
            }
        }

        return collect($entries)
            ->sortByDesc('timestamp')
            ->take(150)
            ->values();
    }

    protected function relatedTenantIdFromOperatorAction(LandlordOperatorAction $action, int $tenantId): ?int
    {
        $candidates = [
            data_get($action->context, 'related_tenant_id'),
            data_get($action->result, 'related_tenant_id'),
            data_get($action->context, 'tenant_id'),
            data_get($action->result, 'tenant_id'),
            strtolower(trim((string) $action->target_type)) === 'tenant' ? $action->target_id : null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_numeric($candidate)) {
                continue;
            }

            $cast = (int) $candidate;
            if ($cast > 0 && $cast !== $tenantId) {
                return $cast;
            }
        }

        return null;
    }

    /**
     * @return array<string,string>
     */
    protected function performanceRangeOptions(): array
    {
        return [
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            '90d' => 'Last 90 days',
            '12m' => 'Last 12 months',
        ];
    }

    protected function normalizePerformanceRange(string $range): string
    {
        $normalized = strtolower(trim($range));

        return array_key_exists($normalized, $this->performanceRangeOptions()) ? $normalized : '30d';
    }

    /**
     * @return array<string,mixed>
     */
    protected function tenantPerformance(int $tenantId, string $range): array
    {
        $resolvedRange = $this->normalizePerformanceRange($range);
        ['start' => $start, 'end' => $end, 'group_by' => $groupBy] = $this->performanceWindow($resolvedRange);
        $buckets = $this->performanceBuckets($start, $end, $groupBy);

        $salesByBucket = array_fill_keys(array_keys($buckets), 0);
        $earnedByBucket = array_fill_keys(array_keys($buckets), 0);
        $netRewardsByBucket = array_fill_keys(array_keys($buckets), 0.0);

        if (Schema::hasTable('orders')) {
            $orderRows = DB::table('orders')
                ->select(['ordered_at', 'created_at', 'total_price'])
                ->where('tenant_id', $tenantId)
                ->where(function ($query) use ($start): void {
                    $query
                        ->where('ordered_at', '>=', $start)
                        ->orWhere(function ($fallback) use ($start): void {
                            $fallback->whereNull('ordered_at')->where('created_at', '>=', $start);
                        });
                })
                ->where(function ($query) use ($end): void {
                    $query
                        ->where('ordered_at', '<=', $end)
                        ->orWhere(function ($fallback) use ($end): void {
                            $fallback->whereNull('ordered_at')->where('created_at', '<=', $end);
                        });
                })
                ->get();

            foreach ($orderRows as $row) {
                $timestamp = $row->ordered_at
                    ? CarbonImmutable::parse((string) $row->ordered_at)
                    : ($row->created_at ? CarbonImmutable::parse((string) $row->created_at) : null);
                if (! $timestamp) {
                    continue;
                }

                $bucketKey = $this->performanceBucketKey($timestamp, $groupBy);
                if (! array_key_exists($bucketKey, $salesByBucket)) {
                    continue;
                }

                $salesByBucket[$bucketKey] += (int) round(((float) ($row->total_price ?? 0)) * 100);
            }
        }

        $initialUnused = 0.0;

        if (Schema::hasTable('candle_cash_transactions') && Schema::hasTable('marketing_profiles')) {
            $txRows = DB::table('candle_cash_transactions as cct')
                ->join('marketing_profiles as mp', 'mp.id', '=', 'cct.marketing_profile_id')
                ->select(['cct.created_at', 'cct.candle_cash_delta'])
                ->where('mp.tenant_id', $tenantId)
                ->whereBetween('cct.created_at', [$start, $end])
                ->orderBy('cct.created_at')
                ->get();

            foreach ($txRows as $row) {
                $timestamp = $row->created_at ? CarbonImmutable::parse((string) $row->created_at) : null;
                if (! $timestamp) {
                    continue;
                }

                $bucketKey = $this->performanceBucketKey($timestamp, $groupBy);
                if (! array_key_exists($bucketKey, $earnedByBucket)) {
                    continue;
                }

                $delta = (float) ($row->candle_cash_delta ?? 0);
                $netRewardsByBucket[$bucketKey] += $delta;
                if ($delta > 0) {
                    $earnedByBucket[$bucketKey] += (int) round($delta * 100);
                }
            }

            $initialUnused = (float) DB::table('candle_cash_transactions as cct')
                ->join('marketing_profiles as mp', 'mp.id', '=', 'cct.marketing_profile_id')
                ->where('mp.tenant_id', $tenantId)
                ->where('cct.created_at', '<', $start)
                ->sum('cct.candle_cash_delta');
        }

        $unusedByBucket = [];
        $rollingUnused = $initialUnused;

        foreach ($buckets as $bucketKey => $meta) {
            $rollingUnused += (float) ($netRewardsByBucket[$bucketKey] ?? 0);
            $unusedByBucket[$bucketKey] = max(0, (int) round($rollingUnused * 100));
        }

        $currentUnusedFromBalances = null;
        if (Schema::hasTable('candle_cash_balances') && Schema::hasTable('marketing_profiles')) {
            $currentUnusedFromBalances = (int) round(((float) DB::table('candle_cash_balances as ccb')
                ->join('marketing_profiles as mp', 'mp.id', '=', 'ccb.marketing_profile_id')
                ->where('mp.tenant_id', $tenantId)
                ->sum('ccb.balance')) * 100);
        }

        $seriesEarned = array_values($earnedByBucket);
        $seriesSales = array_values($salesByBucket);
        $seriesUnused = array_values($unusedByBucket);
        $hasData = array_sum($seriesEarned) > 0 || array_sum($seriesSales) > 0 || array_sum($seriesUnused) > 0;
        $lastUnusedSeriesValue = $seriesUnused !== [] ? (int) end($seriesUnused) : 0;
        $unusedRewardsCents = $currentUnusedFromBalances ?? $lastUnusedSeriesValue;

        return [
            'range' => $resolvedRange,
            'range_label' => (string) data_get($this->performanceRangeOptions(), $resolvedRange, 'Last 30 days'),
            'window' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'group_by' => $groupBy,
            ],
            'summary' => [
                'earned_rewards_cash_cents' => array_sum($seriesEarned),
                'attributable_sales_cents' => array_sum($seriesSales),
                'unused_rewards_cents' => $unusedRewardsCents,
            ],
            'chart' => [
                'categories' => array_values(array_map(
                    static fn (array $bucket): string => (string) ($bucket['label'] ?? ''),
                    array_values($buckets)
                )),
                'series' => [
                    [
                        'name' => 'Earned rewards cash',
                        'data' => $seriesEarned,
                    ],
                    [
                        'name' => 'Attributable sales',
                        'data' => $seriesSales,
                    ],
                    [
                        'name' => 'Unused rewards',
                        'data' => $seriesUnused,
                    ],
                ],
                'empty' => ! $hasData,
                'empty_state' => 'No performance data is available for the selected date range yet.',
            ],
        ];
    }

    /**
     * @return array{start:CarbonImmutable,end:CarbonImmutable,group_by:string}
     */
    protected function performanceWindow(string $range): array
    {
        $end = now()->toImmutable()->endOfDay();

        return match ($range) {
            '7d' => [
                'start' => $end->subDays(6)->startOfDay(),
                'end' => $end,
                'group_by' => 'day',
            ],
            '90d' => [
                'start' => $end->subDays(89)->startOfDay(),
                'end' => $end,
                'group_by' => 'week',
            ],
            '12m' => [
                'start' => $end->subMonths(11)->startOfMonth(),
                'end' => $end,
                'group_by' => 'month',
            ],
            default => [
                'start' => $end->subDays(29)->startOfDay(),
                'end' => $end,
                'group_by' => 'day',
            ],
        };
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    protected function performanceBuckets(CarbonImmutable $start, CarbonImmutable $end, string $groupBy): array
    {
        $buckets = [];

        if ($groupBy === 'month') {
            $cursor = $start->startOfMonth();
            while ($cursor->lessThanOrEqualTo($end)) {
                $key = $cursor->format('Y-m');
                $buckets[$key] = [
                    'start' => $cursor,
                    'label' => $cursor->format('M y'),
                ];
                $cursor = $cursor->addMonth();
            }

            return $buckets;
        }

        if ($groupBy === 'week') {
            $cursor = $start->startOfWeek();
            while ($cursor->lessThanOrEqualTo($end)) {
                $key = $cursor->format('o-W');
                $buckets[$key] = [
                    'start' => $cursor,
                    'label' => $cursor->format('M j'),
                ];
                $cursor = $cursor->addWeek();
            }

            return $buckets;
        }

        $cursor = $start->startOfDay();
        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->toDateString();
            $buckets[$key] = [
                'start' => $cursor,
                'label' => $cursor->format('M j'),
            ];
            $cursor = $cursor->addDay();
        }

        return $buckets;
    }

    protected function performanceBucketKey(CarbonImmutable $timestamp, string $groupBy): string
    {
        return match ($groupBy) {
            'month' => $timestamp->format('Y-m'),
            'week' => $timestamp->startOfWeek()->format('o-W'),
            default => $timestamp->toDateString(),
        };
    }
}
