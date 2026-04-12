<?php

namespace App\Services\Onboarding;

use App\Models\CustomerAccessRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalPasswordSetupNotification;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Support\Tenancy\TenantHostBuilder;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class CustomerAccessApprovalService
{
    public function __construct(
        protected LandlordOperatorActionAuditService $auditService,
        protected TenantHostBuilder $hostBuilder,
    ) {
    }

    public function approve(int $requestId, int $actorUserId, ?string $decisionNote = null): CustomerAccessRequest
    {
        $actor = $this->actorOrFail($actorUserId);
        $this->assertActorAuthorized($actor);

        $decisionNote = $this->normalizeNote($decisionNote);

        /** @var array{request:CustomerAccessRequest,tenant:?Tenant,user:User,should_send:bool,preferred_host:?string,before:array<string,mixed>,after:array<string,mixed>} $result */
        $result = DB::transaction(function () use ($requestId, $actorUserId, $decisionNote): array {
            $request = $this->lockRequest($requestId);
            $before = $this->auditSnapshot($request);

            if ($this->isRejected($request)) {
                $this->auditService->record(
                    tenantId: (int) ($request->tenant_id ?: 0) ?: null,
                    actorUserId: $actorUserId,
                    actionType: 'customer_access_request.approve',
                    status: 'blocked',
                    targetType: 'customer_access_request',
                    targetId: (int) $request->id,
                    context: ['reason' => 'rejected_request'],
                    beforeState: $before,
                    afterState: $before,
                );

                throw new DomainException('This access request has been rejected and must be reopened explicitly.');
            }

            $intent = $this->normalizeIntent((string) ($request->intent ?? 'production'));
            $slug = $this->resolveTenantSlug($request, $intent);

            $tenant = $this->resolveTenant($request, $slug);
            $user = $this->resolveUser($request, $intent);

            $user->forceFill([
                'is_active' => true,
                'approved_at' => $user->approved_at ?? now(),
                'approved_by' => $user->approved_by ?? $actorUserId,
                'requested_via' => $this->normalizeRequestedVia((string) ($user->requested_via ?? ''), $intent),
            ])->save();

            if ($tenant) {
                $user->tenants()->syncWithoutDetaching([
                    (int) $tenant->id => ['role' => 'manager'],
                ]);
            }

            if ((string) ($request->status ?? '') !== 'approved') {
                $request->status = 'approved';
            }

            $request->forceFill([
                'intent' => $intent,
                'approved_by' => $request->approved_by ?? $actorUserId,
                'approved_at' => $request->approved_at ?? now(),
                'tenant_id' => $tenant?->id ?? $request->tenant_id,
                'user_id' => $user->id,
                'decision_note' => $decisionNote ?? $request->decision_note,
            ]);

            $shouldSend = $this->shouldSendActivationEmail($request);
            if ($shouldSend) {
                $request->forceFill([
                    'activation_email_last_attempted_at' => now(),
                    'activation_email_last_attempt_status' => 'pending',
                ]);
            }

            $request->save();

            $preferredHost = $slug !== null ? $this->hostBuilder->hostForSlug($slug) : null;

            $after = $this->auditSnapshot($request);
            $this->auditService->record(
                tenantId: $tenant?->id,
                actorUserId: $actorUserId,
                actionType: 'customer_access_request.approve',
                status: 'success',
                targetType: 'customer_access_request',
                targetId: (int) $request->id,
                context: [
                    'intent' => $intent,
                    'email' => (string) ($request->email ?? ''),
                    'requested_tenant_slug' => $slug,
                    'preferred_host' => $preferredHost,
                ],
                beforeState: $before,
                afterState: $after,
            );

            return [
                'request' => $request,
                'tenant' => $tenant,
                'user' => $user,
                'should_send' => $shouldSend,
                'preferred_host' => $preferredHost,
                'before' => $before,
                'after' => $after,
            ];
        });

        if ($result['should_send']) {
            $this->sendActivationEmail(
                request: $result['request'],
                user: $result['user'],
                tenant: $result['tenant'],
                preferredHost: $result['preferred_host'],
                actorUserId: $actorUserId,
                reason: 'approval'
            );
        }

        return $result['request']->fresh() ?? $result['request'];
    }

    public function reject(int $requestId, int $actorUserId, ?string $rejectionNote = null): CustomerAccessRequest
    {
        $actor = $this->actorOrFail($actorUserId);
        $this->assertActorAuthorized($actor);

        $rejectionNote = $this->normalizeNote($rejectionNote);

        return DB::transaction(function () use ($requestId, $actorUserId, $rejectionNote): CustomerAccessRequest {
            $request = $this->lockRequest($requestId);
            $before = $this->auditSnapshot($request);

            if ((string) ($request->status ?? '') === 'approved') {
                $this->auditService->record(
                    tenantId: (int) ($request->tenant_id ?: 0) ?: null,
                    actorUserId: $actorUserId,
                    actionType: 'customer_access_request.reject',
                    status: 'blocked',
                    targetType: 'customer_access_request',
                    targetId: (int) $request->id,
                    context: ['reason' => 'already_approved'],
                    beforeState: $before,
                    afterState: $before,
                );

                throw new DomainException('Approved access requests cannot be rejected without explicit reopen handling.');
            }

            if ($this->isRejected($request)) {
                return $request;
            }

            $request->forceFill([
                'status' => 'rejected',
                'rejected_by' => $actorUserId,
                'rejected_at' => now(),
                'rejection_note' => $rejectionNote,
            ])->save();

            $after = $this->auditSnapshot($request);
            $this->auditService->record(
                tenantId: (int) ($request->tenant_id ?: 0) ?: null,
                actorUserId: $actorUserId,
                actionType: 'customer_access_request.reject',
                status: 'success',
                targetType: 'customer_access_request',
                targetId: (int) $request->id,
                context: ['email' => (string) ($request->email ?? '')],
                beforeState: $before,
                afterState: $after,
            );

            return $request;
        });
    }

    public function resendActivation(int $requestId, int $actorUserId, ?string $decisionNote = null, int $throttleSeconds = 60): CustomerAccessRequest
    {
        $actor = $this->actorOrFail($actorUserId);
        $this->assertActorAuthorized($actor);

        $decisionNote = $this->normalizeNote($decisionNote);
        $throttleSeconds = max(5, min(600, $throttleSeconds));

        /** @var array{request:CustomerAccessRequest,tenant:?Tenant,user:User,preferred_host:?string,should_send:bool,before:array<string,mixed>,after:array<string,mixed>} $result */
        $result = DB::transaction(function () use ($requestId, $actorUserId, $decisionNote, $throttleSeconds): array {
            $request = $this->lockRequest($requestId);
            $before = $this->auditSnapshot($request);

            if ($this->isRejected($request)) {
                $this->auditService->record(
                    tenantId: (int) ($request->tenant_id ?: 0) ?: null,
                    actorUserId: $actorUserId,
                    actionType: 'customer_access_request.resend_activation',
                    status: 'blocked',
                    targetType: 'customer_access_request',
                    targetId: (int) $request->id,
                    context: ['reason' => 'rejected_request'],
                    beforeState: $before,
                    afterState: $before,
                );

                throw new DomainException('Rejected requests cannot receive activation email.');
            }

            if ((string) ($request->status ?? '') !== 'approved') {
                $this->auditService->record(
                    tenantId: (int) ($request->tenant_id ?: 0) ?: null,
                    actorUserId: $actorUserId,
                    actionType: 'customer_access_request.resend_activation',
                    status: 'blocked',
                    targetType: 'customer_access_request',
                    targetId: (int) $request->id,
                    context: ['reason' => 'not_approved'],
                    beforeState: $before,
                    afterState: $before,
                );

                throw new DomainException('Only approved requests can receive activation email resends.');
            }

            $lastSentAt = $request->getAttribute('activation_email_last_sent_at');
            if (is_string($lastSentAt) && trim($lastSentAt) !== '') {
                try {
                    $lastSentAt = Carbon::parse($lastSentAt);
                } catch (\Throwable) {
                    $lastSentAt = null;
                }
            }

            if ($lastSentAt instanceof \DateTimeInterface && ! $lastSentAt instanceof Carbon) {
                $lastSentAt = Carbon::instance($lastSentAt);
            }

            if (! $lastSentAt instanceof \DateTimeInterface) {
                $lastSentAt = null;
            }
            if ($lastSentAt && $lastSentAt->greaterThan(now()->subSeconds($throttleSeconds))) {
                return [
                    'request' => $request,
                    'tenant' => $request->tenant_id ? Tenant::query()->find((int) $request->tenant_id) : null,
                    'user' => $this->resolveUser($request, $this->normalizeIntent((string) ($request->intent ?? 'production'))),
                    'preferred_host' => null,
                    'should_send' => false,
                    'before' => $before,
                    'after' => $before,
                ];
            }

            $intent = $this->normalizeIntent((string) ($request->intent ?? 'production'));
            $slug = $this->resolveTenantSlug($request, $intent);
            $tenant = $this->resolveTenant($request, $slug);
            $user = $this->resolveUser($request, $intent);
            $preferredHost = $slug !== null ? $this->hostBuilder->hostForSlug($slug) : null;

            $request->forceFill([
                'decision_note' => $decisionNote ?? $request->decision_note,
                'activation_email_last_attempted_at' => now(),
                'activation_email_last_attempt_status' => 'pending',
            ])->save();

            $after = $this->auditSnapshot($request);
            $this->auditService->record(
                tenantId: $tenant?->id,
                actorUserId: $actorUserId,
                actionType: 'customer_access_request.resend_activation',
                status: 'success',
                targetType: 'customer_access_request',
                targetId: (int) $request->id,
                context: [
                    'email' => (string) ($request->email ?? ''),
                    'requested_tenant_slug' => $slug,
                    'preferred_host' => $preferredHost,
                ],
                beforeState: $before,
                afterState: $after,
            );

            return [
                'request' => $request,
                'tenant' => $tenant,
                'user' => $user,
                'preferred_host' => $preferredHost,
                'should_send' => true,
                'before' => $before,
                'after' => $after,
            ];
        });

        if ($result['should_send']) {
            $this->sendActivationEmail(
                request: $result['request'],
                user: $result['user'],
                tenant: $result['tenant'],
                preferredHost: $result['preferred_host'],
                actorUserId: $actorUserId,
                reason: 'resend'
            );
        }

        return $result['request']->fresh() ?? $result['request'];
    }

    protected function sendActivationEmail(
        CustomerAccessRequest $request,
        User $user,
        ?Tenant $tenant,
        ?string $preferredHost,
        int $actorUserId,
        string $reason
    ): void {
        try {
            Notification::send($user, new ApprovalPasswordSetupNotification($user, $preferredHost));

            $request->forceFill([
                'activation_email_sent_at' => $request->activation_email_sent_at ?? now(),
                'activation_email_last_sent_at' => now(),
                'activation_email_last_attempt_status' => 'sent',
                'activation_email_resend_count' => $reason === 'resend'
                    ? max(0, (int) ($request->activation_email_resend_count ?? 0)) + 1
                    : max(0, (int) ($request->activation_email_resend_count ?? 0)),
            ])->save();

            $this->auditService->record(
                tenantId: $tenant?->id,
                actorUserId: $actorUserId,
                actionType: 'customer_access_request.activation_email',
                status: 'success',
                targetType: 'customer_access_request',
                targetId: (int) $request->id,
                context: [
                    'reason' => $reason,
                    'email' => (string) ($request->email ?? ''),
                    'preferred_host' => $preferredHost,
                ],
                beforeState: null,
                afterState: null,
                result: ['mail' => 'sent'],
            );
        } catch (\Throwable $e) {
            report($e);

            $request->forceFill([
                'activation_email_last_attempt_status' => 'failed',
            ])->save();

            $this->auditService->record(
                tenantId: $tenant?->id,
                actorUserId: $actorUserId,
                actionType: 'customer_access_request.activation_email',
                status: 'failed',
                targetType: 'customer_access_request',
                targetId: (int) $request->id,
                context: [
                    'reason' => $reason,
                    'email' => (string) ($request->email ?? ''),
                    'preferred_host' => $preferredHost,
                ],
                beforeState: null,
                afterState: null,
                result: ['error' => (string) $e->getMessage()],
            );
        }
    }

    protected function lockRequest(int $requestId): CustomerAccessRequest
    {
        $request = CustomerAccessRequest::query()
            ->whereKey($requestId)
            ->lockForUpdate()
            ->first();

        if (! $request) {
            throw new DomainException('Access request not found.');
        }

        return $request;
    }

    protected function actorOrFail(int $actorUserId): User
    {
        $actor = User::query()->find($actorUserId);
        if (! $actor) {
            throw new DomainException('Approval actor is not a valid user.');
        }

        return $actor;
    }

    protected function assertActorAuthorized(User $actor): void
    {
        if (! $actor->isAdmin()) {
            throw new DomainException('Not authorized to approve customer access requests.');
        }
    }

    protected function resolveTenant(?CustomerAccessRequest $request, ?string $tenantSlug): ?Tenant
    {
        if (! $request) {
            return null;
        }

        if (is_numeric($request->tenant_id) && (int) $request->tenant_id > 0) {
            return Tenant::query()->find((int) $request->tenant_id);
        }

        if ($tenantSlug === null) {
            return null;
        }

        $tenant = Tenant::query()->where('slug', $tenantSlug)->first();
        if ($tenant) {
            return $tenant;
        }

        return Tenant::query()->create([
            'name' => (string) ($request->company ?: Str::headline($tenantSlug)),
            'slug' => $tenantSlug,
        ]);
    }

    protected function resolveUser(CustomerAccessRequest $request, string $intent): User
    {
        if (is_numeric($request->user_id) && (int) $request->user_id > 0) {
            $existing = User::query()->find((int) $request->user_id);
            if ($existing) {
                return $existing;
            }
        }

        $email = strtolower(trim((string) ($request->email ?? '')));
        if ($email !== '') {
            $existing = User::query()->where('email', $email)->first();
            if ($existing) {
                $request->forceFill(['user_id' => (int) $existing->id])->save();

                return $existing;
            }
        }

        $name = trim((string) ($request->name ?? ''));
        $name = $name !== '' ? $name : 'Customer';

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(Str::password(32)),
            'role' => 'manager',
            'is_active' => false,
            'requested_via' => 'customer_'.$intent,
            'approval_requested_at' => $request->created_at ?? Carbon::now(),
        ]);

        $request->forceFill(['user_id' => (int) $user->id])->save();

        return $user;
    }

    protected function shouldSendActivationEmail(CustomerAccessRequest $request): bool
    {
        if ($request->getAttribute('activation_email_sent_at') !== null) {
            return false;
        }

        $attemptedAt = $request->getAttribute('activation_email_last_attempted_at');
        if (is_string($attemptedAt) && trim($attemptedAt) !== '') {
            try {
                $attemptedAt = Carbon::parse($attemptedAt);
            } catch (\Throwable) {
                $attemptedAt = null;
            }
        }

        if ($attemptedAt instanceof \DateTimeInterface && ! $attemptedAt instanceof Carbon) {
            $attemptedAt = Carbon::instance($attemptedAt);
        }

        if (! $attemptedAt instanceof \DateTimeInterface) {
            $attemptedAt = null;
        }

        if ($attemptedAt && $attemptedAt->greaterThan(now()->subSeconds(60))) {
            return false;
        }

        return true;
    }

    protected function normalizeIntent(string $intent): string
    {
        $intent = strtolower(trim($intent));

        return in_array($intent, ['demo', 'production'], true) ? $intent : 'production';
    }

    protected function normalizeRequestedVia(string $existing, string $intent): string
    {
        $existing = strtolower(trim($existing));
        if ($existing !== '' && ! str_starts_with($existing, 'customer_')) {
            return $existing;
        }

        return 'customer_'.$intent;
    }

    protected function resolveTenantSlug(CustomerAccessRequest $request, string $intent): ?string
    {
        if ($intent === 'demo') {
            $candidate = (string) config('tenancy.onboarding.demo_tenant_slug', 'demo');
        } else {
            $candidate = (string) ($request->requested_tenant_slug ?? '');
        }

        $candidate = strtolower(trim($candidate));
        if ($candidate === '') {
            return null;
        }

        $candidate = preg_replace('/[^a-z0-9\\-]/', '-', $candidate);
        $candidate = trim((string) $candidate, '-');

        return $candidate !== '' ? $candidate : null;
    }

    protected function normalizeNote(?string $value): ?string
    {
        $note = trim((string) $value);
        if ($note === '') {
            return null;
        }

        return mb_substr($note, 0, 2000);
    }

    protected function isRejected(CustomerAccessRequest $request): bool
    {
        return (string) ($request->status ?? '') === 'rejected'
            || $request->rejected_at !== null
            || $request->rejected_by !== null;
    }

    /**
     * @return array<string,mixed>
     */
    protected function auditSnapshot(CustomerAccessRequest $request): array
    {
        return [
            'id' => (int) $request->id,
            'status' => (string) ($request->status ?? ''),
            'intent' => (string) ($request->intent ?? ''),
            'email' => (string) ($request->email ?? ''),
            'requested_tenant_slug' => (string) ($request->requested_tenant_slug ?? ''),
            'tenant_id' => (int) ($request->tenant_id ?: 0) ?: null,
            'user_id' => (int) ($request->user_id ?: 0) ?: null,
            'approved_by' => (int) ($request->approved_by ?: 0) ?: null,
            'approved_at' => $request->approved_at?->toIso8601String(),
            'rejected_by' => (int) ($request->rejected_by ?: 0) ?: null,
            'rejected_at' => $request->rejected_at?->toIso8601String(),
            'activation_email_sent_at' => $request->activation_email_sent_at?->toIso8601String(),
            'activation_email_last_sent_at' => $request->activation_email_last_sent_at?->toIso8601String(),
            'activation_email_resend_count' => (int) ($request->activation_email_resend_count ?? 0),
        ];
    }
}
