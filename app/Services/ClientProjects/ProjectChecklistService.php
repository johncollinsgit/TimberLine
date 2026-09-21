<?php

namespace App\Services\ClientProjects;

use App\Models\ClientProject;
use App\Models\ClientProjectTicketTask;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProjectChecklistService
{
    public function projects(Tenant $tenant)
    {
        return ClientProject::query()->forTenantId((int) $tenant->id)
            ->where('metadata->checklist_enabled', true)
            ->with(['tickets' => fn ($q) => $q->where('tenant_id', $tenant->id)->where('customer_visible', true)
                ->orderBy('id')->with(['tasks' => fn ($tasks) => $tasks->where('tenant_id', $tenant->id)])])
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    public function isOperator(User $user): bool
    {
        // Tenant admins are not automatically Evergrove operators.
        $emails = array_map(fn ($email) => strtolower(trim((string) $email)), (array) config('tenancy.landlord.operator_emails', []));

        return $user->is_active && Gate::forUser($user)->allows('manage-landlord-commercial')
            && ($user->role === 'platform_admin' || in_array(strtolower($user->email), $emails, true));
    }

    public function assertMembership(User $user, Tenant $tenant): void
    {
        $membership = $user->tenants()->whereKey($tenant->id)->first();
        abort_unless($membership && $membership->pivot->membership_active
            && in_array($membership->pivot->role, ['admin', 'owner', 'tenant_owner', 'manager', 'marketing_manager'], true), 403);
    }

    public function setComplete(Tenant $tenant, User $user, int $taskId, bool $complete): void
    {
        $this->assertMembership($user, $tenant);
        DB::transaction(function () use ($tenant, $user, $taskId, $complete): void {
            $task = ClientProjectTicketTask::query()->forTenantId((int) $tenant->id)->lockForUpdate()->findOrFail($taskId);
            $ticket = $task->ticket()->where('tenant_id', $tenant->id)->where('customer_visible', true)->firstOrFail();
            $ticket->project()->where('tenant_id', $tenant->id)->where('metadata->checklist_enabled', true)->firstOrFail();
            abort_unless($task->owner_type === 'client' || $this->isOperator($user), 403);
            $status = $complete ? 'done' : 'open';
            if ($task->status === $status) {
                return;
            }
            $before = $task->only(['status', 'completed_at']);
            $task->update(['status' => $status, 'completed_at' => $complete ? now() : null]);
            app(LandlordOperatorActionAuditService::class)->record(
                tenantId: (int) $tenant->id,
                actorUserId: (int) $user->id,
                actionType: 'client_project.checklist_updated',
                targetType: 'client_project_ticket_task',
                targetId: $task->id,
                beforeState: $before,
                afterState: $task->only(['status', 'completed_at']),
            );
        });
    }
}
