<?php

namespace App\Services\Mobile;

use App\Models\ClientProject;
use App\Models\CustomerAccessRequest;
use App\Models\FieldServiceJob;
use App\Models\LandlordOperatorAction;
use App\Models\Order;
use App\Models\ServiceInquiry;
use App\Models\Tenant;
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
            'access_requests' => $this->accessRequests(12),
            'support_inquiries' => $this->inquiries(12),
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
