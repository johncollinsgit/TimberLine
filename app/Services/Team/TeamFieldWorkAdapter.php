<?php

namespace App\Services\Team;

use App\Models\FieldServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\Tenancy\TenantModuleAccessResolver;

/** References existing Field jobs; never copies or mutates Field tasks, notes or jobs. */
class TeamFieldWorkAdapter
{
    public function __construct(private TeamWorkAccess $access, private TeamWorkRepository $repository, private WorkGraph $graph, private FieldServiceAccessService $fieldAccess) {}

    private function fieldEnabled(Tenant $tenant): bool
    {
        // Resolve current canonical state, not a captured resolver's prior entitlement cache.
        return app(TenantModuleAccessResolver::class)->canAccess((int) $tenant->id, 'field_service');
    }

    private function job(User $user, Tenant $tenant, int $jobId): FieldServiceJob
    {
        abort_unless($this->fieldEnabled($tenant), 403);
        $job = FieldServiceJob::query()->forTenantId((int) $tenant->id)->notGeneratedQuickBooksInvoice()->findOrFail($jobId);
        abort_unless($this->fieldAccess->canAccessJob($user, $tenant, $job), 404);

        return $job;
    }

    public function link(User $user, Tenant $tenant, string $taskId, int $jobId, array $input): array
    {
        $this->access->context($user, $tenant);

        return $this->repository->execute((int) $tenant->id, function () use ($user, $tenant, $jobId) {
            $actor = $this->access->context($user, $tenant);
            $this->job($user, $tenant, $jobId); // Both object families rechecked inside the Team transaction.
            $actor['accessible_field_job_ids'] = [(string) $jobId];

            return $actor;
        }, ['kind' => 'task.field_link', 'id' => $taskId, 'job_id' => (string) $jobId,
            'expected_version' => $input['expected_version'], 'idempotency_key' => $input['idempotency_key'], 'remove' => $input['remove'] ?? false]);
    }

    public function jobsForTask(User $user, Tenant $tenant, string $taskId): array
    {
        $actor = $this->access->context($user, $tenant);
        $state = $this->repository->state((int) $tenant->id);
        abort_unless($this->graph->can($state, $actor, 'task', $taskId), 404);
        if (! $this->fieldEnabled($tenant)) {
            return [];
        }
        $ids = array_keys($state['tasks'][$taskId]['field_job_ids']);
        $query = FieldServiceJob::query()->forTenantId((int) $tenant->id)->notGeneratedQuickBooksInvoice()->whereIn('id', $ids);
        $this->fieldAccess->scopeVisibleJobs($query, $user, $tenant);

        return $query->orderBy('id')->get(['id', 'title', 'operational_status'])->map(fn ($job) => [
            'id' => (int) $job->id, 'title' => $job->title, 'status' => $job->operational_status,
            'destination' => ['kind' => 'field_service_job', 'id' => (int) $job->id],
        ])->all();
    }

    public function tasksForJob(User $user, Tenant $tenant, int $jobId, int $page = 1): array
    {
        abort_unless($page >= 1, 422);
        $actor = $this->access->context($user, $tenant);
        $this->job($user, $tenant, $jobId);
        $state = $this->repository->state((int) $tenant->id);
        $tasks = [];
        foreach ($state['tasks'] as $task) {
            if (isset($task['field_job_ids'][(string) $jobId]) && $this->graph->can($state, $actor, 'task', $task['id'])) {
                $tasks[] = ['id' => $task['id'], 'title' => $task['title'], 'completed_at' => $task['completed_at'],
                    'due_on' => $task['due_on'], 'due_at' => $task['due_at'], 'version' => $task['version'],
                    'can_edit' => $this->graph->can($state, $actor, 'task', $task['id'], true)];
            }
        }
        usort($tasks, fn ($a, $b) => $a['id'] <=> $b['id']);

        return ['tasks' => array_slice($tasks, ($page - 1) * 50, 50), 'page' => $page, 'has_more' => count($tasks) > $page * 50,
            'sharing' => 'A job reference does not grant task or project access.'];
    }
}
