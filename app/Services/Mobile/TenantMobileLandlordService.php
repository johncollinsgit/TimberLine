<?php

namespace App\Services\Mobile;

use App\Models\ClientProject;
use App\Models\CustomerAccessRequest;
use App\Models\FieldServiceJob;
use App\Models\LandlordOperatorAction;
use App\Models\LandlordProspect;
use App\Models\Order;
use App\Models\ServiceInquiry;
use App\Models\Tenant;
use App\Models\TenantBillingReceipt;
use App\Models\TenantBillingSubscription;
use App\Models\TenantOnboardingBlueprint;
use App\Models\TenantSupportTicket;
use App\Models\User;
use App\Services\Onboarding\CustomerAccessApprovalService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TenantMobileLandlordService
{
    public function __construct(
        protected TenantModuleAccessResolver $accessResolver,
        protected CustomerAccessApprovalService $approvals,
        protected LandlordOperatorActionAuditService $audit,
    ) {}

    /** @return array<string,mixed> */
    public function bootstrap(): array
    {
        $tenants = Tenant::query()->count();
        $activeTenants = $this->activeTenantIds()->count();
        $mrrCents = $this->monthlyRecurringRevenueCents();

        return [
            'metrics' => [
                ['label' => 'Monthly revenue', 'value' => '$'.number_format($mrrCents / 100, 2)],
                ['label' => 'Tenants', 'value' => $tenants],
                ['label' => 'Active tenants', 'value' => $activeTenants],
                ['label' => 'Tenant users', 'value' => User::query()->whereHas('tenants')->count()],
                ['label' => 'Open tickets', 'value' => Schema::hasTable('tenant_support_tickets') ? TenantSupportTicket::withoutGlobalScopes()->whereNotIn('status', ['resolved', 'closed'])->count() : 0],
                ['label' => 'Pending access', 'value' => Schema::hasTable('customer_access_requests') ? CustomerAccessRequest::query()->whereNotIn('status', ['approved', 'rejected'])->count() : 0],
                ['label' => 'New inquiries', 'value' => Schema::hasTable('service_inquiries') ? ServiceInquiry::query()->where('status', 'new')->count() : 0],
            ],
            'tenant_types' => $this->tenantTypeReport(),
            'tenant_growth' => $this->tenantGrowthReport(),
            'activity' => [
                'active_30d' => $activeTenants,
                'inactive_30d' => max(0, $tenants - $activeTenants),
                'rate' => $tenants > 0 ? (int) round(($activeTenants / $tenants) * 100) : 0,
            ],
            'recent_tenants' => $this->tenants('', 8)['tenants'],
            'recent_activity' => LandlordOperatorAction::query()->with('tenant:id,name')->latest('id')->limit(12)->get()->map(fn (LandlordOperatorAction $action): array => [
                'id' => (int) $action->id,
                'tenant' => $action->tenant?->name ?: 'Everbranch',
                'action' => Str::headline((string) $action->action_type),
                'status' => (string) $action->status,
                'created_at' => optional($action->created_at)->toIso8601String(),
            ])->values(),
            'modern_forestry_sales' => $this->modernForestrySales(),
            'landlord_revenue' => $this->landlordRevenue(),
            'customer_acquisition' => $this->customerAcquisitionSummary(),
            'access_requests' => $this->accessRequests(12),
            'support_inquiries' => $this->inquiries(12),
        ];
    }

    /**
     * Read-only Modern Forestry Shopify order evidence for an operator display.
     *
     * These are imported order amounts, not Shopify payouts or accounting net
     * income. Keeping that distinction in the contract prevents a dashboard
     * consumer from silently treating operational sales as reconciled cash.
     *
     * @return array<string,mixed>
     */
    protected function modernForestrySales(): array
    {
        $unavailable = fn (string $reason): array => [
            'available' => false,
            'reason' => $reason,
            'source' => 'Everbranch tenant-scoped imported Shopify orders',
            'basis' => 'Gross imported order amounts less recorded refunds. Shopify payouts, processing fees, taxes, and accounting net income are not exposed by this report.',
            'currency' => null,
            'periods' => [],
            'observed_through' => null,
        ];

        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'tenant_id') || ! Schema::hasColumn('orders', 'ordered_at') || ! Schema::hasColumn('orders', 'shopify_store_key') || ! Schema::hasColumn('orders', 'total_price')) {
            return $unavailable('The verified orders reporting columns are not available.');
        }

        $tenant = Tenant::query()->where('slug', 'modern-forestry')->first();
        if (! $tenant) {
            return $unavailable('The Modern Forestry workspace was not found.');
        }

        $now = now();
        $starts = [
            'day' => $now->copy()->startOfDay(),
            'week' => $now->copy()->startOfWeek(),
            'month' => $now->copy()->startOfMonth(),
        ];
        $queryStart = collect($starts)->sortBy(fn ($start) => $start->getTimestamp())->first();
        $hasRefunds = Schema::hasColumn('orders', 'refund_total');
        $hasCurrency = Schema::hasColumn('orders', 'currency_code');
        $rows = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('shopify_store_key', ['retail', 'wholesale'])
            ->whereBetween('ordered_at', [$queryStart, $now])
            ->get(array_values(array_filter([
                'ordered_at',
                'shopify_store_key',
                'total_price',
                $hasRefunds ? 'refund_total' : null,
                $hasCurrency ? 'currency_code' : null,
            ])));

        $currencies = $hasCurrency
            ? $rows->pluck('currency_code')->filter()->map(fn ($currency): string => strtoupper(trim((string) $currency)))->unique()->values()
            : collect(['USD']);
        if ($currencies->count() > 1) {
            return $unavailable('Imported orders contain multiple currencies and cannot be combined safely.');
        }
        $currency = (string) ($currencies->first() ?: 'USD');
        $periods = collect($starts)->mapWithKeys(function ($start, string $period) use ($rows, $hasRefunds): array {
            $periodRows = $rows->filter(fn (Order $order): bool => $order->ordered_at !== null && $order->ordered_at->greaterThanOrEqualTo($start));
            $stores = collect(['retail', 'wholesale'])->mapWithKeys(function (string $store) use ($periodRows, $hasRefunds): array {
                $storeRows = $periodRows->where('shopify_store_key', $store);
                $grossCents = (int) round($storeRows->sum(fn (Order $order): float => (float) ($order->total_price ?? 0)) * 100);
                $refundCents = $hasRefunds ? (int) round($storeRows->sum(fn (Order $order): float => (float) ($order->refund_total ?? 0)) * 100) : null;

                return [$store => [
                    'order_count' => $storeRows->count(),
                    'gross_cents' => $grossCents,
                    'refund_cents' => $refundCents,
                    'net_after_recorded_refunds_cents' => $refundCents === null ? null : $grossCents - $refundCents,
                ]];
            });

            return [$period => [
                'starts_at' => $start->toIso8601String(),
                'ends_at' => now()->toIso8601String(),
                'order_count' => (int) $stores->sum('order_count'),
                'gross_cents' => (int) $stores->sum('gross_cents'),
                'refund_cents' => $hasRefunds ? (int) $stores->sum('refund_cents') : null,
                'net_after_recorded_refunds_cents' => $hasRefunds ? (int) $stores->sum('net_after_recorded_refunds_cents') : null,
                'stores' => $stores->all(),
            ]];
        })->all();

        return [
            'available' => true,
            'reason' => null,
            'source' => 'Everbranch tenant-scoped imported Shopify orders',
            'basis' => 'Gross imported order amounts less recorded refunds. Shopify payouts, processing fees, taxes, and accounting net income are not exposed by this report.',
            'currency' => $currency,
            'periods' => $periods,
            'observed_through' => optional($rows->max('ordered_at'))->toIso8601String(),
        ];
    }

    /**
     * Verified Evergrove Software landlord receipts, kept separate from the
     * commerce income earned inside client workspaces.
     *
     * The recurring/one-time split is based on the billing line items that
     * produced each confirmed receipt. Taxes and refunds are reported
     * separately because assigning either to a line-item category without
     * provider evidence would invent a financial allocation.
     *
     * @return array<string,mixed>
     */
    protected function landlordRevenue(): array
    {
        $unavailable = fn (string $reason): array => [
            'available' => false,
            'reason' => $reason,
            'source' => 'Everbranch Stripe-confirmed landlord billing receipts',
            'owner' => 'Evergrove Software',
            'scope' => 'Landlord receipts from client tenants. Tenant commerce income is excluded.',
            'basis' => 'No amount is inferred from catalog pricing or tenant sales.',
            'currency' => null,
            'mrr_run_rate_cents' => $this->monthlyRecurringRevenueCents(),
            'periods' => [],
            'history' => [],
            'observed_through' => null,
        ];

        if (! Schema::hasTable('tenant_billing_receipts') || ! Schema::hasTable('stripe_webhook_events')) {
            return $unavailable('The verified landlord receipt ledger is not available.');
        }

        $secret = trim((string) config('services.stripe.secret'));
        $livemode = match (true) {
            str_starts_with($secret, 'sk_live_') => true,
            str_starts_with($secret, 'sk_test_') => false,
            default => null,
        };
        if ($livemode === null) {
            return $unavailable('Stripe mode cannot be verified from the configured Everbranch account.');
        }

        $receipts = TenantBillingReceipt::withoutGlobalScopes()
            ->stripePaymentConfirmed($livemode)
            ->with([
                'tenant:id,name',
                'refunds:id,tenant_billing_receipt_id,status,amount_cents',
                'billingOrder:id,tenant_id,line_items,metadata',
                'directInvoice:id,tenant_id,line_items,metadata',
            ])
            ->orderByDesc('paid_at')
            ->limit(2000)
            ->get();

        $currencies = $receipts->pluck('currency')->filter()->map(fn ($currency): string => strtoupper(trim((string) $currency)))->unique()->values();
        if ($currencies->count() > 1) {
            return $unavailable('Verified landlord receipts contain multiple currencies and cannot be combined safely.');
        }
        $currency = (string) ($currencies->first() ?: 'USD');

        $rows = $receipts->map(function (TenantBillingReceipt $receipt): array {
            $rawItems = (array) ($receipt->billingOrder?->line_items ?: $receipt->directInvoice?->line_items);
            $normalized = collect($rawItems)->filter(fn ($line): bool => is_array($line))->map(function (array $line): array {
                $quantity = max(1, (int) ($line['quantity'] ?? 1));
                $amount = array_key_exists('unit_amount_cents', $line)
                    ? (int) ($line['amount_cents'] ?? ((int) $line['unit_amount_cents'] * $quantity))
                    : (int) ($line['amount_cents'] ?? $line['amount'] ?? 0) * $quantity;

                return [
                    'amount_cents' => $amount,
                    'frequency' => strtolower(trim((string) ($line['frequency'] ?? ''))),
                    'payment_timing' => strtolower(trim((string) ($line['payment_timing'] ?? ''))),
                    'cost_category' => strtolower(trim((string) ($line['cost_category'] ?? ''))),
                ];
            })->filter(fn (array $line): bool => $line['amount_cents'] !== 0)->values();
            $candidateGroups = [
                $normalized->filter(fn (array $line): bool => in_array($line['payment_timing'], ['due_on_acceptance', 'recurring_current'], true))->values(),
                $normalized->filter(fn (array $line): bool => $line['payment_timing'] === 'recurring_current')->values(),
                $normalized->filter(fn (array $line): bool => $line['payment_timing'] === 'recurring_future')->values(),
                $normalized,
            ];
            $receiptLines = collect($candidateGroups)->first(
                fn ($group): bool => $group->isNotEmpty() && (int) $group->sum('amount_cents') === (int) $receipt->subtotal_amount_cents
            );
            $classified = $receiptLines ? $receiptLines->map(function (array $line): array {
                $frequency = $line['frequency'];
                $timing = $line['payment_timing'];
                $category = $line['cost_category'];
                $kind = in_array($frequency, ['month', 'monthly'], true) || str_contains($timing, 'recurring') || $timing === 'monthly_in_arrears'
                    ? 'recurring'
                    : (in_array($frequency, ['one_time', 'once'], true) || in_array($timing, ['due_on_acceptance', 'scheduled', 'supplemental_work_order', 'milestone'], true) || str_contains($category, 'implementation') || str_contains($category, 'milestone') ? 'one_time' : 'uncategorized');

                return ['kind' => $kind, 'amount_cents' => $line['amount_cents']];
            }) : collect();

            return [
                'paid_at' => $receipt->paid_at,
                'tenant' => (string) ($receipt->tenant?->name ?? 'Client tenant'),
                'total_cents' => (int) $receipt->total_amount_cents,
                'refund_cents' => (int) $receipt->refunds->where('status', 'succeeded')->sum('amount_cents'),
                'recurring_cents' => (int) $classified->where('kind', 'recurring')->sum('amount_cents'),
                'one_time_cents' => (int) $classified->where('kind', 'one_time')->sum('amount_cents'),
                'uncategorized_cents' => $receiptLines
                    ? (int) $classified->where('kind', 'uncategorized')->sum('amount_cents')
                    : (int) $receipt->subtotal_amount_cents,
            ];
        });

        $summarize = static function ($periodRows): array {
            $total = (int) $periodRows->sum('total_cents');
            $refunds = (int) $periodRows->sum('refund_cents');

            return [
                'receipt_count' => $periodRows->count(),
                'verified_cash_received_cents' => max(0, $total - $refunds),
                'gross_receipts_cents' => $total,
                'refund_cents' => $refunds,
                'recurring_line_item_cents' => (int) $periodRows->sum('recurring_cents'),
                'one_time_line_item_cents' => (int) $periodRows->sum('one_time_cents'),
                'uncategorized_line_item_cents' => (int) $periodRows->sum('uncategorized_cents'),
            ];
        };
        $now = now();
        $periods = [
            'month' => $summarize($rows->filter(fn (array $row): bool => $row['paid_at']?->greaterThanOrEqualTo($now->copy()->startOfMonth()) === true)),
            'year' => $summarize($rows->filter(fn (array $row): bool => $row['paid_at']?->greaterThanOrEqualTo($now->copy()->startOfYear()) === true)),
        ];
        $history = $rows->filter(fn (array $row): bool => $row['paid_at']?->greaterThanOrEqualTo($now->copy()->subMonths(23)->startOfMonth()) === true)
            ->groupBy(fn (array $row): string => $row['paid_at']->format('Y-m'))
            ->map(fn ($monthRows, string $month): array => ['month' => $month, ...$summarize($monthRows)])
            ->sortKeys()->values()->all();

        return [
            'available' => true,
            'reason' => null,
            'source' => 'Everbranch Stripe-confirmed landlord billing receipts',
            'owner' => 'Evergrove Software',
            'scope' => 'Landlord receipts from client tenants. Tenant commerce income is excluded.',
            'basis' => 'Verified cash is Stripe-confirmed receipt totals less succeeded refunds. Recurring and one-time amounts are pre-tax billing-line classifications; taxes and refunds are not allocated between those categories.',
            'currency' => $currency,
            'mrr_run_rate_cents' => $this->monthlyRecurringRevenueCents(),
            'periods' => $periods,
            'history' => $history,
            'observed_through' => optional($rows->max('paid_at'))->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    protected function customerAcquisitionSummary(): array
    {
        if (! Schema::hasTable('landlord_prospects')) {
            return [
                'available' => false,
                'reason' => 'The Everbranch landlord prospect pipeline is not available.',
                'source' => 'Everbranch landlord prospect pipeline',
                'manage_url' => 'https://app.theeverbranch.com/landlord/onboarding',
                'stage_counts' => [],
                'follow_up_due' => 0,
                'needs_attention' => [],
            ];
        }

        $terminal = ['converted', 'not_fit', 'unsubscribed'];
        $counts = LandlordProspect::query()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn ($count): int => (int) $count)->all();
        $attention = LandlordProspect::query()
            ->whereNotIn('status', $terminal)
            ->where(function ($query): void {
                $query->where('status', 'replied')
                    ->orWhere('status', 'meeting_scheduled')
                    ->orWhere(fn ($followUp) => $followUp->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', now()));
            })
            ->orderByRaw("case when status = 'replied' then 0 when status = 'meeting_scheduled' then 1 else 2 end")
            ->orderBy('next_follow_up_at')
            ->limit(12)
            ->get(['id', 'business_name', 'trade', 'city', 'status', 'fit_score', 'opportunity_priority', 'next_follow_up_at'])
            ->map(fn (LandlordProspect $prospect): array => [
                'id' => (int) $prospect->id,
                'business_name' => (string) $prospect->business_name,
                'trade' => (string) $prospect->trade,
                'city' => (string) ($prospect->city ?? ''),
                'status' => (string) $prospect->status,
                'fit_score' => $prospect->fit_score === null ? null : (int) $prospect->fit_score,
                'priority' => $prospect->opportunity_priority,
                'next_follow_up_at' => optional($prospect->next_follow_up_at)->toIso8601String(),
            ])->values()->all();

        return [
            'available' => true,
            'reason' => null,
            'source' => 'Everbranch landlord prospect pipeline',
            'manage_url' => 'https://app.theeverbranch.com/landlord/onboarding',
            'stage_counts' => $counts,
            'follow_up_due' => LandlordProspect::query()->whereNotIn('status', $terminal)->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', now())->count(),
            'needs_attention' => $attention,
            'safety' => 'Read-only summary. Discovery may incur provider cost and requires confirmation. Outreach sends, meeting bookings, conversion, and client onboarding require explicit operator review and confirmation in Everbranch.',
        ];
    }

    /** @return array<string,mixed> */
    public function tenants(string $search = '', int $limit = 40): array
    {
        $search = trim($search);
        $activeTenantIds = $this->activeTenantIds();
        $activity = $this->activityCounts();
        $rows = Tenant::query()->with(['accessProfile'])->withCount('users')
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(fn ($builder) => $builder->where('name', 'like', $like)->orWhere('slug', 'like', $like));
            })->orderBy('name')->limit(max(1, min(100, $limit)))->get();

        return ['tenants' => $rows->map(fn (Tenant $tenant): array => [
            'id' => (int) $tenant->id,
            'name' => (string) $tenant->name,
            'slug' => (string) $tenant->slug,
            'plan' => Str::headline((string) ($tenant->accessProfile?->plan_key ?: 'starter')),
            'operating_mode' => Str::headline((string) ($tenant->accessProfile?->operating_mode ?: 'shopify')),
            'users_count' => (int) $tenant->users_count,
            'active' => $activeTenantIds->contains((int) $tenant->id),
            'activity_30d' => (int) ($activity[(int) $tenant->id] ?? 0),
        ])->values()];
    }

    /** @return array<string,mixed> */
    public function tenant(int $tenantId): array
    {
        $tenant = Tenant::query()->with(['accessProfile', 'users:id,name,email,role,is_active'])->findOrFail($tenantId);
        $access = $this->accessResolver->resolveForTenant($tenantId);
        $modules = collect((array) ($access['modules'] ?? []))->map(fn (array $state, string $key): array => [
            'key' => $key,
            'label' => (string) ($state['label'] ?? Str::headline($key)),
            'enabled' => (bool) ($state['enabled'] ?? false),
            'setup_status' => (string) ($state['setup_status'] ?? 'not_started'),
            'reason' => (string) ($state['reason'] ?? ''),
        ])->values();

        return [
            'tenant' => ['id' => (int) $tenant->id, 'name' => (string) $tenant->name, 'slug' => (string) $tenant->slug, 'plan' => (string) ($access['plan_key'] ?? 'starter'), 'operating_mode' => (string) ($access['operating_mode'] ?? 'shopify')],
            'metrics' => [
                ['label' => 'Users', 'value' => $tenant->users->count()],
                ['label' => 'Active Branches', 'value' => $modules->where('enabled', true)->count()],
                ['label' => 'Activity (30d)', 'value' => (int) ($this->activityCounts()[(int) $tenant->id] ?? 0)],
                ['label' => 'Setup ready', 'value' => $modules->where('setup_status', 'ready')->count()],
            ],
            'users' => $tenant->users->map(fn (User $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name, 'email' => (string) $user->email, 'role' => (string) $user->role, 'active' => $user->is_active !== false])->values(),
            'branches' => $modules,
            'recent_actions' => $this->audit->recentForTenant($tenantId, 15)->map(fn ($action): array => ['id' => (int) $action->id, 'type' => (string) $action->action_type, 'status' => (string) $action->status, 'created_at' => optional($action->created_at)->toIso8601String()])->values(),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function accessRequests(int $limit = 30): array
    {
        if (! Schema::hasTable('customer_access_requests')) {
            return [];
        }

        return CustomerAccessRequest::query()->with('tenant:id,name')->latest('id')->limit($limit)->get()->map(fn (CustomerAccessRequest $request): array => [
            'id' => (int) $request->id,
            'name' => (string) ($request->name ?: $request->email),
            'email' => (string) $request->email,
            'company' => $request->company,
            'status' => (string) $request->status,
            'intent' => (string) $request->intent,
            'tenant' => $request->tenant?->name,
            'message' => $request->message,
        ])->values()->all();
    }

    /** @return array<string,mixed> */
    public function decideAccessRequest(int $requestId, string $action, User $actor, ?string $note): array
    {
        $request = match ($action) {
            'approve' => $this->approvals->approve($requestId, (int) $actor->id, $note),
            'reject' => $this->approvals->reject($requestId, (int) $actor->id, $note),
            default => abort(422, 'Choose approve or reject.'),
        };

        return ['ok' => true, 'request_id' => (int) $request->id, 'status' => (string) $request->status];
    }

    /** @return array<int,array<string,mixed>> */
    public function inquiries(int $limit = 30): array
    {
        if (! Schema::hasTable('service_inquiries')) {
            return [];
        }

        return ServiceInquiry::query()->latest('id')->limit($limit)->get()->map(fn (ServiceInquiry $inquiry): array => [
            'id' => (int) $inquiry->id,
            'name' => (string) $inquiry->name,
            'email' => (string) $inquiry->email,
            'company' => $inquiry->company,
            'status' => (string) $inquiry->status,
            'pain_point' => $inquiry->pain_point,
        ])->values()->all();
    }

    /** @return array<string,mixed> */
    public function updateInquiry(int $inquiryId, string $status, User $actor): array
    {
        $inquiry = ServiceInquiry::query()->findOrFail($inquiryId);
        $before = ['status' => (string) $inquiry->status];
        $inquiry->forceFill(['status' => $status])->save();
        $this->audit->record(null, (int) $actor->id, 'service_inquiry.status', targetType: 'service_inquiry', targetId: $inquiry->id, context: ['surface' => 'everbranch_mobile'], beforeState: $before, afterState: ['status' => $status]);

        return ['ok' => true, 'inquiry_id' => (int) $inquiry->id, 'status' => (string) $inquiry->status];
    }

    protected function monthlyRecurringRevenueCents(): int
    {
        if (! Schema::hasTable('tenant_billing_subscriptions')) {
            return 0;
        }

        return TenantBillingSubscription::withoutGlobalScopes()->whereIn('status', ['active', 'trialing'])
            ->get(['purchase_key'])
            ->sum(fn (TenantBillingSubscription $subscription): int => $this->purchasePriceCents((string) $subscription->purchase_key));
    }

    protected function purchasePriceCents(string $purchaseKey): int
    {
        foreach (['plans', 'addons'] as $group) {
            foreach ((array) config('module_catalog.'.$group, []) as $key => $definition) {
                if ($purchaseKey === (string) ($definition['purchase_key'] ?? $group.'.'.$key)) {
                    return (int) data_get($definition, 'pricing.recurring_price_cents', 0);
                }
            }
        }

        return 0;
    }

    /** @return \Illuminate\Support\Collection<int,int> */
    protected function activeTenantIds()
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return collect();
        }

        return Tenant::query()->whereHas('users.tokens', fn ($query) => $query->where('last_used_at', '>=', now()->subDays(30)))
            ->pluck('id')->map(fn ($id): int => (int) $id);
    }

    /** @return array<int,int> */
    protected function activityCounts(): array
    {
        $since = now()->subDays(30);
        $counts = [];
        foreach ([Order::class, FieldServiceJob::class, ClientProject::class] as $model) {
            $model::withoutGlobalScopes()->where('updated_at', '>=', $since)->selectRaw('tenant_id, COUNT(*) as aggregate')->groupBy('tenant_id')->get()
                ->each(function ($row) use (&$counts): void {
                    $tenantId = (int) $row->tenant_id;
                    $counts[$tenantId] = ($counts[$tenantId] ?? 0) + (int) $row->aggregate;
                });
        }

        return $counts;
    }

    /** @return array<int,array{label:string,value:int}> */
    protected function tenantTypeReport(): array
    {
        $blueprints = Schema::hasTable('tenant_onboarding_blueprints')
            ? TenantOnboardingBlueprint::withoutGlobalScopes()->latest('id')->get(['tenant_id', 'payload'])->unique('tenant_id')->keyBy('tenant_id')
            : collect();
        $counts = ['Retail' => 0, 'Trades' => 0, 'Services' => 0, 'Other' => 0];
        Tenant::query()->with('accessProfile')->get()->each(function (Tenant $tenant) use (&$counts, $blueprints): void {
            $template = strtolower((string) data_get($blueprints->get($tenant->id)?->payload, 'business_template', ''));
            $mode = strtolower((string) ($tenant->accessProfile?->operating_mode ?? ''));
            $type = match (true) {
                in_array($template, ['electrician', 'landscaping', 'field_service'], true) => 'Trades',
                in_array($template, ['law', 'generic_project', 'professional_services'], true) => 'Services',
                in_array($template, ['apparel', 'candle_maker', 'shopify_retail'], true), in_array($mode, ['shopify', 'square', 'retail'], true) => 'Retail',
                default => 'Other',
            };
            $counts[$type]++;
        });

        return collect($counts)->map(fn (int $value, string $label): array => ['label' => $label, 'value' => $value])->values()->all();
    }

    /** @return array<int,array{label:string,value:int}> */
    protected function tenantGrowthReport(): array
    {
        return collect(range(11, 0))->map(function (int $monthsAgo): array {
            $month = now()->subMonths($monthsAgo);

            return ['label' => $month->format('M'), 'value' => Tenant::query()->where('created_at', '<=', $month->copy()->endOfMonth())->count()];
        })->values()->all();
    }
}
