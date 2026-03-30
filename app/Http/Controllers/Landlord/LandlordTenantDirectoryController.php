<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Services\Tenancy\LandlordTenantOperationsService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class LandlordTenantDirectoryController extends Controller
{
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
        ]);
    }

    public function show(
        Tenant $tenant,
        LandlordTenantOperationsService $operationsService,
        LandlordOperatorActionAuditService $auditService
    ): View
    {
        $hydratedTenant = $this->tenantDetailQuery()->findOrFail($tenant->getKey());
        $summary = $this->presentTenant($hydratedTenant);

        /** @var Collection<int,ShopifyStore> $storeRows */
        $storeRows = $hydratedTenant->relationLoaded('shopifyStores')
            ? $hydratedTenant->shopifyStores
            : collect();
        $recentOperatorActions = $auditService->recentForTenant((int) $hydratedTenant->id, 25);

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

        $status = $this->derivedStatus(
            hasAccessProfile: $accessProfile !== null,
            operatingMode: $operatingMode,
            userCount: $userCount,
            connectedShopifyStoreCount: $connectedShopifyStoreCount,
            openHealthEventCount: $openHealthEventCount,
        );

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
}
