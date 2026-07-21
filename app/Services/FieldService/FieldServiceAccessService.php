<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceJob;
use App\Models\FieldServiceTask;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class FieldServiceAccessService
{
    public function role(User $user, Tenant|int $tenant): string
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : $tenant;

        $membership = $user->tenants()->whereKey($tenantId)->first();
        if (! $membership || $membership->pivot?->membership_active === false || (int) $membership->pivot?->membership_active === 0) {
            return '';
        }

        return strtolower(trim((string) ($membership->pivot?->role ?? '')));
    }

    public function canViewAllJobs(User $user, Tenant|int $tenant): bool
    {
        return in_array($this->role($user, $tenant), ['owner', 'tenant_owner', 'admin', 'manager'], true)
            || $user->role === 'platform_admin';
    }

    public function canManageJobs(User $user, Tenant|int $tenant): bool
    {
        return $this->canViewAllJobs($user, $tenant);
    }

    public function canUpdateProgress(User $user, Tenant $tenant, FieldServiceJob $job): bool
    {
        if ($this->canManageJobs($user, $tenant)) {
            return true;
        }

        if ((int) $job->tenant_id !== (int) $tenant->id) {
            return false;
        }

        return (int) $job->assigned_user_id === (int) $user->id
            || $job->participants()->whereKey((int) $user->id)->exists();
    }

    public function canCreateTask(User $user, Tenant $tenant, FieldServiceJob $job): bool
    {
        return $this->canManageJobs($user, $tenant) || $this->canUpdateProgress($user, $tenant, $job);
    }

    public function canUpdateTask(User $user, Tenant $tenant, FieldServiceJob $job, FieldServiceTask $task): bool
    {
        if ((int) $task->tenant_id !== (int) $tenant->id || (int) $task->field_service_job_id !== (int) $job->id) {
            return false;
        }
        if ($this->canManageJobs($user, $tenant)) {
            return true;
        }

        return $this->canUpdateProgress($user, $tenant, $job)
            && ($task->assigned_user_id === null
                || (int) $task->assigned_user_id === (int) $user->id
                || $task->assignees()->whereKey((int) $user->id)->exists());
    }

    /** @return array<string,bool> */
    public function capabilities(User $user, Tenant $tenant): array
    {
        $manage = $this->canManageJobs($user, $tenant);

        return [
            'view_all_jobs' => $this->canViewAllJobs($user, $tenant),
            'manage_jobs' => $manage,
            'create_jobs' => $manage,
            'manage_team' => $manage,
            'manage_any_task' => $manage,
            'update_participating_job_progress' => true,
        ];
    }

    public function scopeVisibleJobs(Builder $query, User $user, Tenant|int $tenant): Builder
    {
        if ($this->canViewAllJobs($user, $tenant)) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($user): void {
            $visible->where('assigned_user_id', (int) $user->id)
                ->orWhereHas('participants', fn (Builder $participants) => $participants->whereKey((int) $user->id))
                ->orWhereHas('tasks', fn (Builder $tasks) => $tasks
                    ->where('assigned_user_id', (int) $user->id)
                    ->orWhereHas('assignees', fn (Builder $assignees) => $assignees->whereKey((int) $user->id)))
                ->orWhereHas('notes.mentions', fn (Builder $mentions) => $mentions->whereKey((int) $user->id));
        });
    }

    public function canAccessJob(User $user, Tenant $tenant, FieldServiceJob $job): bool
    {
        if ((int) $job->tenant_id !== (int) $tenant->id) {
            return false;
        }

        return $this->scopeVisibleJobs(FieldServiceJob::query()->whereKey($job->id), $user, $tenant)->exists();
    }
}
