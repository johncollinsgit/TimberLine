<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\TenantWorkspaceChangeRequest;
use App\Models\User;
use App\Services\Onboarding\TenantSetupStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class TenantWorkspaceChangeRequestService
{
    public function __construct(
        protected TenantBlueprintProfileService $blueprints,
        protected TenantSetupStatusService $setupStatuses,
    ) {}

    /** @return array<string,mixed> */
    public function requestValidationRules(): array
    {
        return [
            'requested_template_key' => ['required', 'string', Rule::in(array_keys($this->blueprints->templateOptions()))],
            'custom_business_type' => ['nullable', 'string', 'max:120', 'required_if:requested_template_key,custom'],
            'business_description' => ['nullable', 'string', 'max:500'],
            'request_note' => ['required', 'string', 'max:2000'],
        ];
    }

    public function canManageWorkspace(User $user, Tenant $tenant): bool
    {
        $role = strtolower(trim((string) ($user->tenants()->whereKey($tenant->id)->first()?->pivot?->role ?? '')));

        return in_array($role, ['owner', 'tenant_owner', 'admin'], true);
    }

    public function pendingForTenant(Tenant $tenant): ?TenantWorkspaceChangeRequest
    {
        return TenantWorkspaceChangeRequest::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', TenantWorkspaceChangeRequest::STATUS_PENDING)
            ->latest('id')
            ->first();
    }

    /** @param array<string,mixed> $input */
    public function request(Tenant $tenant, User $user, array $input): TenantWorkspaceChangeRequest
    {
        if (! $this->canManageWorkspace($user, $tenant)) {
            throw new RuntimeException('Only a workspace owner can request this change.');
        }

        return DB::transaction(function () use ($tenant, $user, $input): TenantWorkspaceChangeRequest {
            // Lock the tenant row while checking, so two owners cannot create
            // concurrent pending requests for the same workspace.
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $existing = $this->pendingForTenant($tenant);
            if ($existing instanceof TenantWorkspaceChangeRequest) {
                throw new RuntimeException('A workspace change request is already awaiting review.');
            }

            $templateKey = strtolower(trim((string) $input['requested_template_key']));
            $preview = $this->blueprints->blueprintFromInput([
                'business_template' => $templateKey,
                'custom_business_type' => $input['custom_business_type'] ?? null,
                'business_description' => $input['business_description'] ?? null,
            ]);

            return TenantWorkspaceChangeRequest::query()->create([
                'tenant_id' => $tenant->id,
                'requested_by_user_id' => $user->id,
                'requested_template_key' => $templateKey,
                'requested_context' => [
                    'custom_business_type' => $preview['custom_business_type'] ?? null,
                    'business_description' => $preview['business_description'] ?? null,
                    'suggested_workspace_profile' => $preview['workspace_profile'] ?? 'generic_custom',
                ],
                'request_note' => trim((string) $input['request_note']),
                'status' => TenantWorkspaceChangeRequest::STATUS_PENDING,
                'requested_at' => now(),
            ]);
        });
    }

    public function cancel(TenantWorkspaceChangeRequest $request, User $user, Tenant $tenant): void
    {
        if ((int) $request->tenant_id !== (int) $tenant->id || ! $this->canManageWorkspace($user, $tenant)) {
            throw new RuntimeException('You cannot cancel this workspace change request.');
        }

        if ($request->status !== TenantWorkspaceChangeRequest::STATUS_PENDING) {
            throw new RuntimeException('Only a pending request can be cancelled.');
        }

        $request->forceFill([
            'status' => TenantWorkspaceChangeRequest::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();
    }

    /** @param array<string,mixed> $input */
    public function approve(TenantWorkspaceChangeRequest $request, User $operator, array $input): void
    {
        if ($request->status !== TenantWorkspaceChangeRequest::STATUS_PENDING) {
            throw new RuntimeException('Only a pending request can be approved.');
        }

        DB::transaction(function () use ($request, $operator, $input): void {
            $tenant = Tenant::query()->with('accessProfile')->findOrFail($request->tenant_id);
            $existing = $this->blueprints->payloadForTenant($tenant);
            $context = (array) $request->requested_context;
            $profile = $tenant->accessProfile ?: $tenant->accessProfile()->create([
                'plan_key' => 'base',
                'operating_mode' => 'custom_or_unknown',
                'source' => 'workspace_change_request',
            ]);
            $setupStatus = $this->setupStatuses->forTenant($tenant);
            $blueprintInput = array_merge($existing, [
                'business_template' => $request->requested_template_key,
                'workspace_profile' => $input['workspace_profile'],
                'capability_packs' => $input['capability_packs'] ?? [],
                'custom_business_type' => $context['custom_business_type'] ?? ($existing['custom_business_type'] ?? null),
                'business_description' => $context['business_description'] ?? ($existing['business_description'] ?? null),
                'blueprint_review_status' => 'reviewed',
                'blueprint_internal_notes' => $existing['blueprint_internal_notes'] ?? null,
                'blueprint_next_action' => $existing['blueprint_next_action'] ?? null,
            ]);

            // blueprintFromInput expects the planning preferences at the top
            // level. Preserve them when a reviewer changes only the template
            // and approved capability boundary.
            foreach ((array) ($existing['work_management_intent'] ?? []) as $key => $value) {
                $blueprintInput[(string) $key] = (bool) $value;
            }

            $this->blueprints->updateBlueprint($tenant, $profile, $setupStatus, $blueprintInput, $operator);

            $request->forceFill([
                'status' => TenantWorkspaceChangeRequest::STATUS_APPROVED,
                'reviewed_by_user_id' => $operator->id,
                'decision_note' => trim((string) ($input['decision_note'] ?? '')) ?: null,
                'reviewed_at' => now(),
            ])->save();
        });
    }

    public function decline(TenantWorkspaceChangeRequest $request, User $operator, ?string $note): void
    {
        if ($request->status !== TenantWorkspaceChangeRequest::STATUS_PENDING) {
            throw new RuntimeException('Only a pending request can be declined.');
        }

        $request->forceFill([
            'status' => TenantWorkspaceChangeRequest::STATUS_DECLINED,
            'reviewed_by_user_id' => $operator->id,
            'decision_note' => trim((string) $note) ?: null,
            'reviewed_at' => now(),
        ])->save();
    }
}
