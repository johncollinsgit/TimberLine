<?php

namespace App\Services\Dashboard;

use App\Models\Agreement;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowRun;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceMaterial;
use App\Models\FieldServiceVehicle;
use App\Models\MarketingIdentityReview;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use App\Models\ScheduledClass;
use App\Models\Tenant;
use App\Models\TenantBillingOrder;
use App\Models\User;
use App\Models\WebsiteOrder;
use App\Services\FieldService\QuickBooksOwnerReportingService;
use App\Services\Reporting\SalesChannelSummaryService;
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
        protected SalesChannelSummaryService $salesChannels,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function forRequest(Request $request, ?User $user = null, ?string $rangeKey = null, ?string $metricKey = null): array
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
        $channelPulse = $this->channelPulse($tenantId, $canAccessMarketing || $canAccessOps, $range, $metricKey);

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
            'channel_pulse' => $channelPulse,
            'upcoming_jobs' => $clientFacingFieldService ? ($ownerReport['upcoming_jobs'] ?? $this->upcomingJobs($tenant)) : [],
            'class_calendar' => $this->classCalendar($tenant),
            'front_yard_launch' => $this->frontYardLaunch($tenant),
            'workflow_automation_health' => $this->workflowAutomationHealth($tenantId, $canAccessOps || $canAccessMarketing),
            'owner_reporting' => $ownerReport,
            'next_actions' => $this->nextActions($tenantId, $profile, $catalog, $canAccessMarketing, $canAccessOps, $clientFacingFieldService),
            'pinned_modules' => $canAccessMarketing ? $this->pinnedModules($catalog) : [],
        ];
    }

    /**
     * A compact, tenant-scoped version of the sales-channel pulse used at the
     * top of Home. Storefront sessions come only from recorded events; when a
     * tenant has not connected tracking, the UI says so instead of implying
     * that a zero is a measured conversion result.
     *
     * @param  array{key:string,label:string,short_label:string,starts_at:\Carbon\CarbonImmutable,ends_at:\Carbon\CarbonImmutable,options:array<string,string>}  $range
     * @return array<string,mixed>|null
     */
    protected function channelPulse(?int $tenantId, bool $roleAllowed, array $range, ?string $metricKey = null): ?array
    {
        if (! $roleAllowed || $tenantId === null) {
            return null;
        }

        $sales = $this->salesChannels->forTenant($tenantId, $range['starts_at'], $range['ends_at']);
        $priorRange = $this->previousRange($range);
        $priorSales = $this->salesChannels->forTenant($tenantId, $priorRange['starts_at'], $priorRange['ends_at']);
        $sessions = $this->storefrontSessionCount($tenantId, $range['starts_at'], $range['ends_at']);
        $priorSessions = $this->storefrontSessionCount($tenantId, $priorRange['starts_at'], $priorRange['ends_at']);
        $liveVisitors = $this->liveStorefrontVisitorCount($tenantId);
        $conversion = $sessions === null || $sessions === 0
            ? null
            : (($sales['order_count'] / $sessions) * 100);
        $priorConversion = $priorSessions === null || $priorSessions === 0
            ? null
            : (($priorSales['order_count'] / $priorSessions) * 100);
        $href = route('sales-channels.index', ['range' => $range['key']]);

        return [
            'range_label' => $range['key'] === '1d' ? 'Today' : $range['label'],
            'href' => $href,
            'metrics' => [
                [
                    'key' => 'sessions',
                    'label' => 'Sessions',
                    'value' => $sessions === null ? '—' : number_format($sessions),
                    'detail' => $sessions === null ? 'Tracking not connected' : 'Tracked storefront sessions',
                    'trend' => $sessions === null ? null : $this->percentageTrend($sessions, $priorSessions),
                    'href' => $href,
                ],
                [
                    'key' => 'sales',
                    'label' => 'Total sales',
                    'value' => '$'.number_format($sales['revenue_cents'] / 100, 2),
                    'detail' => $this->channelDetail($sales),
                    'trend' => $this->percentageTrend($sales['revenue_cents'], $priorSales['revenue_cents']),
                    'href' => $href,
                ],
                [
                    'key' => 'orders',
                    'label' => 'Orders',
                    'value' => number_format($sales['order_count']),
                    'detail' => 'Confirmed sales across channels',
                    'trend' => $this->percentageTrend($sales['order_count'], $priorSales['order_count']),
                    'href' => $href,
                ],
                [
                    'key' => 'conversion',
                    'label' => 'Conversion rate',
                    'value' => $conversion === null ? '—' : number_format($conversion, 2).'%',
                    'detail' => $conversion === null ? 'Needs tracked sessions' : 'Orders ÷ tracked sessions',
                    'trend' => $conversion === null ? null : $this->percentageTrend($conversion, $priorConversion),
                    'href' => $href,
                ],
                [
                    'key' => 'visitors',
                    'label' => 'Live visitors',
                    'value' => $liveVisitors === null ? '—' : number_format($liveVisitors),
                    'detail' => $liveVisitors === null ? 'Tracking not connected' : 'Active in the last 5 minutes',
                    'live' => $liveVisitors !== null,
                    'href' => $href,
                ],
            ],
            'chart' => $this->channelPulseChart($tenantId, $range, $metricKey),
        ];
    }

    /**
     * @param  array{starts_at:\Carbon\CarbonImmutable,ends_at:\Carbon\CarbonImmutable}  $range
     * @return array{key:string,starts_at:\Carbon\CarbonImmutable,ends_at:\Carbon\CarbonImmutable}
     */
    protected function previousRange(array $range): array
    {
        $seconds = max(1, $range['ends_at']->diffInSeconds($range['starts_at']));
        $endsAt = $range['starts_at']->subSecond();

        return [
            'key' => $range['key'],
            'starts_at' => $endsAt->subSeconds($seconds),
            'ends_at' => $endsAt,
        ];
    }

    protected function storefrontSessionCount(int $tenantId, \Carbon\CarbonImmutable $startsAt, \Carbon\CarbonImmutable $endsAt): ?int
    {
        if (! Schema::hasTable('marketing_storefront_events')) {
            return null;
        }

        return (int) MarketingStorefrontEvent::query()
            ->forTenantId($tenantId)
            ->where('event_type', 'session_started')
            ->whereBetween('occurred_at', [$startsAt, $endsAt])
            ->where('source_id', 'like', 'session_started:%')
            ->distinct()
            ->count('source_id');
    }

    protected function liveStorefrontVisitorCount(int $tenantId): ?int
    {
        if (! Schema::hasTable('marketing_storefront_events')) {
            return null;
        }

        return (int) MarketingStorefrontEvent::query()
            ->forTenantId($tenantId)
            ->where('event_type', 'session_started')
            ->where('occurred_at', '>=', now()->subMinutes(5))
            ->where('source_id', 'like', 'session_started:%')
            ->distinct()
            ->count('source_id');
    }

    /** @return array{label:string,tone:string}|null */
    protected function percentageTrend(int|float $current, int|float|null $previous): ?array
    {
        if ($previous === null) {
            return null;
        }

        if ($previous <= 0) {
            return $current > 0 ? ['label' => 'New', 'tone' => 'positive'] : null;
        }

        $change = (($current - $previous) / $previous) * 100;
        $rounded = (int) round(abs($change));

        if ($rounded === 0) {
            return ['label' => 'No change', 'tone' => 'neutral'];
        }

        return [
            'label' => ($change > 0 ? '+' : '−').number_format($rounded).'%',
            'tone' => $change > 0 ? 'positive' : 'negative',
        ];
    }

    /** @param array{channel_count:int,channels:array<int,array{label:string}>} $sales */
    protected function channelDetail(array $sales): string
    {
        if ($sales['channel_count'] === 0) {
            return 'No confirmed sales in this period';
        }

        if ($sales['channel_count'] === 1) {
            return (string) ($sales['channels'][0]['label'] ?? 'Sales channel');
        }

        return number_format($sales['channel_count']).' sales channels';
    }

    /**
     * The Home chart intentionally stays inside the dashboard. It compares the
     * selected period with the equivalent preceding period using the same
     * isolated sales and storefront-event lanes as the top strip.
     *
     * @param  array{key:string,label:string,short_label:string,starts_at:\Carbon\CarbonImmutable,ends_at:\Carbon\CarbonImmutable,options:array<string,string>}  $range
     * @return array<string,mixed>
     */
    protected function channelPulseChart(int $tenantId, array $range, ?string $metricKey): array
    {
        $metricKey = in_array($metricKey, ['sessions', 'sales', 'orders', 'conversion', 'visitors'], true)
            ? $metricKey
            : 'sales';
        $priorRange = $this->previousRange($range);
        $current = $this->pulseSeries($tenantId, $range, $metricKey);
        $prior = $this->pulseSeries($tenantId, $priorRange, $metricKey);
        $definitions = [
            'sessions' => ['title' => 'Sessions over time', 'subtitle' => 'Tracked storefront sessions in the selected period.', 'unit' => 'count'],
            'sales' => ['title' => 'Total sales over time', 'subtitle' => 'Confirmed sales from each connected channel.', 'unit' => 'currency'],
            'orders' => ['title' => 'Orders over time', 'subtitle' => 'Confirmed orders from each connected channel.', 'unit' => 'count'],
            'conversion' => ['title' => 'Conversion rate over time', 'subtitle' => 'Confirmed orders divided by tracked storefront sessions.', 'unit' => 'percent'],
            'visitors' => ['title' => 'Visitor activity over time', 'subtitle' => 'Tracked session starts in the selected period. The top number remains the last five minutes.', 'unit' => 'count'],
        ];
        $definition = $definitions[$metricKey];

        return [
            'key' => $metricKey,
            'title' => $definition['title'],
            'subtitle' => $definition['subtitle'],
            'unit' => $definition['unit'],
            'value' => $this->formatPulseChartValue($metricKey, $current),
            'labels' => $current['labels'],
            'current' => $current['values'],
            'previous' => $prior['values'],
            'current_label' => $this->pulseRangeLabel($range),
            'previous_label' => $this->pulseRangeLabel($priorRange),
            'has_data' => $current['has_measurement'] || $prior['has_measurement'],
            'empty_message' => $metricKey === 'conversion'
                ? 'Track storefront sessions and confirmed orders to plot conversion.'
                : 'No tracked data is available for this period yet.',
        ];
    }

    /**
     * @param  array{starts_at:\Carbon\CarbonImmutable,ends_at:\Carbon\CarbonImmutable}  $range
     * @return array{labels:array<int,string>,values:array<int,float|int>,sales_total:int,order_total:int,session_total:int,has_measurement:bool}
     */
    protected function pulseSeries(int $tenantId, array $range, string $metricKey): array
    {
        $unit = $this->pulseBucketUnit($range);
        $buckets = $this->pulseBuckets($range, $unit);
        $sales = array_fill_keys(array_keys($buckets), 0);
        $orders = array_fill_keys(array_keys($buckets), 0);
        $sessions = array_fill_keys(array_keys($buckets), []);

        if (Schema::hasTable('orders')) {
            Order::query()
                ->forTenantId($tenantId)
                ->whereBetween('ordered_at', [$range['starts_at'], $range['ends_at']])
                ->get(['ordered_at', 'total_price'])
                ->each(function (Order $order) use (&$sales, &$orders, $unit): void {
                    if (! $order->ordered_at) {
                        return;
                    }
                    $key = $this->pulseBucketKey($order->ordered_at, $unit);
                    if (! array_key_exists($key, $sales)) {
                        return;
                    }
                    $sales[$key] += (int) round(((float) $order->total_price) * 100);
                    $orders[$key]++;
                });
        }

        if (Schema::hasTable('website_orders')) {
            WebsiteOrder::query()
                ->forTenantId($tenantId)
                ->where('payment_status', 'paid')
                ->whereBetween('paid_at', [$range['starts_at'], $range['ends_at']])
                ->get(['paid_at', 'total_cents'])
                ->each(function (WebsiteOrder $order) use (&$sales, &$orders, $unit): void {
                    if (! $order->paid_at) {
                        return;
                    }
                    $key = $this->pulseBucketKey($order->paid_at, $unit);
                    if (! array_key_exists($key, $sales)) {
                        return;
                    }
                    $sales[$key] += (int) $order->total_cents;
                    $orders[$key]++;
                });
        }

        if (Schema::hasTable('marketing_storefront_events')) {
            MarketingStorefrontEvent::query()
                ->forTenantId($tenantId)
                ->where('event_type', 'session_started')
                ->whereBetween('occurred_at', [$range['starts_at'], $range['ends_at']])
                ->where('source_id', 'like', 'session_started:%')
                ->get(['source_id', 'occurred_at'])
                ->each(function (MarketingStorefrontEvent $event) use (&$sessions, $unit): void {
                    if (! $event->occurred_at) {
                        return;
                    }
                    $key = $this->pulseBucketKey($event->occurred_at, $unit);
                    if (array_key_exists($key, $sessions)) {
                        $sessions[$key][(string) $event->source_id] = true;
                    }
                });
        }

        $sessionCounts = array_map(static fn (array $sourceIds): int => count($sourceIds), $sessions);
        $values = match ($metricKey) {
            'sales' => array_values($sales),
            'orders' => array_values($orders),
            'conversion' => array_values(array_map(static fn (int $orderCount, int $sessionCount): float => $sessionCount > 0 ? ($orderCount / $sessionCount) * 100 : 0, $orders, $sessionCounts)),
            'sessions', 'visitors' => array_values($sessionCounts),
            default => array_values($sales),
        };
        $salesTotal = array_sum($sales);
        $orderTotal = array_sum($orders);
        $sessionTotal = array_sum($sessionCounts);

        return [
            'labels' => array_values(array_column($buckets, 'label')),
            'values' => $values,
            'sales_total' => $salesTotal,
            'order_total' => $orderTotal,
            'session_total' => $sessionTotal,
            'has_measurement' => match ($metricKey) {
                'sales', 'orders' => $orderTotal > 0,
                'conversion', 'sessions', 'visitors' => $sessionTotal > 0,
                default => false,
            },
        ];
    }

    /** @param array{starts_at:\Carbon\CarbonImmutable,ends_at:\Carbon\CarbonImmutable} $range */
    protected function pulseBucketUnit(array $range): string
    {
        return $range['key'] === '1d' ? 'hour' : 'day';
    }

    /**
     * @param  array{starts_at:\Carbon\CarbonImmutable,ends_at:\Carbon\CarbonImmutable}  $range
     * @return array<string,array{label:string}>
     */
    protected function pulseBuckets(array $range, string $unit): array
    {
        $cursor = $unit === 'hour' ? $range['starts_at']->startOfHour() : $range['starts_at']->startOfDay();
        $end = $unit === 'hour' ? $range['ends_at']->startOfHour() : $range['ends_at']->startOfDay();
        $buckets = [];

        while ($cursor->lte($end)) {
            $key = $this->pulseBucketKey($cursor, $unit);
            $buckets[$key] = [
                'label' => $unit === 'hour' ? $cursor->format('g A') : $cursor->format('M j'),
            ];
            $cursor = $unit === 'hour' ? $cursor->addHour() : $cursor->addDay();
        }

        return $buckets;
    }

    protected function pulseBucketKey(\Carbon\CarbonInterface $at, string $unit): string
    {
        return $at->format($unit === 'hour' ? 'Y-m-d-H' : 'Y-m-d');
    }

    /**
     * @param  array{sales_total:int,order_total:int,session_total:int}  $series
     */
    protected function formatPulseChartValue(string $metricKey, array $series): string
    {
        return match ($metricKey) {
            'sales' => '$'.number_format($series['sales_total'] / 100, 2),
            'orders' => number_format($series['order_total']),
            'conversion' => $series['session_total'] > 0
                ? number_format(($series['order_total'] / $series['session_total']) * 100, 2).'%'
                : '—',
            'sessions', 'visitors' => number_format($series['session_total']),
            default => '—',
        };
    }

    /** @param array{starts_at:\Carbon\CarbonImmutable,ends_at:\Carbon\CarbonImmutable} $range */
    protected function pulseRangeLabel(array $range): string
    {
        return $range['starts_at']->isSameDay($range['ends_at'])
            ? $range['starts_at']->format('M j, Y')
            : $range['starts_at']->format('M j').'–'.$range['ends_at']->format('M j, Y');
    }

    /** @return array<string,mixed>|null */
    protected function workflowAutomationHealth(?int $tenantId, bool $roleAllowed): ?array
    {
        if (! $roleAllowed
            || $tenantId === null
            || ! Route::has('workflows.index')
            || ! Schema::hasTable('automation_workflows')
            || ! $this->moduleAccess->canAccess($tenantId, 'workflow_automations')) {
            return null;
        }

        $workflows = AutomationWorkflow::query()->forTenantId($tenantId);
        $total = (clone $workflows)->count();
        $active = (clone $workflows)->where('status', AutomationWorkflow::STATUS_ACTIVE)->count();
        $needsAttention = Schema::hasTable('automation_workflow_runs')
            ? AutomationWorkflowRun::query()->forTenantId($tenantId)->whereIn('status', ['failed', 'partial_failure'])->where('created_at', '>=', now()->subDays(7))->count()
            : 0;
        $latest = Schema::hasTable('automation_workflow_runs')
            ? AutomationWorkflowRun::query()->forTenantId($tenantId)->latest()->first(['status', 'finished_at', 'created_at'])
            : null;

        return [
            'total' => $total,
            'active' => $active,
            'needs_attention' => $needsAttention,
            'latest_status' => $latest?->status,
            'latest_at' => ($latest?->finished_at ?? $latest?->created_at)?->toIso8601String(),
            'href' => route('workflows.index'),
            'history_href' => route('workflows.history'),
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
                ->where('agreement_type', Agreement::TYPE_FRONT_YARD_CLIENT_SERVICES)
                ->where('template_key', Agreement::TEMPLATE_FRONT_YARD_CLIENT_SERVICES)
                ->whereNull('parent_agreement_id')
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
            || in_array(strtolower(trim((string) $tenant->slug)), ['collins-electric', 'collins-upstate-electric'], true)
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

        if ($tenantId !== null && ($canAccessMarketing || $canAccessOps)) {
            $sales = $this->salesChannels->forTenant($tenantId, $range['starts_at'], $range['ends_at']);
            $hasRelevantChannel = in_array($channelType, ['shopify', 'hybrid'], true) || $sales['has_website_channel'];

            if ($hasRelevantChannel && $sales['order_count'] > 0) {
                $channelDetail = $sales['channel_count'] === 1
                    ? ($sales['channels'][0]['label'] ?? 'Sales channel')
                    : number_format($sales['channel_count']).' channels';

                return [
                    'label' => 'Sales-channel revenue · '.$range['short_label'],
                    'value' => '$'.number_format($sales['revenue_cents'] / 100, 2),
                    'supporting' => number_format($sales['order_count']).' confirmed orders · '.$channelDetail,
                    'tone' => 'emerald',
                    'destination' => ['kind' => 'sales_channels'],
                ];
            }
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
                'href' => route('admin.users'),
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
                'label' => 'Explore Branches',
                'description' => 'See what is included, what can be added now, and what requires a request.',
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
                'price_label' => filled(data_get($module, 'purchase.price_display'))
                    ? (string) data_get($module, 'purchase.price_display')
                    : (string) ($module['pricing_impact_label'] ?? 'Included or request-based'),
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
            'sales_channels' => route('sales-channels.index', ['range' => $rangeKey]),
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
