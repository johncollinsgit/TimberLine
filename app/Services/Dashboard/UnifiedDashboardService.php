<?php

namespace App\Services\Dashboard;

use App\Models\Agreement;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceMaterial;
use App\Models\FieldServiceVehicle;
use App\Models\MarketingIdentityReview;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\ScheduledClass;
use App\Models\Tenant;
use App\Models\TenantBillingOrder;
use App\Models\User;
use App\Services\FieldService\QuickBooksOwnerReportingService;
use App\Services\Tenancy\AuthenticatedTenantContextResolver;
use App\Services\Tenancy\TenantBlueprintProfileService;
use App\Services\Tenancy\TenantExperienceProfileService;
use App\Services\Tenancy\TenantFinancialAccess;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class UnifiedDashboardService
{
    public function __construct(
        protected AuthenticatedTenantContextResolver $tenantContextResolver,
        protected TenantExperienceProfileService $experienceProfileService,
        protected TenantModuleCatalogService $moduleCatalogService,
        protected TenantBlueprintProfileService $blueprintProfileService,
        protected DashboardDateRange $dateRanges,
        protected TenantFinancialAccess $financialAccess,
        protected TenantModuleAccessResolver $moduleAccess,
        protected QuickBooksOwnerReportingService $ownerReports,
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
        $clientFacingFieldService = $this->clientFacingFieldServiceEnabled($tenant);
        $tradeMetrics = $clientFacingFieldService ? $this->tradeMetrics($tenant, $profile, $range) : null;
        $ownerReport = $this->ownerReport($tenant, $user, $range['key']);
        $summaryCards = $ownerReport
            ? $this->financialSummaryCards($ownerReport)
            : $this->summaryCards($tenantId, $profile, $catalog, $canAccessMarketing, $canAccessOps, $range, $tradeMetrics, $clientFacingFieldService);

        $hero = $this->heroMetric($tenantId, $profile, $canAccessMarketing, $canAccessOps, $range, $tradeMetrics, $clientFacingFieldService);
        $hero['href'] = $this->destinationHref((array) ($hero['destination'] ?? []), $tenant, $range['key']);
        $summaryCards = array_map(function (array $card) use ($tenant, $range): array {
            $card['href'] = $this->destinationHref((array) ($card['destination'] ?? []), $tenant, $range['key']);

            return $card;
        }, $summaryCards);

        return [
            'tenant_id' => $tenantId,
            'tenant_slug' => $tenant?->slug,
            'date_range' => [
                'key' => $range['key'],
                'label' => $range['label'],
                'short_label' => $range['short_label'],
                'starts_at' => $range['starts_at']->toIso8601String(),
                'ends_at' => $range['ends_at']->toIso8601String(),
                'options' => $range['options'],
            ],
            'experience_profile' => $profile,
            'hero' => $hero,
            'summary_cards' => $summaryCards,
            'upcoming_jobs' => $clientFacingFieldService ? ($ownerReport['upcoming_jobs'] ?? $this->upcomingJobs($tenant)) : [],
            'class_calendar' => $this->classCalendar($tenant),
            'front_yard_launch' => $this->frontYardLaunch($tenant),
            'owner_reporting' => $ownerReport,
            'next_actions' => $this->nextActions($tenantId, $profile, $catalog, $canAccessMarketing, $canAccessOps, $clientFacingFieldService),
            'pinned_modules' => $canAccessMarketing ? $this->pinnedModules($catalog) : [],
        ];
    }

    /** @return array<string,mixed>|null */
    protected function frontYardLaunch(?Tenant $tenant): ?array
    {
        if (! $tenant || (string) $tenant->slug !== 'front-yard-foods') {
            return null;
        }

        $agreement = Schema::hasTable('agreements')
            ? Agreement::query()
                ->forTenantId((int) $tenant->id)
                ->where('template_key', 'front_yard_foods_launch_partner')
                ->with(['acceptance', 'billingOrders' => fn ($query) => $query->latest()])
                ->latest()
                ->first()
            : null;

        /** @var TenantBillingOrder|null $billingOrder */
        $billingOrder = $agreement?->billingOrders?->first();
        $paymentStatus = $billingOrder?->status ?? 'waiting_for_signature';
        $agreementStatus = $agreement?->acceptance ? 'signed' : ($agreement ? 'ready_to_sign' : 'drafting');

        return [
            'headline' => 'Welcome, Laura',
            'subheadline' => 'Front Yard Foods is being prepared as a calm, central workspace for the Shopify migration, Square mapping, classes, consultations, customers, messaging, sales context, and plant inventory.',
            'brand' => [
                'name' => 'Front Yard Foods',
                'primary' => '#42654a',
                'cream' => '#fbf6e6',
                'accent' => '#e6b84d',
            ],
            'explain' => 'Once Shopify and Square are connected, this dashboard will start tying together customers, messaging readiness, schedulable events, product sales context, and inventory on one page. Publishing and sync remain pending until each provider connection is approved and tested.',
            'statuses' => [
                ['label' => 'Agreement', 'value' => str_replace('_', ' ', $agreementStatus), 'tone' => $agreementStatus === 'signed' ? 'green' : 'amber'],
                ['label' => 'Payment', 'value' => str_replace('_', ' ', $paymentStatus), 'tone' => $paymentStatus === 'paid' ? 'green' : 'amber'],
                ['label' => 'Shopify/Square sync', 'value' => 'pending connection', 'tone' => 'amber'],
            ],
            'evergrove_doing' => [
                'Prepare Shopify migration plan.',
                'Match Shopify design to the current Squarespace site.',
                'Set up product and inventory structure.',
                'Prepare Square → Shopify inventory mapping.',
                'Configure classes/events and pickup/delivery workflows.',
                'Review launch readiness before domain cutover.',
            ],
            'client_needs' => [
                'Squarespace login or collaborator invite.',
                'Shopify login or collaborator invite.',
                'Square login or collaborator invite.',
                'Inventory and product files.',
                'Customer files currently used for the company.',
                'Website photos, copy, policies, delivery/pickup details, and class/consultation info.',
            ],
            'data_assurance' => [
                'Your data is used only to perform the approved migration, setup, support, reporting, security, and client-authorized integrations.',
                'Your data is not sold.',
                'Your data is not shared with unrelated third parties.',
                'Shopify, Square, Substack, booking, and website access is used only for the approved implementation.',
            ],
            'agreement_href' => route('agreements.index', ['tenant' => $tenant->slug]),
            'events_href' => Route::has('class-scheduling.index') ? route('class-scheduling.index') : null,
            'inventory_href' => Route::has('plant-inventory.index') ? route('plant-inventory.index') : null,
        ];
    }

    /** @return array<string,mixed>|null */
    protected function ownerReport(?Tenant $tenant, ?User $user, string $rangeKey): ?array
    {
        if (! $tenant || ! $user
            || ! Schema::hasTable('quickbooks_reporting_settings')
            || ! $this->financialAccess->allows($user, $tenant)
            || ! $this->moduleAccess->canAccess((int) $tenant->id, 'quickbooks')) {
            return null;
        }

        return $this->ownerReports->report($tenant, $rangeKey, false);
    }

    /** @param array<string,mixed> $report @return array<int,array<string,string>> */
    protected function financialSummaryCards(array $report): array
    {
        $unpaid = (array) data_get($report, 'cards.unpaid_invoices', []);
        $work = (array) data_get($report, 'cards.work_billed', []);
        $contract = (array) data_get($report, 'cards.contract_labor', []);
        $sync = (array) ($report['sync_health'] ?? []);

        return [
            ['label' => 'Unpaid invoices', 'value' => '$'.number_format((float) ($unpaid['amount'] ?? 0), 2), 'detail' => number_format((int) ($unpaid['count'] ?? 0)).' open · $'.number_format((float) ($unpaid['overdue_amount'] ?? 0), 2).' overdue', 'destination' => ['kind' => 'reporting', 'section' => 'unpaid_invoices']],
            ['label' => 'Work billed', 'value' => '$'.number_format((float) ($work['amount'] ?? 0), 2), 'detail' => number_format((int) ($work['count'] ?? 0)).' invoices', 'destination' => ['kind' => 'reporting', 'section' => 'work_billed']],
            ['label' => 'Contract labor', 'value' => ($contract['amount'] ?? null) === null ? 'Mapping needed' : '$'.number_format((float) $contract['amount'], 2), 'detail' => ($contract['percent'] ?? null) === null ? 'Review account mapping' : number_format((float) $contract['percent'], 1).'% of income', 'destination' => ['kind' => 'reporting', 'section' => 'contract_labor']],
            ['label' => 'QuickBooks review', 'value' => number_format((int) ($sync['review_count'] ?? 0)), 'detail' => ($sync['connected'] ?? false) ? 'Connected' : 'Not connected', 'destination' => ['kind' => 'reporting', 'section' => 'quickbooks']],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    protected function upcomingJobs(?Tenant $tenant): array
    {
        if (! $this->clientFacingFieldServiceEnabled($tenant) || ! Schema::hasTable('field_service_jobs')) {
            return [];
        }

        return FieldServiceJob::query()->forTenantId((int) $tenant->id)
            ->whereNotNull('scheduled_for')->where('scheduled_for', '>=', now())->where('status', '!=', 'done')
            ->with('assignedUser:id,name')->orderBy('scheduled_for')->limit(5)->get()
            ->map(fn (FieldServiceJob $job): array => [
                'id' => (int) $job->id,
                'title' => (string) $job->title,
                'scheduled_for' => $job->scheduled_for?->toIso8601String(),
                'address' => trim(implode(', ', array_filter([$job->service_address_line_1, $job->service_city, $job->service_state]))),
                'assigned_to' => $job->assignedUser?->name,
                'href' => route('field-service.jobs.show', ['job' => $job->id]),
            ])->all();
    }

    /** @return array<string,mixed>|null */
    protected function classCalendar(?Tenant $tenant): ?array
    {
        if (! $tenant || ! Schema::hasTable('scheduled_classes')
            || ! $this->moduleAccess->canAccess((int) $tenant->id, 'class_scheduling')) {
            return null;
        }

        $month = now()->startOfMonth();
        $classes = ScheduledClass::query()
            ->forTenantId((int) $tenant->id)
            ->whereBetween('starts_at', [$month->copy()->startOfWeek(), $month->copy()->endOfMonth()->endOfWeek()])
            ->whereNotIn('status', ['cancelled'])
            ->withSum(['confirmedEnrollments as confirmed_enrollments_sum_seats'], 'seats')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (ScheduledClass $class): array => [
                'id' => (int) $class->id,
                'title' => (string) $class->title,
                'category' => (string) ($class->category ?: 'Class'),
                'starts_at' => $class->starts_at->toIso8601String(),
                'seats_taken' => $class->seats_taken,
                'capacity' => (int) $class->capacity,
                'href' => route('class-scheduling.show', $class),
                'destination' => ['kind' => 'scheduled_class', 'id' => (int) $class->id],
            ])->all();

        return [
            'month' => $month->format('Y-m'),
            'label' => $month->format('F Y'),
            'classes' => $classes,
            'href' => route('class-scheduling.index', ['month' => $month->format('Y-m')]),
            'destination' => ['kind' => 'class_scheduling'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function heroMetric(?int $tenantId, array $profile, bool $canAccessMarketing, bool $canAccessOps, array $range, ?array $tradeMetrics = null, bool $clientFacingFieldService = true): array
    {
        $channelType = (string) ($profile['channel_type'] ?? 'direct');
        $useCase = (string) ($profile['use_case_profile'] ?? 'ops');

        if ($canAccessOps && $tradeMetrics !== null) {
            return [
                'label' => 'Active jobs',
                'value' => number_format((int) $tradeMetrics['jobs_in_progress']),
                'supporting' => 'Accepted work that still needs attention',
                'tone' => 'emerald',
                'destination' => ['kind' => 'field_service', 'view' => 'list', 'filter' => 'active'],
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
                'destination' => ['kind' => 'orders'],
            ];
        }

        if ($clientFacingFieldService && $canAccessOps && $tenantId !== null && $useCase === 'field_service' && Schema::hasTable('field_service_jobs')) {
            $openJobs = (int) FieldServiceJob::query()
                ->forTenantId($tenantId)
                ->whereNotIn('status', ['done'])
                ->count();

            return [
                'label' => 'Open jobs',
                'value' => number_format($openJobs),
                'supporting' => 'Customer jobs waiting for work, materials, or follow-up',
                'tone' => 'amber',
                'destination' => ['kind' => 'field_service', 'view' => 'list', 'filter' => 'active'],
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
                'destination' => ['kind' => 'customers'],
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
                'destination' => ['kind' => 'orders'],
            ];
        }

        return [
            'label' => 'Workspace readiness',
            'value' => 'Ready',
            'supporting' => 'Search, shortcuts, and module discovery are available from this home surface.',
            'tone' => 'emerald',
            'destination' => ['kind' => 'modules'],
        ];
    }

    /**
     * @return array<int,array<string,string|int>>
     */
    protected function summaryCards(?int $tenantId, array $profile, array $catalog, bool $canAccessMarketing, bool $canAccessOps, array $range, ?array $tradeMetrics = null, bool $clientFacingFieldService = true): array
    {
        $cards = [];
        $useCase = (string) ($profile['use_case_profile'] ?? 'ops');

        if ($canAccessOps && $tradeMetrics !== null) {
            return [
                [
                    'label' => 'Total gross revenue',
                    'value' => '$'.number_format((float) $tradeMetrics['gross_revenue'], 2),
                    'detail' => 'Active job value',
                    'destination' => ['kind' => 'field_service', 'view' => 'list', 'filter' => 'active'],
                ],
                [
                    'label' => 'Crews working',
                    'value' => number_format((int) $tradeMetrics['crews_working']),
                    'detail' => 'Assigned active jobs',
                    'destination' => ['kind' => 'field_service', 'view' => 'calendar', 'filter' => 'active'],
                ],
                [
                    'label' => 'Potential jobs in progress',
                    'value' => number_format((int) $tradeMetrics['potential_jobs']),
                    'detail' => 'Recent unaccepted quotes',
                    'destination' => ['kind' => 'field_service', 'view' => 'list', 'filter' => 'quotes'],
                ],
            ];
        }

        if ($clientFacingFieldService && $tenantId !== null && $useCase === 'field_service') {
            if (Schema::hasTable('marketing_profiles')) {
                $cards[] = [
                    'label' => 'Customers',
                    'value' => number_format((int) MarketingProfile::query()->forTenantId($tenantId)->whereBetween('created_at', [$range['starts_at'], $range['ends_at']])->count()),
                    'detail' => 'People and businesses you work for',
                    'destination' => ['kind' => 'customers'],
                ];
            }

            if (Schema::hasTable('field_service_jobs')) {
                $cards[] = [
                    'label' => 'Jobs',
                    'value' => number_format((int) FieldServiceJob::query()->forTenantId($tenantId)->whereBetween('created_at', [$range['starts_at'], $range['ends_at']])->count()),
                    'detail' => 'Service work in this workspace',
                    'destination' => ['kind' => 'field_service', 'view' => 'list', 'filter' => 'active'],
                ];
            }

            if (Schema::hasTable('field_service_materials')) {
                $cards[] = [
                    'label' => 'Materials',
                    'value' => number_format((int) FieldServiceMaterial::query()->forTenantId($tenantId)->whereBetween('created_at', [$range['starts_at'], $range['ends_at']])->count()),
                    'detail' => 'Parts and materials to track',
                    'destination' => ['kind' => 'field_service', 'section' => 'materials'],
                ];
            }

            if (Schema::hasTable('field_service_vehicles')) {
                $cards[] = [
                    'label' => 'Work vans',
                    'value' => number_format((int) FieldServiceVehicle::query()->forTenantId($tenantId)->whereBetween('created_at', [$range['starts_at'], $range['ends_at']])->count()),
                    'detail' => 'Vehicles in the field',
                    'destination' => ['kind' => 'field_service', 'section' => 'vehicles'],
                ];
            }

            return array_slice($cards, 0, 4);
        }

        if ($canAccessMarketing && $tenantId !== null && Schema::hasTable('marketing_profiles')) {
            $cards[] = [
                'label' => 'Customers',
                'value' => number_format((int) MarketingProfile::query()->forTenantId($tenantId)->whereBetween('created_at', [$range['starts_at'], $range['ends_at']])->count()),
                'detail' => 'Unified tenant-scoped profiles',
                'destination' => ['kind' => 'customers'],
            ];
        }

        if ($tenantId !== null && Schema::hasTable('orders')) {
            $cards[] = [
                'label' => 'Orders',
                'value' => number_format((int) Order::query()->forTenantId($tenantId)->whereBetween('ordered_at', [$range['starts_at'], $range['ends_at']])->count()),
                'detail' => 'Tenant-linked order records',
                'destination' => ['kind' => 'orders'],
            ];
        }

        if ($tenantId !== null && Schema::hasTable('marketing_import_runs')) {
            $cards[] = [
                'label' => 'Imports',
                'value' => number_format((int) MarketingImportRun::query()->forTenantId($tenantId)->whereBetween('created_at', [$range['starts_at'], $range['ends_at']])->count()),
                'detail' => 'Import runs and sync batches',
                'destination' => ['kind' => 'imports'],
            ];
        }

        $cards[] = [
            'label' => 'Modules',
            'value' => number_format(count((array) ($catalog['sections']['active'] ?? []))),
            'detail' => 'Active modules in this workspace',
            'destination' => ['kind' => 'modules'],
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
            ->where(function ($query): void {
                $query->whereIn('operational_status', ['active', 'needs_details', 'blocked'])
                    ->orWhere(function ($legacy): void {
                        $legacy->whereNull('operational_status')
                            ->whereIn('status', ['open', 'scheduled', 'in_progress', 'blocked']);
                    });
            })
            ->get(['id', 'assigned_user_id', 'status', 'operational_status', 'metadata']);
        $inProgress = $jobs;
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
            'potential_jobs' => FieldServiceJob::query()->forTenantId((int) $tenant->id)
                ->where(function ($query) use ($pipelineStages): void {
                    $query->where('operational_status', 'quote')
                        ->orWhere(function ($legacy) use ($pipelineStages): void {
                            $legacy->whereNull('operational_status')->whereIn('status', $pipelineStages);
                        });
                })
                ->where(function ($query) use ($range): void {
                    $query->whereBetween('last_financial_activity_at', [$range['starts_at'], $range['ends_at']])
                        ->orWhere(function ($fallback) use ($range): void {
                            $fallback->whereNull('last_financial_activity_at')
                                ->whereBetween('created_at', [$range['starts_at'], $range['ends_at']]);
                        });
                })
                ->get()->filter(function (FieldServiceJob $job) use ($pipelineStages): bool {
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
        bool $canAccessOps,
        bool $clientFacingFieldService = true
    ): array {
        $actions = [];

        if ($clientFacingFieldService && $canAccessOps && (string) ($profile['use_case_profile'] ?? 'ops') === 'field_service' && route('field-service.index', [], false)) {
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

    protected function clientFacingFieldServiceEnabled(?Tenant $tenant): bool
    {
        if (! $tenant instanceof Tenant) {
            return false;
        }

        return $this->moduleAccess->canAccess((int) $tenant->id, 'field_service');
    }

    /** @param array<string,mixed> $destination */
    protected function destinationHref(array $destination, ?Tenant $tenant, string $rangeKey): ?string
    {
        $kind = (string) ($destination['kind'] ?? '');

        return match ($kind) {
            'field_service', 'field_service_job' => route('field-service.index').($destination['section'] ?? false ? '#'.(string) $destination['section'] : ''),
            'customers', 'customer' => route('marketing.customers'),
            'orders' => route('shipping.orders'),
            'imports' => route('marketing.providers-integrations'),
            'modules' => route('marketing.modules'),
            'reporting' => $tenant ? route('quickbooks.reports.index', ['tenant' => $tenant->slug, 'range' => $rangeKey]) : null,
            'class_scheduling', 'scheduled_class' => isset($destination['id'])
                ? route('class-scheduling.show', ['scheduledClass' => (int) $destination['id']])
                : route('class-scheduling.index'),
            default => null,
        };
    }
}
