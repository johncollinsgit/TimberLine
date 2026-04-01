<?php

namespace App\Services\Dashboard;

use App\Models\MarketingIdentityReview;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Services\Tenancy\AuthenticatedTenantContextResolver;
use App\Services\Tenancy\TenantExperienceProfileService;
use App\Services\Tenancy\TenantModuleCatalogService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class UnifiedDashboardService
{
    public function __construct(
        protected AuthenticatedTenantContextResolver $tenantContextResolver,
        protected TenantExperienceProfileService $experienceProfileService,
        protected TenantModuleCatalogService $moduleCatalogService
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function forRequest(Request $request, ?User $user = null): array
    {
        $user ??= $request->user();
        $tenant = $user ? $this->tenantContextResolver->resolveForRequest($request, $user) : null;
        $tenantId = $tenant ? (int) $tenant->id : null;
        $profile = $this->experienceProfileService->forTenant($tenantId, $user, $tenant);
        $canAccessMarketing = $user?->canAccessMarketing() ?? false;
        $canAccessOps = ($user?->isAdmin() ?? false) || ($user?->isManager() ?? false);
        $catalog = ($tenantId !== null && $canAccessMarketing)
            ? $this->moduleCatalogService->tenantStorePayload($tenantId, 'marketing')
            : ['sections' => []];

        return [
            'tenant_id' => $tenantId,
            'experience_profile' => $profile,
            'hero' => $this->heroMetric($tenantId, $profile, $canAccessMarketing, $canAccessOps),
            'summary_cards' => $this->summaryCards($tenantId, $profile, $catalog, $canAccessMarketing),
            'next_actions' => $this->nextActions($tenantId, $profile, $catalog, $canAccessMarketing, $canAccessOps),
            'pinned_modules' => $canAccessMarketing ? $this->pinnedModules($catalog) : [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function heroMetric(?int $tenantId, array $profile, bool $canAccessMarketing, bool $canAccessOps): array
    {
        $channelType = (string) ($profile['channel_type'] ?? 'direct');
        $useCase = (string) ($profile['use_case_profile'] ?? 'ops');

        if ($tenantId !== null && Schema::hasTable('orders') && in_array($channelType, ['shopify', 'hybrid'], true) && ($canAccessMarketing || $canAccessOps)) {
            $since = now()->subDays(30);
            $query = Order::query()->forTenantId($tenantId);
            $revenue = (float) (clone $query)->where('ordered_at', '>=', $since)->sum('total_price');
            $orders = (int) (clone $query)->where('ordered_at', '>=', $since)->count();

            return [
                'label' => 'Order-linked revenue (30D)',
                'value' => '$'.number_format($revenue, 2),
                'supporting' => number_format($orders).' recent orders',
                'tone' => 'emerald',
            ];
        }

        if ($canAccessMarketing && $tenantId !== null && Schema::hasTable('marketing_profiles') && in_array($useCase, ['crm', 'marketing', 'hybrid'], true)) {
            $reachable = (int) MarketingProfile::query()
                ->forTenantId($tenantId)
                ->where(function ($query): void {
                    $query->whereNotNull('email')
                        ->where('email', '!=', '')
                        ->orWhere(function ($nested): void {
                            $nested->whereNotNull('phone')
                                ->where('phone', '!=', '');
                        });
                })
                ->count();

            return [
                'label' => 'Reachable customers',
                'value' => number_format($reachable),
                'supporting' => 'Profiles with at least one usable contact path',
                'tone' => 'sky',
            ];
        }

        if ($canAccessOps && $tenantId !== null && Schema::hasTable('orders')) {
            $openQueue = (int) Order::query()
                ->forTenantId($tenantId)
                ->whereIn('status', ['reviewed', 'submitted_to_pouring', 'pouring', 'brought_down', 'verified'])
                ->count();

            return [
                'label' => 'Open operational queue',
                'value' => number_format($openQueue),
                'supporting' => 'Orders currently moving through the pipeline',
                'tone' => 'amber',
            ];
        }

        return [
            'label' => 'Workspace readiness',
            'value' => 'Ready',
            'supporting' => 'Search, shortcuts, and module discovery are available from this home surface.',
            'tone' => 'emerald',
        ];
    }

    /**
     * @return array<int,array<string,string|int>>
     */
    protected function summaryCards(?int $tenantId, array $profile, array $catalog, bool $canAccessMarketing): array
    {
        $cards = [];

        if ($canAccessMarketing && $tenantId !== null && Schema::hasTable('marketing_profiles')) {
            $cards[] = [
                'label' => 'Customers',
                'value' => number_format((int) MarketingProfile::query()->forTenantId($tenantId)->count()),
                'detail' => 'Unified tenant-scoped profiles',
            ];
        }

        if ($tenantId !== null && Schema::hasTable('orders')) {
            $cards[] = [
                'label' => 'Orders',
                'value' => number_format((int) Order::query()->forTenantId($tenantId)->count()),
                'detail' => 'Tenant-linked order records',
            ];
        }

        if ($tenantId !== null && Schema::hasTable('marketing_import_runs')) {
            $cards[] = [
                'label' => 'Imports',
                'value' => number_format((int) MarketingImportRun::query()->forTenantId($tenantId)->count()),
                'detail' => 'Import runs and sync batches',
            ];
        }

        $cards[] = [
            'label' => 'Modules',
            'value' => number_format(count((array) ($catalog['sections']['active'] ?? []))),
            'detail' => 'Active modules in this workspace',
        ];

        return array_slice($cards, 0, 4);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function nextActions(
        ?int $tenantId,
        array $profile,
        array $catalog,
        bool $canAccessMarketing,
        bool $canAccessOps
    ): array
    {
        $actions = [
            [
                'label' => 'Search everything',
                'description' => 'Find customers, orders, imports, modules, and key workflows quickly.',
                'intent' => 'open-command',
                'tone' => 'neutral',
            ],
        ];

        if ($canAccessMarketing) {
            $actions[] = [
                'label' => 'Send Message to All Opted-In Customers',
                'description' => 'Quick send to all SMS/email subscribers.',
                'href' => route('marketing.send.all-opted-in'),
                'tone' => 'success',
            ];
        }

        if ($canAccessMarketing && $tenantId !== null && Schema::hasTable('marketing_import_runs')) {
            $latestImport = MarketingImportRun::query()
                ->forTenantId($tenantId)
                ->orderByDesc('id')
                ->first();

            if ($latestImport && in_array((string) $latestImport->status, ['pending', 'failed'], true)) {
                $actions[] = [
                    'label' => 'Review imports',
                    'description' => 'Latest import activity needs attention before customer workflows continue.',
                    'href' => route('marketing.providers-integrations'),
                    'tone' => 'warning',
                ];
            }
        }

        if ($canAccessMarketing && $tenantId !== null && Schema::hasTable('marketing_identity_reviews')) {
            $pendingIdentityReviews = MarketingIdentityReview::query()
                ->where('status', 'pending')
                ->whereHas('proposedMarketingProfile', fn ($query) => $query->forTenantId($tenantId))
                ->count();

            if ($pendingIdentityReviews > 0) {
                $actions[] = [
                    'label' => 'Fix identity matches',
                    'description' => number_format($pendingIdentityReviews).' profile match decision'.($pendingIdentityReviews === 1 ? '' : 's').' are waiting.',
                    'href' => route('marketing.identity-review'),
                    'tone' => 'warning',
                ];
            }
        }

        if ($canAccessMarketing && $tenantId !== null && ((array) ($catalog['sections']['available'] ?? [])) !== []) {
            $actions[] = [
                'label' => 'Explore modules',
                'description' => 'See which modules can be activated or requested next for this tenant.',
                'href' => route('marketing.modules'),
                'tone' => 'info',
            ];
        }

        if ($canAccessMarketing && in_array((string) ($profile['use_case_profile'] ?? 'ops'), ['crm', 'marketing', 'hybrid'], true)) {
            $actions[] = [
                'label' => 'Open customers',
                'description' => 'Go straight to customer search, follow-up, and profile detail.',
                'href' => route('marketing.customers'),
                'tone' => 'success',
            ];
        }

        if ($canAccessOps && $tenantId !== null && Schema::hasTable('orders')) {
            $openQueue = (int) Order::query()
                ->forTenantId($tenantId)
                ->whereIn('status', ['reviewed', 'submitted_to_pouring', 'pouring', 'brought_down', 'verified'])
                ->count();

            if ($openQueue > 0) {
                $actions[] = [
                    'label' => 'Review order queue',
                    'description' => number_format($openQueue).' order'.($openQueue === 1 ? '' : 's').' are active in production workflows.',
                    'href' => route('shipping.orders'),
                    'tone' => 'warning',
                ];
            }
        }

        $powerUserMode = (bool) ($profile['power_user_mode'] ?? false);

        return array_slice($actions, 0, $powerUserMode ? 5 : 4);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function pinnedModules(array $catalog): array
    {
        $active = is_array($catalog['sections']['active'] ?? null) ? $catalog['sections']['active'] : [];
        $available = is_array($catalog['sections']['available'] ?? null) ? $catalog['sections']['available'] : [];

        $rows = array_merge(array_slice($active, 0, 2), array_slice($available, 0, 2));

        return array_map(function (array $module): array {
            return [
                'module_key' => (string) ($module['module_key'] ?? ''),
                'display_name' => (string) ($module['display_name'] ?? 'Module'),
                'description' => (string) ($module['description'] ?? ''),
                'state_label' => (string) data_get($module, 'module_state.state_label', 'Available'),
                'href' => route('marketing.modules', ['module' => (string) ($module['module_key'] ?? '')]),
            ];
        }, $rows);
    }
}
