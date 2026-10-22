<?php

namespace App\Enterprise;

use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamFileService;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use Illuminate\Support\Str;

class Discussion
{
    public function __construct(private WorkspaceAccess $access, private RecordStore $store, private TeamWorkRepository $work, private WorkGraph $graph, private TeamFileService $files) {}

    public function resolve(Tenant $tenant, User $user, string $kind, string $id, bool $create = false): array
    {
        abort_unless(config('enterprise.collaboration_enabled'), 404);
        $actor = $this->access->actor($tenant, $user, $create);
        $state = $this->work->state($tenant->id);
        if ($kind === 'request') {
            $record = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => 'enterprise_request'])->findOrFail($id);
            app(RequestWorkflow::class)->view($tenant, $actor, $record);
            $taskId = $record->data['task_id'] ?? null;
            abort_if($create && ! $taskId, 409, 'Assign this request to a task before adding discussion or files.');
        } else {
            abort_unless($this->graph->can($state, $actor, 'project', $id), 404);
            $project = $this->graph->read($state, $actor, 'project', $id);
            $areaId = $project['enterprise_area_id'] ?? '';
            $this->access->area($tenant, $actor, $areaId);
            $thread = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId, 'kind' => 'ent_project_thread',
                'source_key' => hash('sha256', 'ent-discussion:'.$id)])->first();
            $taskId = $thread?->data['task_id'];
            if ($create && ! $thread) {
                abort_unless($this->graph->can($state, $actor, 'project', $id, true) && ! $project['archived'], 403);
                $taskId = (string) Str::uuid();
                $this->work->execute($tenant->id, fn () => $this->access->actor($tenant, $user, true), ['kind' => 'task.create',
                    'id' => $taskId, 'idempotency_key' => $taskId, 'title' => 'Project discussion', 'memberships' => [$id => []], 'enterprise_discussion_project' => $id]);
                $thread = TeamOperationRecord::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $areaId,
                    'kind' => 'ent_project_thread', 'source_key' => hash('sha256', 'ent-discussion:'.$id), 'visibility' => 'area',
                    'title' => 'Project discussion', 'status' => 'active', 'version' => 1, 'data' => ['project_id' => $id, 'task_id' => $taskId]]);
                $this->store->audit($thread, $user->id, 'discussion.open');
                $state = $this->work->state($tenant->id);
            }
        }
        abort_if($taskId && ! $this->graph->can($state, $actor, 'task', $taskId), 404);
        $task = $taskId ? $this->graph->read($state, $actor, 'task', $taskId) : null;
        if ($create) {
            abort_unless($task && $this->graph->can($state, $actor, 'task', $taskId, true), 403);
        }

        return ['task' => $task, 'can_write' => $task ? $this->graph->can($state, $actor, 'task', $taskId, true)
            : ($kind === 'project' && $this->graph->can($state, $actor, 'project', $id, true) && ! $project['archived'])];
    }

    public function open(Tenant $tenant, User $user, string $kind, string $id): array
    {
        return $this->store->locked($tenant, fn () => $this->resolve($tenant, $user, $kind, $id, true));
    }

    public function show(Tenant $tenant, User $user, string $kind, string $id): array
    {
        $result = $this->resolve($tenant, $user, $kind, $id);
        $task = $result['task'];
        $comments = array_values(array_filter($task['comments'] ?? [], fn ($row) => $row['deleted_at'] === null));

        return ['task_id' => $task['id'] ?? null, 'can_write' => $result['can_write'],
            'comments' => array_slice(array_map(fn ($row) => array_intersect_key($row, array_flip(['id', 'author_id', 'body', 'created_at'])), $comments), -100),
            'files' => $task ? $this->files->listing($tenant, $user, $task['id']) : [],
            'coverage' => 'Latest 100 notes. Files inherit this native task and department visibility; confidential contracts need a separate future document lane.'];
    }

    public function comment(Tenant $tenant, User $user, string $kind, string $id, string $body, string $requestId): array
    {
        return $this->store->locked($tenant, function () use ($tenant, $user, $kind, $id, $body, $requestId): array {
            $task = $this->resolve($tenant, $user, $kind, $id, true)['task'];
            $hash = hash('sha256', json_encode([$kind, $id, $body], JSON_THROW_ON_ERROR));
            $receipt = TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $requestId])->first();
            if ($receipt) {
                abort_unless($receipt->kind === 'ent.discussion' && hash_equals($receipt->input_hash, $hash), 409);

                return ['saved' => true, 'replayed' => true];
            }
            $result = $this->work->execute($tenant->id, fn () => $this->access->actor($tenant, $user, true),
                ['kind' => 'task.comment', 'id' => $task['id'], 'comment_id' => $requestId, 'idempotency_key' => $requestId,
                    'expected_version' => $task['version'], 'body' => $body]);
            TeamOperationAttempt::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $task['enterprise_area_id'],
                'record_id' => null, 'user_id' => $user->id, 'kind' => 'ent.discussion', 'status' => 'accepted', 'request_id' => $requestId,
                'input_hash' => $hash, 'result' => ['task_id' => $task['id']]]);

            return ['saved' => true, 'replayed' => $result['replayed']];
        });
    }
}
