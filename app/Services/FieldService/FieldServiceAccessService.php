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
        $role = $this->role($user, $tenant);

        return $user->is_active !== false && $role !== '';
    }

    public function canManageJobs(User $user, Tenant|int $tenant): bool
    {
        return in_array($this->role($user, $tenant), ['owner', 'tenant_owner', 'admin', 'manager'], true)
            || $user->role === 'platform_admin';
    }

    public function canCreateJobs(User $user, Tenant|int $tenant): bool
    {
        return $user->is_active !== false && $this->role($user, $tenant) !== '';
    }

    public function canUpdateProgress(User $user, Tenant $tenant, FieldServiceJob $job): bool
    {
        if ($this->canManageJobs($user, $tenant)) {
            return true;
        }

        if ((int) $job->tenant_id !== (int) $tenant->id) {
            return false;
        }

        return $user->is_active !== false && $this->role($user, $tenant) !== '';
    }

    public function scopeAssignedJobs(Builder $query, User $user): Builder
    {
        // Crew membership describes who is working on a job, not who may see it.
        return $user->is_active !== false ? $query : $query->whereRaw('1 = 0');
    }

    public function canClockJob(User $user, Tenant $tenant, FieldServiceJob $job): bool
    {
        return $this->canUpdateProgress($user, $tenant, $job);
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

        return $this->canUpdateProgress($user, $tenant, $job);
    }

    /** @return array<string,bool> */
    public function capabilities(User $user, Tenant $tenant): array
    {
        $manage = $this->canManageJobs($user, $tenant);

        return [
            'view_all_jobs' => $this->canViewAllJobs($user, $tenant),
            'manage_jobs' => $manage,
            'create_jobs' => $this->canCreateJobs($user, $tenant),
            'edit_jobs' => $this->canCreateJobs($user, $tenant),
            'delete_jobs' => $this->canCreateJobs($user, $tenant),
            'restore_jobs' => $manage,
            'edit_customer' => $manage && app(CollinsClientAccessService::class)->allows($user, $tenant),
            'manage_team' => $manage,
            'manage_any_task' => $manage,
            'update_participating_job_progress' => true,
        ];
    }

    public function scopeVisibleJobs(Builder $query, User $user, Tenant|int $tenant): Builder
    {
        $query->whereNull('metadata->recycled_at');
        if ($this->canViewAllJobs($user, $tenant)) {
            return $query;
        }

        return $query->whereRaw('1 = 0');
    }

    public function canAccessJob(User $user, Tenant $tenant, FieldServiceJob $job): bool
    {
        if ((int) $job->tenant_id !== (int) $tenant->id) {
            return false;
        }

        return $this->scopeVisibleJobs(FieldServiceJob::query()->whereKey($job->id), $user, $tenant)->exists();
    }
}
