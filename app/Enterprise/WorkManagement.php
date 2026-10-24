<?php

namespace App\Enterprise;

use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/** Explicit, scoped work editing over the existing native graph and request lifecycle. */
class WorkManagement
{
    public const ACTIONS = ['job.create', 'project.update', 'project.archive', 'task.create', 'task.update', 'task.delete', 'request.plan'];

    public function __construct(private WorkspaceAccess $access, private TeamWorkRepository $work, private WorkGraph $graph, private RequestWorkflow $requests) {}

    public static function rules(): array
    {
        return [
            'action' => ['required', 'in:'.implode(',', self::ACTIONS)],
            'id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'], 'area_id' => ['required', 'uuid'],
            'expected_version' => ['exclude_if:action,job.create,task.create', 'required_unless:action,job.create,task.create', 'integer', 'min:1'],
            'title' => ['required_if:action,job.create,project.update,task.create,task.update', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'], 'due_on' => ['nullable', 'date_format:Y-m-d'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'], 'request_type' => ['sometimes', 'in:'.implode(',', array_keys(RequestWorkflow::TYPES))],
            'assignee_id' => ['required_if:action,job.create,request.plan', 'nullable', 'integer', 'min:1'],
            'project_id' => ['required_if:action,task.create,request.plan', 'uuid'],
            'target_area_id' => ['required_if:action,request.plan', 'uuid'],
            'location_id' => ['nullable', 'required_with:location_version', 'uuid'], 'location_version' => ['nullable', 'required_with:location_id', 'integer', 'min:1'],
            'location' => ['nullable', 'string', 'max:200'], 'task_title' => ['nullable', 'string', 'max:100'],
            'archived' => ['required_if:action,project.archive', 'boolean'],
            'related_request_id' => ['nullable', 'required_with:request_version', 'uuid'], 'request_version' => ['nullable', 'required_with:related_request_id', 'integer', 'min:1'],
            'acknowledge_routing' => ['required_if:action,request.plan', 'accepted_if:action,request.plan'],
            'acknowledge_removal' => ['required_if:action,task.delete', 'accepted_if:action,task.delete'],
        ];
    }

    public function execute(Tenant $tenant, User $user, array $input): array
    {
        return DB::transaction(function () use ($tenant, $user, $input): array {
            DB::table('team_work_locks')->insertOrIgnore(['tenant_id' => $tenant->id]);
            DB::table('team_work_locks')->where('tenant_id', $tenant->id)->lockForUpdate()->first();
            $actor = $this->access->actor($tenant, $user, true);
            $area = $this->access->area($tenant, $actor, $input['area_id'], true);
            $target = $input['action'] === 'request.plan' ? $this->access->area($tenant, $actor, $input['target_area_id'], true) : $area;
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $receipt = TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($receipt) {
                abort_unless($receipt->kind === 'enterprise.work' && hash_equals($receipt->input_hash, $hash), 409);
                $result = $receipt->result;
                abort_unless(($result['area_id'] ?? null) === $target->id, 409);
                $state = $this->work->state($tenant->id);
                $project = $this->graph->read($state, $actor, 'project', $result['project_id']);
                abort_unless(($project['enterprise_area_id'] ?? null) === $target->id, 404);
                if (! empty($result['ticket_id'])) {
                    $this->requests->view($tenant, $actor, $this->request($tenant, $target->id, $result['ticket_id']));
                } elseif (! empty($result['task_id'])) {
                    $visible = $state;
                    if ($input['action'] === 'task.delete' && isset($visible['tasks'][$result['task_id']])) {
                        $visible['tasks'][$result['task_id']]['deleted'] = false;
                    }
                    $task = $this->graph->read($visible, $actor, 'task', $result['task_id']);
                    abort_unless(($task['enterprise_area_id'] ?? null) === $target->id && isset($task['memberships'][$project['id']]), 404);
                }

                return $result + ['replayed' => true];
            }
            $action = $input['action'];
            if ($action === 'job.create') {
                $project = $this->native($tenant, $user, ['kind' => 'project.create', 'id' => $input['id'], 'idempotency_key' => $input['request_id'],
                    'name' => $input['title'], 'description' => $input['description'] ?? '', 'privacy' => 'workspace', 'enterprise_area_id' => $area->id, 'due_on' => $input['due_on'] ?? null]);
                $ticketId = $this->stable($tenant, $input['request_id'], 'ticket');
                $ticket = $this->requests->execute($tenant, $user, ['action' => 'request.create', 'id' => $ticketId,
                    'request_id' => $this->stable($tenant, $input['request_id'], 'intake'), 'area_id' => $area->id, 'expected_version' => 0,
                    'title' => $input['task_title'] ?? $input['title'], 'description' => $input['description'] ?? '', 'due_on' => $input['due_on'] ?? null,
                    'request_type' => $input['request_type'] ?? 'general', 'priority' => $input['priority'] ?? 'normal',
                    'location' => $input['location'] ?? '', 'location_id' => $input['location_id'] ?? null, 'location_version' => $input['location_version'] ?? null]);
                $result = $this->assign($tenant, $user, $ticket, $project['id'], $input);
            } elseif ($action === 'request.plan') {
                $record = $this->request($tenant, $area->id, $input['id']);
                abort_unless($record->version === (int) $input['expected_version'] && in_array($record->status, ['submitted', 'triaged'], true)
                    && empty($record->data['task_id']), 409, 'Only a current incoming ticket can be routed. Refresh before reviewing it again.');
                $project = $this->project($tenant, $actor, $target->id, $input['project_id']);
                abort_if($project['archived'], 422, 'Choose an active project.');
                $building = empty($input['location_id']) ? null : app(Resources::class)->requestBuilding($tenant, $user, $target->id, $input['location_id'], (int) $input['location_version']);
                $before = $this->requests->view($tenant, $actor, $record);
                $data = $record->data;
                // A routing preview explicitly selects the destination location. Never retain a foreign department's saved link.
                unset($data['location_id'], $data['location_version'], $data['latitude'], $data['longitude']);
                $data['location'] = $building?->title ?? ($input['location'] ?? '');
                if ($building) {
                    $data += ['location_id' => $building->id, 'location_version' => $building->version];
                }
                $record->area_id = $target->id;
                $record->data = $data;
                $record->version++;
                $record->save();
                $this->audit($tenant, $user, $record, 'request.routed', $before);
                $result = $this->assign($tenant, $user, $this->requests->view($tenant, $actor, $record), $project['id'], $input);
            } elseif (str_starts_with($action, 'project.')) {
                $project = $this->project($tenant, $actor, $area->id, $input['id']);
                $command = ['kind' => $action, 'id' => $project['id'], 'idempotency_key' => $input['request_id'], 'expected_version' => (int) $input['expected_version']];
                if ($action === 'project.archive') {
                    $command['archived'] = (bool) $input['archived'];
                } else {
                    $command['changes'] = ['name' => $input['title'], 'description' => $input['description'] ?? '', 'due_on' => $input['due_on'] ?? null];
                }
                $this->native($tenant, $user, $command);
                $result = ['area_id' => $area->id, 'project_id' => $project['id']];
            } else {
                $result = $this->task($tenant, $user, $actor, $area->id, $input);
            }
            TeamOperationAttempt::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $target->id, 'user_id' => $user->id,
                'kind' => 'enterprise.work', 'status' => 'accepted', 'request_id' => $input['request_id'], 'input_hash' => $hash, 'result' => $result]);

            return $result + ['replayed' => false];
        }, 3);
    }

    private function assign(Tenant $tenant, User $user, array $ticket, string $projectId, array $input): array
    {
        if ($ticket['status'] === 'submitted') {
            $ticket = $this->requests->execute($tenant, $user, ['action' => 'request.triage', 'id' => $ticket['id'], 'area_id' => $ticket['area_id'],
                'request_id' => $this->stable($tenant, $input['request_id'], 'triage'), 'expected_version' => $ticket['version'], 'note' => 'Reviewed through the assignment wizard.']);
        }
        $ticket = $this->requests->execute($tenant, $user, ['action' => 'request.assign', 'id' => $ticket['id'], 'area_id' => $ticket['area_id'],
            'request_id' => $this->stable($tenant, $input['request_id'], 'assign'), 'expected_version' => $ticket['version'], 'project_id' => $projectId, 'assignee_id' => (string) $input['assignee_id']]);

        return ['area_id' => $ticket['area_id'], 'project_id' => $projectId, 'ticket_id' => $ticket['id'], 'task_id' => $ticket['data']['task_id']];
    }

    private function task(Tenant $tenant, User $user, array $actor, string $area, array $input): array
    {
        $state = $this->work->state($tenant->id);
        $action = $input['action'];
        if ($action === 'task.create') {
            $project = $this->project($tenant, $actor, $area, $input['project_id']);
            abort_if($project['archived'], 422, 'Choose an active project.');
            $command = ['kind' => 'task.create', 'id' => $input['id'], 'idempotency_key' => $input['request_id'],
                'memberships' => [$project['id'] => []], 'title' => $input['title'], 'description' => $input['description'] ?? '',
                'assignee_id' => $this->assignee($actor, $area, $input['assignee_id'] ?? null), 'priority' => $input['priority'] ?? 'normal', 'due_on' => $input['due_on'] ?? null];
            $this->native($tenant, $user, $command);

            return ['area_id' => $area, 'project_id' => $project['id'], 'task_id' => $input['id']];
        }
        $task = $this->graph->read($state, $actor, 'task', $input['id']);
        abort_unless(($task['enterprise_area_id'] ?? null) === $area && $this->graph->can($state, $actor, 'task', $task['id'], true)
            && ! isset($task['enterprise_discussion_project']), 404);
        $projects = array_filter($task['memberships'], fn ($_, $id) => ($state['projects'][$id]['enterprise_area_id'] ?? null) === $area, ARRAY_FILTER_USE_BOTH);
        abort_unless(count($state['tasks'][$task['id']]['memberships']) === 1 && count($projects) === 1, 422, 'Open the native task to review its project memberships.');
        $linked = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area, 'kind' => 'enterprise_request'])->orderBy('id')->limit(1001)->get();
        abort_if($linked->count() > 1000, 409, 'The request scan is incomplete. Review the native task before editing.');
        $linked = $linked->filter(fn ($row) => ($row->data['task_id'] ?? null) === $task['id']);
        abort_if($linked->count() > 1, 409, 'This task has multiple request links. Review it before editing.');
        $record = $linked->first();
        if ($record) {
            abort_unless($record->id === ($input['related_request_id'] ?? null) && $record->version === (int) ($input['request_version'] ?? 0), 409, 'The linked ticket changed. Reload before editing.');
        } else {
            abort_if(isset($input['related_request_id']), 409);
        }
        $before = $record ? $this->requests->view($tenant, $actor, $record) : null;
        abort_if($action === 'task.update' && $task['completed_at'] !== null, 409, 'Review completed work through its existing operational controls.');
        $command = ['kind' => $action, 'id' => $task['id'], 'idempotency_key' => $input['request_id'], 'expected_version' => (int) $input['expected_version']];
        if ($action === 'task.update') {
            $command['changes'] = ['title' => $input['title'], 'description' => $input['description'] ?? '',
                'assignee_id' => $this->assignee($actor, $area, $input['assignee_id'] ?? null), 'priority' => $input['priority'] ?? $task['priority'], 'due_on' => $input['due_on'] ?? null];
        }
        $this->native($tenant, $user, $command);
        if ($record) {
            if ($action === 'task.update') {
                $record->title = $input['title'];
                $record->starts_on = $input['due_on'] ?? null;
                $record->assigned_user_id = $command['changes']['assignee_id'];
                $data = $record->data;
                $data['description'] = $input['description'] ?? '';
                $record->data = $data;
            } else {
                $record->status = 'removed';
            }
            $record->version++;
            $record->save();
            $this->audit($tenant, $user, $record, $action, $before);
        }

        return ['area_id' => $area, 'project_id' => array_key_first($projects), 'task_id' => $task['id']];
    }

    private function assignee(array $actor, string $area, mixed $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        $id = (string) $id;
        $recipient = array_replace($actor, ['user_id' => $id, 'role' => $actor['active_roles'][$id] ?? 'guest']);
        abort_unless(in_array($id, $actor['verified_user_ids'], true) && in_array($recipient['role'], ['owner', 'admin', 'member'], true)
            && WorkspaceAccess::permits($recipient, $area, true), 422, 'Choose a current active, verified editor in this department.');

        return $id;
    }

    private function project(Tenant $tenant, array $actor, string $area, string $id): array
    {
        $project = $this->graph->read($this->work->state($tenant->id), $actor, 'project', $id);
        abort_unless(($project['enterprise_area_id'] ?? null) === $area, 404);

        return $project;
    }

    private function request(Tenant $tenant, string $area, string $id): TeamOperationRecord
    {
        return TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area, 'kind' => 'enterprise_request'])->findOrFail($id);
    }

    private function native(Tenant $tenant, User $user, array $command): array
    {
        return $this->work->execute($tenant->id, fn () => $this->access->actor($tenant, $user, true), $command)['result'];
    }

    private function stable(Tenant $tenant, string $request, string $step): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'enterprise-job:'.$tenant->id.':'.$request.':'.$step);
    }

    private function audit(Tenant $tenant, User $user, TeamOperationRecord $record, string $action, ?array $before): void
    {
        // Audit the exact request record, not a potentially revoked graph projection.
        if ($before) {
            unset($before['task'], $before['can_coordinate'], $before['can_progress']);
        }
        DB::table('team_operation_revisions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'record_id' => $record->id,
            'version' => $record->version, 'user_id' => $user->id, 'payload' => Crypt::encryptString(json_encode(['action' => $action, 'before' => $before,
                'after' => ['id' => $record->id, 'area_id' => $record->area_id, 'title' => $record->title, 'status' => $record->status, 'data' => $record->data]], JSON_THROW_ON_ERROR)), 'created_at' => now()]);
    }
}
