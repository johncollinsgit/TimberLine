<?php

namespace App\Services\Tenancy;

use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\ShopifyStore;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Schema\SchemaCapabilityMap;
use Illuminate\Http\Request;

class TenantExperienceProfileService
{
    /**
     * @var array<string,array<string,mixed>>
     */
    protected array $cache = [];

    public function __construct(
        protected AuthenticatedTenantContextResolver $tenantContextResolver,
        protected TenantModuleAccessResolver $moduleAccessResolver,
        protected SchemaCapabilityMap $schemaCapabilities,
        protected TenantWorkspaceCapabilityService $workspaceCapabilities
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function forRequest(Request $request, ?User $user = null): array
    {
        $user ??= $request->user();
        $tenant = $request->attributes->get('current_tenant');
        if (! $tenant instanceof Tenant && $user instanceof User) {
            $tenant = $this->tenantContextResolver->resolveForRequest($request, $user);
        }

        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : null;

        return $this->forTenant($tenantId, $user, $tenant instanceof Tenant ? $tenant : null);
    }

    /**
     * @return array<string,mixed>
     */
    public function forTenant(?int $tenantId, ?User $user = null, ?Tenant $tenant = null): array
    {
        $cacheKey = implode(':', [
            (string) ($tenantId ?? 0),
            (string) ($user?->id ?? 0),
        ]);

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        if ($tenantId !== null && ! $tenant instanceof Tenant) {
            $tenant = Tenant::query()
                ->with(['accessProfile', 'shopifyStores'])
                ->find($tenantId);
        }

        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : $tenantId;
        $operatingMode = strtolower(trim((string) ($tenant?->accessProfile?->operating_mode ?? config('module_catalog.defaults.operating_mode', 'shopify'))));
        if ($operatingMode === '') {
            $operatingMode = 'shopify';
        }
        $accountMode = strtolower(trim((string) data_get($tenant?->accessProfile?->metadata, 'account_mode', 'production')));
        if ($accountMode === '') {
            $accountMode = 'production';
        }

        $hasShopifyConnection = $this->hasShopifyConnection($tenantId, $tenant);
        $hasDirectSignals = $this->hasDirectSignals($tenantId, $operatingMode);
        $channelType = $this->deriveChannelType($operatingMode, $hasShopifyConnection, $hasDirectSignals);
        $workspaceContext = $this->workspaceCapabilities->forTenant($tenant);

        $moduleStates = $this->moduleAccessResolver->resolveForTenant($tenantId, [
            'customers',
            'campaigns',
            'rewards',
            'reviews',
            'service_reviews',
            'birthdays',
            'wishlist',
            'bulk_email_marketing',
            'diagnostics_advanced',
            'reporting',
            'field_service',
        ]);
        $resolvedModules = is_array($moduleStates['modules'] ?? null) ? (array) $moduleStates['modules'] : [];

        $hasCustomerSignals = $this->tenantHasRows(MarketingProfile::class, $tenantId);
        $hasOrderSignals = $this->tenantHasRows(Order::class, $tenantId);
        $hasImportSignals = $this->tenantHasRows(MarketingImportRun::class, $tenantId);
        $workspaceProfile = (string) ($workspaceContext['workspace_profile'] ?? 'generic_custom');
        // Keep this legacy presentation field stable for existing dashboard and
        // mobile renderers. Its value now derives only from the authoritative
        // workspace profile, never connection or activity heuristics.
        $useCaseProfile = $this->useCaseProfileForWorkspace($workspaceProfile);

        $powerUserMode = $this->powerUserMode($user);
        $workspace = $this->workspacePresentation($channelType, $workspaceProfile, $powerUserMode);

        return $this->cache[$cacheKey] = [
            'tenant_id' => $tenantId,
            'tenant_name' => $tenant instanceof Tenant ? (string) $tenant->name : null,
            'operating_mode' => $operatingMode,
            'account_mode' => $accountMode,
            'channel_type' => $channelType,
            'use_case_profile' => $useCaseProfile,
            'workspace_profile' => $workspaceProfile,
            'capability_packs' => (array) ($workspaceContext['capability_packs'] ?? []),
            'legacy_overlays' => (array) ($workspaceContext['legacy_overlays'] ?? []),
            'workspace_profile_reviewed' => (bool) ($workspaceContext['is_reviewed'] ?? false),
            'power_user_mode' => $powerUserMode,
            'data_availability' => [
                'customers' => $hasCustomerSignals,
                'orders' => $hasOrderSignals,
                'imports' => $hasImportSignals,
                'shopify' => $hasShopifyConnection,
                'direct' => $hasDirectSignals,
            ],
            'workspace' => $workspace,
            'modules' => [
                'customers_enabled' => $this->moduleEnabled($resolvedModules, 'customers'),
                'campaigns_enabled' => $this->moduleEnabled($resolvedModules, 'campaigns'),
                'rewards_enabled' => $this->moduleEnabled($resolvedModules, 'rewards'),
                'birthdays_enabled' => $this->moduleEnabled($resolvedModules, 'birthdays'),
                'wishlist_enabled' => $this->moduleEnabled($resolvedModules, 'wishlist'),
                'product_reviews_enabled' => $this->moduleEnabled($resolvedModules, 'reviews'),
                'service_reviews_enabled' => $this->moduleEnabled($resolvedModules, 'service_reviews'),
                'field_service_enabled' => $this->moduleEnabled($resolvedModules, 'field_service'),
                'reporting_enabled' => $this->moduleEnabled($resolvedModules, 'reporting')
                    || $this->moduleEnabled($resolvedModules, 'diagnostics_advanced'),
            ],
        ];
    }

    protected function hasShopifyConnection(?int $tenantId, ?Tenant $tenant = null): bool
    {
        if ($tenant instanceof Tenant && $tenant->relationLoaded('shopifyStores')) {
            return $tenant->shopifyStores->isNotEmpty();
        }

        if ($tenantId === null || ! $this->hasTable('shopify_stores')) {
            return false;
        }

        return ShopifyStore::query()
            ->forTenantId($tenantId)
            ->exists();
    }

    protected function hasDirectSignals(?int $tenantId, string $operatingMode): bool
    {
        if ($operatingMode === 'direct') {
            return true;
        }

        if ($tenantId === null) {
            return false;
        }

        if ($this->hasTable('square_customers') && SquareCustomer::query()->forTenantId($tenantId)->exists()) {
            return true;
        }

        if ($this->hasTable('square_orders') && SquareOrder::query()->forTenantId($tenantId)->exists()) {
            return true;
        }

        return false;
    }

    protected function deriveChannelType(string $operatingMode, bool $hasShopifyConnection, bool $hasDirectSignals): string
    {
        if ($hasShopifyConnection && $hasDirectSignals) {
            return 'hybrid';
        }

        if ($hasShopifyConnection || $operatingMode === 'shopify') {
            return 'shopify';
        }

        return 'direct';
    }

    /**
     * @param  array<string,array<string,mixed>>  $resolvedModules
     */
    protected function deriveUseCaseProfile(
        string $channelType,
        array $resolvedModules,
        bool $hasCustomerSignals,
        bool $hasOrderSignals
    ): string {
        if ($channelType === 'hybrid') {
            return 'hybrid';
        }

        $marketingHeavy = $this->moduleEnabled($resolvedModules, 'campaigns')
            || $this->moduleEnabled($resolvedModules, 'rewards')
            || $this->moduleEnabled($resolvedModules, 'reviews')
            || $this->moduleEnabled($resolvedModules, 'wishlist')
            || $this->moduleEnabled($resolvedModules, 'birthdays');

        if ($channelType === 'shopify' && ($marketingHeavy || $hasOrderSignals)) {
            return 'marketing';
        }

        if ($this->moduleEnabled($resolvedModules, 'field_service')) {
            return 'field_service';
        }

        if ($hasCustomerSignals || $this->moduleEnabled($resolvedModules, 'customers') || $this->moduleEnabled($resolvedModules, 'bulk_email_marketing')) {
            return 'crm';
        }

        return 'ops';
    }

    /**
     * @return array<string,string>
     */
    protected function workspacePresentation(string $channelType, string $useCaseProfile, bool $powerUserMode): array
    {
        [$label, $subtitle] = match ($useCaseProfile) {
            'retail_commerce' => ['Retail workspace', 'Products, customers, and retail retention tools in one place.'],
            'maker_production' => ['Production workspace', 'Production, inventory, and approved commerce tools in one place.'],
            'field_service_trades' => ['Field service workspace', 'Customers, jobs, materials, and field work in one place.'],
            'professional_services' => ['Professional workspace', 'Clients, documents, matters, and follow-up in one place.'],
            'appointment_inventory' => ['Appointments workspace', 'Classes, reservations, inventory, and customer follow-up in one place.'],
            default => ['Business workspace', 'Core tools, data, and next actions in one place.'],
        };

        return [
            'label' => $label,
            'subtitle' => $subtitle,
            'command_placeholder' => $powerUserMode
                ? 'Search customers, orders, modules, and actions'
                : 'Search the workspace or jump to a task',
        ];
    }

    protected function useCaseProfileForWorkspace(string $workspaceProfile): string
    {
        return match ($workspaceProfile) {
            'retail_commerce', 'maker_production' => 'marketing',
            'field_service_trades' => 'field_service',
            'professional_services' => 'crm',
            'appointment_inventory' => 'ops',
            default => 'crm',
        };
    }

    protected function powerUserMode(?User $user): bool
    {
        $prefs = is_array($user?->ui_preferences ?? null) ? $user->ui_preferences : [];

        return ! empty($prefs['compact_tables'])
            || ! empty($prefs['wide_layout'])
            || ! empty($prefs['sidebar_order'])
            || (($prefs['theme'] ?? null) === 'get-shit-done');
    }

    protected function tenantHasRows(string $modelClass, ?int $tenantId): bool
    {
        if ($tenantId === null) {
            return false;
        }

        $table = app($modelClass)->getTable();
        if (! $this->hasTable($table)) {
            return false;
        }

        return $modelClass::query()->forTenantId($tenantId)->exists();
    }

    /**
     * @param  array<string,array<string,mixed>>  $resolvedModules
     */
    protected function moduleEnabled(array $resolvedModules, string $moduleKey): bool
    {
        return (bool) data_get($resolvedModules, $moduleKey.'.enabled', false);
    }

    protected function hasTable(string $table): bool
    {
        return $this->schemaCapabilities->hasTable($table);
    }
}
