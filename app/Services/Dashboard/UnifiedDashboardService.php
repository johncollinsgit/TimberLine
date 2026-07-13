<?php

namespace App\Services\Dashboard;

use App\Models\FieldServiceJob;
use App\Models\FieldServiceMaterial;
use App\Models\FieldServiceVehicle;
use App\Models\MarketingIdentityReview;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\AuthenticatedTenantContextResolver;
use App\Services\Tenancy\TenantBlueprintProfileService;
use App\Services\Tenancy\TenantExperienceProfileService;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class UnifiedDashboardService
{
    public function __construct(
        protected AuthenticatedTenantContextResolver $tenantContextResolver,
        protected TenantExperienceProfileService $experienceProfileService,
        protected TenantModuleCatalogService $moduleCatalogService,
        protected TenantBlueprintProfileService $blueprintProfileService,
        protected DashboardDateRange $dateRanges,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function forRequest(Request $request, ?User $user = null, ?string $rangeKey = null): array
    {
        $user ??= $request->user();
        $attributeTenant = $request->attributes->get('current_tenant');
        $tenant = $attributeTenant instanceof Tenant
            ? $attributeTenant
            : ($user ? $this->tenantContextResolver->resolveForRequest($request, $user) : null);
        $tenantId = $tenant ? (int) $tenant->id : null;
        $profile = $this->experienceProfileService->forTenant($tenantId, $user, $tenant);
        $canAccessMarketing = $user?->canAccessMarketing() ?? false;
        $canAccessOps = ($user?->isAdmin() ?? false) || ($user?->isManager() ?? false);
        $catalog = ($tenantId !== null && $canAccessMarketing)
            ? $this->moduleCatalogService->tenantStorePayload($tenantId, 'marketing')
            : ['sections' => []];
        $range = $this->dateRanges->resolve($rangeKey ?? $request->query('range'));
        $tradeMetrics = $this->tradeMetrics($tenant, $profile, $range);

        return [
            'tenant_id' => $tenantId,
            'date_range' => [
                'key' => $range['key'],
                'label' => $range['label'],
                'short_label' => $range['short_label'],
                'starts_at' => $range['starts_at']->toIso8601String(),
                'ends_at' => $range['ends_at']->toIso8601String(),
                'options' => $range['options'],
            ],
            'experience_profile' => $profile,
            'hero' => $this->heroMetric($tenantId, $profile, $canAccessMarketing, $canAccessOps, $range, $tradeMetrics),
            'summary_cards' => $this->summaryCards($tenantId, $profile, $catalog, $canAccessMarketing, $canAccessOps, $range, $tradeMetrics),
            'next_actions' => $this->nextActions($tenantId, $profile, $catalog, $canAccessMarketing, $canAccessOps),
            'pinned_modules' => $canAccessMarketing ? $this->pinnedModules($catalog) : [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function heroMetric(?int $tenantId, array $profile, bool $canAccessMarketing, bool $canAccessOps, array $range, ?array $tradeMetrics = null): array
    {
        $channelType = (string) ($profile['channel_type'] ?? 'direct');
        $useCase = (string) ($profile['use_case_profile'] ?? 'ops');

        if ($canAccessOps && $tradeMetrics !== null) {
            return [
                'label' => 'Jobs in progress',
                'value' => number_format((int) $tradeMetrics['jobs_in_progress']),
                'supporting' => 'Active jobs currently underway',
                'tone' => 'emerald',
            ];
        }

        if ($tenantId !== null && Schema::hasTable('orders') && in_array($channelType, ['shopify', 'hybrid'], true) && ($canAccessMarketing || $canAccessOps)) {
            $query = Order::query()->forTenantId($tenantId);
            $revenue = (float) (clone $query)->whereBetween('ordered_at', [$range['starts_at'], $range['ends_at']])->sum('total_price');
            $orders = (int) (clone $query)->whereBetween('ordered_at', [$range['starts_at'], $range['ends_at']])->count();

            return [
                'label' => 'Order-linked revenue · '.$range['short_label'],
                'value' => '$'.number_format($revenue, 2),
                'supporting' => number_format($orders).' recent orders',
                'tone' => 'emerald',
            ];
        }

        if ($canAccessOps && $tenantId !== null && $useCase === 'field_service' && Schema::hasTable('field_service_jobs')) {
            $openJobs = (int) FieldServiceJob::query()
                ->forTenantId($tenantId)
                ->whereNotIn('status', ['done'])
                ->count();

            return [
                'label' => 'Open jobs',
                'value' => number_format($openJobs),
                'supporting' => 'Customer jobs waiting for work, materials, or follow-up',
                'tone' => 'amber',
            ];
        }

        if ($canAccessMarketing && $tenantId !== null && Schema::hasTable('marketing_profiles') && in_array($useCase, ['crm', 'marketing', 'hybrid'], true)) {
            $reachable = (int) MarketingProfile::query()
                ->forTenantId($tenantId)
                ->whereBetween('created_at', [$range['starts_at'], $range['ends_at']])
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
    protected function summaryCards(?int $tenantId, array $profile, array $catalog, bool $canAccessMarketing, bool $canAccessOps, array $range, ?array $tradeMetrics = null): array
    {
        $cards = [];
        $useCase = (string) ($profile['use_case_profile'] ?? 'ops');

        if ($canAccessOps && $tradeMetrics !== null) {
            return [
                [
                    'label' => 'Total gross revenue',
                    'value' => '$'.number_format((float) $tradeMetrics['gross_revenue'], 2),
                    'detail' => 'Value of jobs in progress for '.$range['short_label'],
                ],
                [
                    'label' => 'Crews working',
                    'value' => number_format((int) $tradeMetrics['crews_working']),
                    'detail' => 'Assigned crews on active jobs',
                ],
                [
                    'label' => 'Potential jobs in progress',
                    'value' => number_format((int) $tradeMetrics['potential_jobs']),
                    'detail' => 'Estimates, quotes, and opportunities in progress',
                ],
            ];
        }

        if ($tenantId !== null && $useCase === 'field_service') {
            if (Schema::hasTable('marketing_profiles')) {
                $cards[] = [
                    'label' => 'Customers',
                    'value' => number_format((int) MarketingProfile::query()->forTenantId($tenantId)->whereBetween('created_at', [$range['starts_at'], $range['ends_at']])->count()),
                    'detail' => 'People and businesses you work for',
                ];
            }

            if (Schema::hasTable('field_service_jobs')) {
                $cards[] = [
                    'label' => 'Jobs',
                    'value' => number_format((int) FieldServiceJob::query()->forTenantId($tenantId)->whereBetween('created_at', [$range['starts_at'], $range['ends_at']])->count()),
                    'detail' => 'Service work in this workspace',
                ];
            }

            if (Schema::hasTable('field_service_materials')) {
                $cards[] = [
                    'label' => 'Materials',
                    'value' => number_format((int) FieldServiceMaterial::query()->forTenantId($tenantId)->whereBetween('created_at', [$range['starts_at'], $range['ends_at']])->count()),
                    'detail' => 'Parts and materials to track',
                ];
            }

            if (Schema::hasTable('field_service_vehicles')) {
                $cards[] = [
                    'label' => 'Work vans',
                    'value' => number_format((int) FieldServiceVehicle::query()->forTenantId($tenantId)->whereBetween('created_at', [$range['starts_at'], $range['ends_at']])->count()),
                    'detail' => 'Vehicles in the field',
                ];
            }

            return array_slice($cards, 0, 4);
        }

        if ($canAccessMarketing && $tenantId !== null && Schema::hasTable('marketing_profiles')) {
            $cards[] = [
                'label' => 'Customers',
                'value' => number_format((int) MarketingProfile::query()->forTenantId($tenantId)->whereBetween('created_at', [$range['starts_at'], $range['ends_at']])->count()),
                'detail' => 'Unified tenant-scoped profiles',
            ];
        }

        if ($tenantId !== null && Schema::hasTable('orders')) {
            $cards[] = [
                'label' => 'Orders',
                'value' => number_format((int) Order::query()->forTenantId($tenantId)->whereBetween('ordered_at', [$range['starts_at'], $range['ends_at']])->count()),
                'detail' => 'Tenant-linked order records',
            ];
        }

        if ($tenantId !== null && Schema::hasTable('marketing_import_runs')) {
            $cards[] = [
                'label' => 'Imports',
                'value' => number_format((int) MarketingImportRun::query()->forTenantId($tenantId)->whereBetween('created_at', [$range['starts_at'], $range['ends_at']])->count()),
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
     * @return array{jobs_in_progress:int,gross_revenue:float,crews_working:int,potential_jobs:int}|null
     */
    protected function tradeMetrics(?Tenant $tenant, array $profile, array $range): ?array
    {
        if (! $tenant instanceof Tenant || ! Schema::hasTable('field_service_jobs')) {
            return null;
        }

        $blueprint = $this->blueprintProfileService->payloadForTenant($tenant->loadMissing('accessProfile'));
        $template = strtolower(trim((string) ($blueprint['business_template'] ?? '')));
        if (! in_array($template, ['electrician', 'landscaping'], true)
            && (string) ($profile['use_case_profile'] ?? '') !== 'field_service') {
            return null;
        }

        $jobs = FieldServiceJob::query()
            ->forTenantId((int) $tenant->id)
            ->where(function ($query) use ($range): void {
                $query->whereBetween('scheduled_for', [$range['starts_at'], $range['ends_at']])
                    ->orWhere(function ($fallback) use ($range): void {
                        $fallback->whereNull('scheduled_for')
                            ->whereBetween('created_at', [$range['starts_at'], $range['ends_at']]);
                    });
            })
            ->get(['id', 'assigned_user_id', 'status', 'metadata']);
        $inProgress = $jobs->filter(fn (FieldServiceJob $job): bool => strtolower((string) $job->status) === 'in_progress');
        $pipelineStages = ['potential', 'estimate', 'estimated', 'quote', 'quoted', 'proposal', 'opportunity'];

        return [
            'jobs_in_progress' => $inProgress->count(),
            'gross_revenue' => $inProgress->sum(fn (FieldServiceJob $job): float => $this->jobGrossRevenue($job)),
            'crews_working' => $inProgress
                ->map(fn (FieldServiceJob $job): string => trim((string) (
                    data_get($job->metadata, 'crew_id')
                    ?? data_get($job->metadata, 'crew_key')
                    ?? data_get($job->metadata, 'crew_name')
                    ?? ($job->assigned_user_id ? 'user:'.$job->assigned_user_id : '')
                )))
                ->filter()
                ->unique()
                ->count(),
            'potential_jobs' => $jobs->filter(function (FieldServiceJob $job) use ($pipelineStages): bool {
                $status = strtolower(trim((string) $job->status));
                $stage = strtolower(trim((string) (
                    data_get($job->metadata, 'pipeline_stage')
                    ?? data_get($job->metadata, 'job_stage')
                    ?? data_get($job->metadata, 'stage')
                    ?? ''
                )));

                return in_array($status, $pipelineStages, true) || in_array($stage, $pipelineStages, true);
            })->count(),
        ];
    }

    protected function jobGrossRevenue(FieldServiceJob $job): float
    {
        $cents = data_get($job->metadata, 'gross_revenue_cents');
        if (is_numeric($cents)) {
            return (float) $cents / 100;
        }

        foreach (['gross_revenue', 'contract_value', 'estimated_revenue', 'quoted_total'] as $key) {
            $value = data_get($job->metadata, $key);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return 0.0;
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
    ): array {
        $actions = [
            [
                'label' => 'Search everything',
                'description' => 'Find customers, orders, imports, modules, and key workflows quickly.',
                'intent' => 'open-command',
                'tone' => 'neutral',
            ],
        ];

        if ($canAccessOps && (string) ($profile['use_case_profile'] ?? 'ops') === 'field_service' && route('field-service.index', [], false)) {
            $actions[] = [
                'label' => 'Add a customer',
                'description' => 'Create the customer and first job together.',
                'href' => route('field-service.index'),
                'tone' => 'success',
            ];
            $actions[] = [
                'label' => 'Create a job',
                'description' => 'Capture address, notes, materials, and assigned person.',
                'href' => route('field-service.index'),
                'tone' => 'info',
            ];
            $actions[] = [
                'label' => 'Add materials',
                'description' => 'Track parts, supplies, and job costs.',
                'href' => route('field-service.index').'#materials',
                'tone' => 'neutral',
            ];
            $actions[] = [
                'label' => 'Invite your team',
                'description' => 'Add the people who need access.',
                'href' => route('admin.index', ['tab' => 'users']),
                'tone' => 'neutral',
            ];

            return array_slice($actions, 0, 5);
        }

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
