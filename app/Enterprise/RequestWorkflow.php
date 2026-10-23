<?php

namespace App\Enterprise;

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Enterprise-owned lifecycle over existing durable Teams records; no provider actions. */
class RequestWorkflow
{
    public function __construct(private WorkspaceAccess $access, private TeamWorkRepository $work, private WorkGraph $graph) {}

    public function execute(Tenant $tenant, User $user, array $input): array
    {
        return DB::transaction(function () use ($tenant, $user, $input): array {
            DB::table('team_work_locks')->insertOrIgnore(['tenant_id' => $tenant->id]);
            DB::table('team_work_locks')->where('tenant_id', $tenant->id)->lockForUpdate()->first();
            $actor = $this->access->actor($tenant, $user, true);
            abort_unless(in_array($actor['role'], ['owner', 'admin', 'member'], true), 403);
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $receipt = TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($receipt) {
                abort_unless($receipt->kind === 'enterprise.command' && hash_equals($receipt->input_hash, $hash), 409);
                if ($input['action'] === 'workspace.create') {
                    abort_unless(in_array($actor['role'], ['owner', 'admin'], true), 403);
                    $area = $this->access->area($tenant, $actor, $input['id']);

                    return ['id' => $area->id, 'replayed' => true];
                }
                $area = $this->access->area($tenant, $actor, $input['area_id'], in_array($input['action'], ['project.create', 'request.triage', 'request.assign'], true));
                if ($input['action'] === 'project.create') {
                    $project = $this->graph->read($this->work->state($tenant->id), $actor, 'project', $input['id']);

                    return ['id' => $project['id'], 'replayed' => true];
                }
                $record = $this->request($tenant, $area, $input['id']);
                if ($input['action'] === 'request.create') {
                    abort_unless(WorkspaceAccess::canSubmit($actor, $area->id), 403);
                }
                if (in_array($input['action'], ['request.progress', 'request.complete'], true)) {
                    abort_unless($this->view($tenant, $actor, $record)['can_progress'], 403);
                }

                return $this->view($tenant, $actor, $record) + ['replayed' => true];
            }

            $action = $input['action'];
            if ($action === 'workspace.create') {
                abort_unless(in_array($actor['role'], ['owner', 'admin'], true), 403);
                abort_if(TeamArea::query()->where('id', $input['id'])->exists(), 409);
                $area = TeamArea::query()->create(['id' => $input['id'], 'tenant_id' => $tenant->id, 'slug' => 'ent-'.$input['id'],
                    'name' => $input['title'], 'profile' => $input['visibility'] === 'shared' ? 'enterprise_shared' : 'enterprise_department',
                    'version' => 1, 'settings' => []]);
                // No tenant/area/project membership is created by workspace creation.
                $result = ['id' => $area->id, 'visibility' => $input['visibility']];
                $this->audit($tenant->id, $area->id, 1, $user->id, ['action' => $action, 'after' => $result + ['name' => $area->name]]);
            } else {
                $coordinate = in_array($action, ['project.create', 'request.triage', 'request.assign'], true);
                $area = $this->access->area($tenant, $actor, $input['area_id'], $coordinate);
                if ($action === 'request.create') {
                    abort_unless(WorkspaceAccess::canSubmit($actor, $area->id), 403);
                }
                if ($action === 'project.create') {
                    $command = ['kind' => 'project.create', 'id' => $input['id'], 'idempotency_key' => $input['request_id'],
                        'name' => $input['title'], 'privacy' => 'workspace', 'enterprise_area_id' => $area->id];
                    $result = $this->work->execute($tenant->id, fn () => $this->access->actor($tenant, $user, true), $command)['result'];
                } else {
                    $result = $this->changeRequest($tenant, $user, $actor, $area, $input);
                }
            }
            TeamOperationAttempt::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
                'user_id' => $user->id, 'record_id' => str_starts_with($action, 'request.') ? $input['id'] : null,
                'kind' => 'enterprise.command', 'status' => 'accepted', 'request_id' => $input['request_id'], 'input_hash' => $hash,
                'result' => ['id' => $input['id']]]);

            return $result + ['replayed' => false];
        }, 3);
    }

    private function request(Tenant $tenant, TeamArea $area, string $id): TeamOperationRecord
    {
        return TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'enterprise_request'])->findOrFail($id);
    }

    private function changeRequest(Tenant $tenant, User $user, array $actor, TeamArea $area, array $input): array
    {
        $action = $input['action'];
        $before = null;
        if ($action === 'request.create') {
            abort_if(TeamOperationRecord::query()->where('id', $input['id'])->exists(), 409);
            abort_unless($input['expected_version'] === 0, 409);
            $building = empty($input['location_id']) ? null : app(Resources::class)->requestBuilding(
                $tenant, $user, $area->id, $input['location_id'], (int) ($input['location_version'] ?? 0));
            $record = new TeamOperationRecord(['id' => $input['id'], 'tenant_id' => $tenant->id, 'area_id' => $area->id,
                'kind' => 'enterprise_request', 'source_key' => hash('sha256', 'enterprise:'.$input['id']), 'visibility' => 'area',
                'title' => $input['title'], 'status' => 'submitted', 'version' => 0,
                'starts_on' => $input['due_on'] ?? null,
                'data' => ['description' => $input['description'] ?? '', 'location' => $building?->title ?? $input['location'] ?? '',
                    'latitude' => $building ? null : ($input['latitude'] ?? null), 'longitude' => $building ? null : ($input['longitude'] ?? null), 'created_by' => (string) $user->id]
                    + ($building ? ['location_id' => $building->id, 'location_version' => $building->version] : [])]);
        } else {
            $record = $this->request($tenant, $area, $input['id']);
            abort_unless($record->version === $input['expected_version'], 409, 'This request changed. Refresh before saving.');
            $before = $this->view($tenant, $actor, $record);
        }
        $data = $record->data;
        if ($action === 'request.triage') {
            abort_unless($record->status === 'submitted', 409);
            $record->status = 'triaged';
            $data['triage_note'] = $input['note'] ?? '';
        } elseif ($action === 'request.assign') {
            abort_unless($record->status === 'triaged' && empty($data['task_id']), 409);
            $state = $this->work->state($tenant->id);
            $project = $this->graph->read($state, $actor, 'project', $input['project_id']);
            abort_unless(($project['enterprise_area_id'] ?? null) === $area->id && ! $project['archived'], 422, 'Choose a project in this workspace.');
            $assignee = (string) $input['assignee_id'];
            abort_unless(in_array($assignee, $actor['verified_user_ids'], true), 422);
            $recipient = array_replace($actor, ['user_id' => $assignee, 'role' => $actor['active_roles'][$assignee] ?? 'guest']);
            abort_unless(in_array($recipient['role'], ['owner', 'admin', 'member'], true) && WorkspaceAccess::permits($recipient, $area->id, true), 422, 'Choose an existing active workspace editor.');
            $taskId = (string) Str::uuid();
            $this->work->execute($tenant->id, fn () => $this->access->actor($tenant, $user, true),
                ['kind' => 'task.create', 'id' => $taskId, 'idempotency_key' => $input['request_id'], 'title' => $record->title,
                    'description' => $data['description'], 'assignee_id' => $assignee, 'memberships' => [$project['id'] => []],
                    'due_on' => $record->starts_on?->toDateString()]);
            $data['task_id'] = $taskId;
            $data['project_id'] = $project['id'];
            $record->assigned_user_id = $assignee;
            $record->status = 'assigned';
        } elseif (in_array($action, ['request.progress', 'request.complete'], true)) {
            abort_unless(in_array($record->status, ['assigned', 'in_progress'], true), 409);
            abort_unless(WorkspaceAccess::permits($actor, $area->id, true)
                && ((string) $record->assigned_user_id === $actor['user_id'] || WorkspaceAccess::permits($actor, $area->id, true, true)), 403);
            $task = $this->graph->read($this->work->state($tenant->id), $actor, 'task', $data['task_id']);
            abort_unless($this->graph->can($this->work->state($tenant->id), $actor, 'task', $task['id'], true), 403);
            if ($action === 'request.complete') {
                if (config('enterprise.operations_enabled')) {
                    app(Operations::class)->assertApproved($tenant, $user, $task['id']);
                } else {
                    $this->work->execute($tenant->id, fn () => $this->access->actor($tenant, $user, true), ['kind' => 'task.complete',
                        'id' => $task['id'], 'idempotency_key' => $input['request_id'], 'expected_version' => $task['version'], 'completed' => true]);
                }
                $record->status = 'completed';
            } else {
                $record->status = 'in_progress';
            }
            // Completion without a replacement note must retain saved progress.
            if ($action === 'request.progress' || trim($input['note'] ?? '') !== '') {
                $data['progress_note'] = $input['note'] ?? '';
            }
        }
        $record->data = $data;
        $record->version++;
        $record->save();
        $result = $this->view($tenant, $actor, $record);
        $auditAfter = $result;
        // Audit request-owned content only; historical graph projections can contain revoked references.
        unset($auditAfter['task'], $auditAfter['can_coordinate'], $auditAfter['can_progress']);
        if ($before !== null) {
            unset($before['task'], $before['can_coordinate'], $before['can_progress']);
        }
        $this->audit($tenant->id, $record->id, $record->version, $user->id, ['action' => $action, 'before' => $before, 'after' => $auditAfter]);

        return $result;
    }

    public function view(Tenant $tenant, array $actor, TeamOperationRecord $record): array
    {
        $this->access->area($tenant, $actor, $record->area_id);
        $data = $record->data;
        $task = null;
        if (! empty($data['task_id'])) {
            $state = $this->work->state($tenant->id);
            abort_unless($this->graph->can($state, $actor, 'task', $data['task_id']), 404);
            $task = $this->graph->read($state, $actor, 'task', $data['task_id']);
        }

        return ['id' => $record->id, 'area_id' => $record->area_id, 'title' => $record->title, 'version' => $record->version,
            'status' => $task && $task['completed_at'] !== null ? 'completed' : ($record->status === 'completed' && $task ? 'assigned' : $record->status),
            'due_on' => $record->starts_on?->toDateString(), 'data' => $data, 'task' => $task,
            'can_coordinate' => WorkspaceAccess::permits($actor, $record->area_id, true, true),
            'can_progress' => WorkspaceAccess::permits($actor, $record->area_id, true)
                && ((string) $record->assigned_user_id === $actor['user_id'] || WorkspaceAccess::permits($actor, $record->area_id, true, true))];
    }

    private function audit(int $tenantId, string $id, int $version, int $userId, array $event): void
    {
        DB::table('team_operation_revisions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'record_id' => $id,
            'version' => $version, 'user_id' => $userId, 'payload' => Crypt::encryptString(json_encode($event, JSON_THROW_ON_ERROR)), 'created_at' => now()]);
    }
}
