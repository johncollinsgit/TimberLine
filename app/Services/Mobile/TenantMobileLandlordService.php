<?php

namespace App\Services\Mobile;

use App\Models\CustomerAccessRequest;
use App\Models\ServiceInquiry;
use App\Models\Tenant;
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
        return [
            'metrics' => [
                ['label' => 'Tenants', 'value' => Tenant::query()->count()],
                ['label' => 'Pending access', 'value' => Schema::hasTable('customer_access_requests') ? CustomerAccessRequest::query()->whereNotIn('status', ['approved', 'rejected'])->count() : 0],
                ['label' => 'New inquiries', 'value' => Schema::hasTable('service_inquiries') ? ServiceInquiry::query()->where('status', 'new')->count() : 0],
            ],
            'recent_tenants' => $this->tenants('', 8)['tenants'],
            'access_requests' => $this->accessRequests(12),
            'support_inquiries' => $this->inquiries(12),
        ];
    }

    /** @return array<string,mixed> */
    public function tenants(string $search = '', int $limit = 40): array
    {
        $search = trim($search);
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
}
