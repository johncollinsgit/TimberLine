<?php

namespace App\Services\Work;

use App\Models\FieldServiceJob;
use App\Models\FieldServiceTask;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItemWatcher;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class EverbranchWorkWatcherService
{
    public function add(Tenant $tenant, string $itemType, int $itemId, User $user): WorkItemWatcher
    {
        return WorkItemWatcher::query()->firstOrCreate([
            'tenant_id' => (int) $tenant->id,
            'item_type' => $itemType,
            'item_id' => $itemId,
            'user_id' => (int) $user->id,
        ]);
    }

    public function remove(Tenant $tenant, string $itemType, int $itemId, User $user): void
    {
        WorkItemWatcher::query()
            ->forTenantId((int) $tenant->id)
            ->where('item_type', $itemType)
            ->where('item_id', $itemId)
            ->where('user_id', (int) $user->id)
            ->delete();
    }

    /**
     * @return EloquentCollection<int,User>
     */
    public function watchers(Tenant $tenant, string $itemType, int $itemId): EloquentCollection
    {
        return User::query()
            ->whereHas('tenants', fn ($query) => $query->whereKey((int) $tenant->id))
            ->whereIn('id', WorkItemWatcher::query()
                ->forTenantId((int) $tenant->id)
                ->where('item_type', $itemType)
                ->where('item_id', $itemId)
                ->select('user_id'))
            ->get();
    }

    /**
     * @return EloquentCollection<int,User>
     */
    public function recipientsForJob(Tenant $tenant, FieldServiceJob $job): EloquentCollection
    {
        $users = $this->watchers($tenant, 'field_service_job', (int) $job->id);

        if ($job->assigned_user_id) {
            $assigned = $this->tenantUser($tenant, (int) $job->assigned_user_id);
            if ($assigned instanceof User && ! $users->contains('id', (int) $assigned->id)) {
                $users->push($assigned);
            }
        }

        return $users->values();
    }

    /**
     * @return EloquentCollection<int,User>
     */
    public function recipientsForTask(Tenant $tenant, FieldServiceTask $task): EloquentCollection
    {
        $users = $this->watchers($tenant, 'field_service_task', (int) $task->id);

        if ($task->assigned_user_id) {
            $assigned = $this->tenantUser($tenant, (int) $task->assigned_user_id);
            if ($assigned instanceof User && ! $users->contains('id', (int) $assigned->id)) {
                $users->push($assigned);
            }
        }

        return $users->values();
    }

    public function tenantUser(Tenant $tenant, mixed $userId): ?User
    {
        if (! is_numeric($userId)) {
            return null;
        }

        return User::query()
            ->whereKey((int) $userId)
            ->whereHas('tenants', fn ($query) => $query->whereKey((int) $tenant->id))
            ->first();
    }
}
