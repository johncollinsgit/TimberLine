<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceJob;
use App\Models\FieldServiceTask;
use App\Models\FieldServiceTaskEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FieldServiceTaskAssignmentService
{
    /** @param array<int,int|string> $ids
     * @return Collection<int,int>
     */
    public function tenantUserIds(Tenant $tenant, array $ids): Collection
    {
        return $tenant->users()
            ->wherePivot('membership_active', true)
            ->whereIn('users.id', collect($ids)->filter(fn ($id): bool => is_numeric($id))->map(fn ($id): int => (int) $id))
            ->pluck('users.id')->map(fn ($id): int => (int) $id)->unique()->values();
    }

    /** @param array<int,int|string> $ids */
    public function sync(FieldServiceTask $task, Tenant $tenant, User $actor, array $ids): FieldServiceTask
    {
        $userIds = $this->tenantUserIds($tenant, $ids);
        $pivot = $userIds->mapWithKeys(fn (int $id): array => [$id => [
            'tenant_id' => (int) $tenant->id,
            'assigned_by_user_id' => (int) $actor->id,
        ]])->all();

        $task->assignees()->sync($pivot);
        $task->forceFill(['assigned_user_id' => $userIds->first()])->save();

        return $task->load('assignees:id,name,email');
    }

    /** @return array<string,mixed> */
    public function handoff(
        FieldServiceTask $task,
        FieldServiceJob $job,
        Tenant $tenant,
        User $actor,
        array $recipientIds,
        ?string $note,
        string $idempotencyKey,
    ): array {
        $existing = FieldServiceTaskEvent::query()->forTenantId((int) $tenant->id)
            ->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return ['task' => $task->load('assignees:id,name,email'), 'event' => $existing, 'replayed' => true];
        }

        $recipients = $this->tenantUserIds($tenant, $recipientIds);
        abort_if($recipients->isEmpty(), 422, 'Choose at least one active workspace member.');

        return DB::transaction(function () use ($task, $job, $tenant, $actor, $recipients, $note, $idempotencyKey): array {
            $fromIds = $task->assignees()->pluck('users.id')->map(fn ($id): int => (int) $id)->values()->all();
            $fromStatus = $task->status;
            $this->sync($task, $tenant, $actor, $recipients->all());
            $task->forceFill([
                'status' => 'waiting',
                'completed_at' => null,
                'completed_by_user_id' => null,
            ])->save();

            $event = FieldServiceTaskEvent::query()->create([
                'tenant_id' => (int) $tenant->id,
                'field_service_task_id' => (int) $task->id,
                'actor_user_id' => (int) $actor->id,
                'event_type' => 'handoff',
                'from_status' => $fromStatus,
                'to_status' => 'waiting',
                'note' => $note,
                'idempotency_key' => $idempotencyKey,
                'metadata' => ['job_id' => (int) $job->id, 'from_assignee_ids' => $fromIds, 'to_assignee_ids' => $recipients->all()],
            ]);

            return ['task' => $task->fresh()->load('assignees:id,name,email'), 'event' => $event, 'replayed' => false];
        });
    }

    /** @return array<string,mixed> */
    public function payload(FieldServiceTask $task): array
    {
        $task->loadMissing('assignees:id,name,email');

        return [
            'id' => (int) $task->id,
            'job_id' => (int) $task->field_service_job_id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status === 'blocked' ? 'waiting' : $task->status,
            'priority' => $task->priority ?: 'normal',
            'due_at' => $task->due_at?->toIso8601String(),
            'assignees' => $task->assignees->map(fn (User $assignee): array => [
                'id' => (int) $assignee->id,
                'name' => (string) ($assignee->name ?: $assignee->email),
                'email' => (string) $assignee->email,
            ])->values(),
            'assigned_user_id' => $task->assigned_user_id,
            'assigned_to' => $task->assignees->pluck('name')->filter()->join(', ') ?: $task->assignedUser?->name,
            'completed_at' => $task->completed_at?->toIso8601String(),
        ];
    }
}
