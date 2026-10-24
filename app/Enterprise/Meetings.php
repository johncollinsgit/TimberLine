<?php

namespace App\Enterprise;

use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamRecurrence;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Leader-private meeting notes and exact reviewed native task changes. No implicit work, grants or messages. */
class Meetings
{
    public function __construct(private WorkspaceAccess $access, private RecordStore $store, private RollCall $rollCall, private TeamWorkRepository $work, private WorkGraph $graph) {}

    public function listing(Tenant $tenant, User $user, array $scope): array
    {
        $rollCall = $this->rollCall->build($tenant, $user, $scope);
        $items = [];
        foreach (TeamOperationRecord::where(['tenant_id' => $tenant->id, 'kind' => 'ent_meeting'])->orderByDesc('updated_at')->limit(1000)->cursor() as $row) {
            if (($row->data['created_by'] ?? null) !== (string) $user->id || $row->data['scope'] !== $this->scope($rollCall['scope'])) {
                continue;
            }
            try {
                $items[] = $this->show($tenant, $user, $row->id);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                if (! in_array($e->getStatusCode(), [403, 404], true)) {
                    throw $e;
                }
            }
            if (count($items) === 100) {
                break;
            }
        }

        return ['items' => $items, 'coverage' => 'Up to 100 currently authorized meetings led by you, from a 1,000-record scan. Other leaders\' private notes are excluded.'];
    }

    private function scope(array $scope): array
    {
        return array_intersect_key($scope, array_flip(['type', 'area_id', 'project_id', 'team_user_id']));
    }

    private function row(Tenant $tenant, User $user, string $id, bool $write = false): TeamOperationRecord
    {
        abort_unless(config('enterprise.roll_call_enabled'), 404);
        $actor = $this->access->actor($tenant, $user, $write);
        $row = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'kind' => 'ent_meeting'])->findOrFail($id);
        abort_unless(($row->data['created_by'] ?? null) === (string) $user->id
            && WorkspaceAccess::permits($actor, $row->area_id, $write, true), 404);
        $this->access->area($tenant, $actor, $row->area_id);
        $this->rollCall->context($tenant, $user, $row->data['scope']);
        foreach ($row->data['commitments'] as $commitment) {
            $this->target($tenant, $user, $commitment, false);
        }
        $state = $this->work->state($tenant->id);
        foreach ($row->data['applied'] as $applied) {
            abort_unless($this->graph->can($state, $actor, 'task', $applied['task_id']), 404);
        }

        return $row;
    }

    public function show(Tenant $tenant, User $user, string $id): array
    {
        $row = $this->row($tenant, $user, $id);

        return ['id' => $row->id, 'area_id' => $row->area_id, 'title' => $row->title, 'version' => $row->version,
            ...$row->data, 'updated_at' => $row->updated_at?->toIso8601String(),
            'url' => $this->rollCall->url($tenant, ['section' => 'roll_call', 'meeting' => $row->id, 'area_id' => $row->area_id, 'scope' => $row->data['scope']['type'],
                ...array_filter(array_intersect_key($row->data['scope'], array_flip(['project_id', 'team_user_id'])), fn ($value) => $value !== null)]),
            'privacy' => 'Meeting leader only; current manager and linked-project permissions are rechecked.'];
    }

    public function save(Tenant $tenant, User $user, array $raw): array
    {
        $input = Validator::make($raw, ['id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'],
            'area_id' => ['required', 'uuid'], 'title' => ['required', 'string', 'max:100'], 'scope' => ['required', 'array:type,area_id,project_id,team_user_id'],
            'scope.type' => ['required', 'in:campus,workspace,project,team'], 'scope.area_id' => ['nullable', 'uuid'], 'scope.project_id' => ['nullable', 'uuid'], 'scope.team_user_id' => ['nullable', 'integer', 'min:1'],
            'decisions' => ['present', 'array', 'max:20'], 'decisions.*' => ['array:id,text'], 'decisions.*.id' => ['required', 'uuid'], 'decisions.*.text' => ['required', 'string', 'max:500'],
            'blockers' => ['present', 'array', 'max:20'], 'blockers.*' => ['array:id,text,owner_id,due_on'], 'blockers.*.id' => ['required', 'uuid'], 'blockers.*.text' => ['required', 'string', 'max:500'],
            'blockers.*.owner_id' => ['nullable', 'integer', 'min:1'], 'blockers.*.due_on' => ['nullable', 'date_format:Y-m-d'],
            'commitments' => ['present', 'array', 'max:20'], 'commitments.*' => ['array:id,text,owner_id,due_on,project_id,task_id,expected_task_version'],
            'commitments.*.id' => ['required', 'uuid'], 'commitments.*.text' => ['required', 'string', 'max:500'], 'commitments.*.owner_id' => ['required', 'integer', 'min:1'],
            'commitments.*.due_on' => ['required', 'date_format:Y-m-d'], 'commitments.*.project_id' => ['required', 'uuid'], 'commitments.*.task_id' => ['nullable', 'uuid'],
            'commitments.*.expected_task_version' => ['nullable', 'integer', 'min:1']])->validate();
        foreach (['decisions', 'blockers', 'commitments'] as $key) {
            abort_unless(array_is_list($input[$key]) && count(array_unique(array_column($input[$key], 'id'))) === count($input[$key]), 422);
        }
        $input['commitments'] = array_map(function (array $entry): array {
            $value = ['id' => $entry['id'], 'text' => $entry['text'], 'owner_id' => (string) $entry['owner_id'], 'due_on' => $entry['due_on'], 'project_id' => $entry['project_id']];
            if (! empty($entry['task_id'])) {
                $value += ['task_id' => $entry['task_id'], 'expected_task_version' => (int) ($entry['expected_task_version'] ?? 0)];
            }

            return $value;
        }, $input['commitments']);

        return $this->store->locked($tenant, function () use ($tenant, $user, $input): array {
            $actor = $this->access->actor($tenant, $user, true);
            $this->access->area($tenant, $actor, $input['area_id'], true);
            $scope = $this->scope($this->rollCall->build($tenant, $user, $input['scope'])['scope']);
            if ($scope['type'] !== 'campus') {
                abort_unless($scope['area_id'] === $input['area_id'], 422);
            }
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $receipt = $this->receipt($tenant, $user, $input['request_id'], 'ent.meeting.save', $hash);
            if ($receipt) {
                return $this->show($tenant, $user, $receipt->result['id']) + ['replayed' => true];
            }
            $existing = TeamOperationRecord::where('id', $input['id'])->first();
            $applied = [];
            if ($existing) {
                $row = $this->row($tenant, $user, $input['id'], true);
                abort_unless($row->version === (int) $input['expected_version'] && $row->area_id === $input['area_id'] && $row->data['scope'] === $scope, 409);
                $applied = $row->data['applied'];
                foreach ($applied as $id => $evidence) {
                    $submitted = collect($input['commitments'])->firstWhere('id', $id);
                    abort_unless($submitted && hash_equals($evidence['commitment_hash'], hash('sha256', json_encode($submitted, JSON_THROW_ON_ERROR))), 409, 'A confirmed commitment is immutable. Add a new commitment to propose a further change.');
                }
            } else {
                abort_unless((int) $input['expected_version'] === 0, 409);
                $row = new TeamOperationRecord(['id' => $input['id'], 'tenant_id' => $tenant->id, 'area_id' => $input['area_id'], 'kind' => 'ent_meeting',
                    'source_key' => hash('sha256', 'ent-meeting:'.$input['id']), 'visibility' => 'private', 'status' => 'open', 'version' => 0]);
            }
            foreach ($input['commitments'] as $commitment) {
                $target = $this->target($tenant, $user, $commitment, ! isset($applied[$commitment['id']]));
                abort_unless(($scope['type'] === 'campus' || ($target['project']['enterprise_area_id'] ?? null) === $scope['area_id'])
                    && ($scope['type'] !== 'project' || $commitment['project_id'] === $scope['project_id'])
                    && ($scope['type'] !== 'team' || (string) $commitment['owner_id'] === $scope['team_user_id']), 422, 'Keep commitments within the selected meeting scope.');
            }
            foreach ($input['blockers'] as $blocker) {
                if (isset($blocker['owner_id'])) {
                    $ownerId = (string) $blocker['owner_id'];
                    $recipient = array_replace($actor, ['user_id' => $ownerId, 'role' => $actor['active_roles'][$ownerId] ?? 'guest']);
                    abort_unless(in_array($ownerId, $actor['verified_user_ids'], true) && WorkspaceAccess::permits($recipient, $input['area_id'], true), 422);
                }
            }
            $row->title = $input['title'];
            $row->version++;
            $row->data = ['created_by' => (string) $user->id, 'scope' => $scope, 'decisions' => $input['decisions'], 'blockers' => $input['blockers'],
                'commitments' => $input['commitments'], 'applied' => $applied];
            $row->save();
            $this->store->audit($row, $user->id, 'meeting.saved', ['decisions' => count($input['decisions']), 'blockers' => count($input['blockers']), 'commitments' => count($input['commitments'])]);
            $this->remember($tenant, $user, $row, $input['request_id'], 'ent.meeting.save', $hash);

            return $this->show($tenant, $user, $row->id) + ['replayed' => false];
        });
    }

    private function target(Tenant $tenant, User $user, array $commitment, bool $write): array
    {
        $actor = $this->access->actor($tenant, $user, $write);
        $state = $this->work->state($tenant->id);
        $projectId = $commitment['project_id'];
        abort_unless($this->graph->can($state, $actor, 'project', $projectId, $write), 404);
        $project = $state['projects'][$projectId];
        $areaId = $project['enterprise_area_id'] ?? null;
        abort_unless($areaId && WorkspaceAccess::permits($actor, $areaId, $write, $write) && (! $write || ! $project['archived']), 404);
        $ownerId = (string) $commitment['owner_id'];
        $recipient = array_replace($actor, ['user_id' => $ownerId, 'role' => $actor['active_roles'][$ownerId] ?? 'guest']);
        if ($write) {
            abort_unless(in_array($ownerId, $actor['verified_user_ids'], true) && in_array($recipient['role'], ['owner', 'admin', 'member'], true)
                && WorkspaceAccess::permits($recipient, $areaId, true) && $this->graph->can($state, $recipient, 'project', $projectId, true), 422, 'Choose an existing verified editor with access to the target project.');
        }
        $task = null;
        $operationsVersion = null;
        if (! empty($commitment['task_id'])) {
            abort_unless($this->graph->can($state, $actor, 'task', $commitment['task_id'], $write), 404);
            $task = $state['tasks'][$commitment['task_id']];
            abort_unless(isset($task['memberships'][$projectId]) && ! isset($task['enterprise_discussion_project']), 422);
            if ($write) {
                abort_unless($task['version'] === (int) ($commitment['expected_task_version'] ?? 0), 409, 'The target task changed. Review its current version.');
                abort_unless($task['assignee_id'] === $ownerId && $task['completed_at'] === null, 422, 'Use Operations to change accountable ownership or reopen work before proposing this meeting update.');
                if (config('enterprise.operations_enabled')) {
                    $operations = app(Operations::class)->show($tenant, $user, $task['id']);
                    abort_unless($operations['owner_user_id'] === $ownerId, 422, 'Use Operations when the accountable owner and current assignee differ.');
                    $operationsVersion = $operations['version'];
                }
            }
        }

        return compact('project', 'task', 'ownerId', 'operationsVersion');
    }

    private function actions(Tenant $tenant, User $user, TeamOperationRecord $row): array
    {
        $actions = [];
        foreach ($row->data['commitments'] as $commitment) {
            if (isset($row->data['applied'][$commitment['id']])) {
                continue;
            }
            $target = $this->target($tenant, $user, $commitment, true);
            $taskId = $target['task']['id'] ?? TeamRecurrence::stableId('ent-meeting:'.$tenant->id.':'.$row->id.':'.$commitment['id']);
            $command = $target['task']
                ? ['kind' => 'task.update', 'id' => $taskId, 'expected_version' => $target['task']['version'],
                    'changes' => ['title' => $commitment['text'], 'due_on' => $commitment['due_on']]]
                : ['kind' => 'task.create', 'id' => $taskId, 'title' => $commitment['text'], 'description' => 'Confirmed meeting commitment.',
                    'assignee_id' => $target['ownerId'], 'due_on' => $commitment['due_on'], 'memberships' => [$commitment['project_id'] => []]];
            $actions[] = ['commitment_id' => $commitment['id'], 'owner_id' => $target['ownerId'], 'owner_name' => User::findOrFail($target['ownerId'])->name,
                'project_id' => $commitment['project_id'], 'project_title' => $target['project']['name'], 'project_version' => $target['project']['version'],
                'operations_version' => $target['operationsVersion'], 'command' => $command];
        }

        return $actions;
    }

    public function preview(Tenant $tenant, User $user, string $id): array
    {
        return $this->store->locked($tenant, function () use ($tenant, $user, $id): array {
            $row = $this->row($tenant, $user, $id, true);
            $actions = $this->actions($tenant, $user, $row);
            abort_unless($actions !== [], 422, 'No unconfirmed commitments remain.');
            $digest = hash('sha256', json_encode([$row->id, $row->version, (string) $user->id, $actions], JSON_THROW_ON_ERROR));
            $preview = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $row->area_id,
                'kind' => 'ent_meeting_preview', 'source_key' => hash('sha256', (string) Str::uuid()), 'visibility' => 'private', 'title' => 'Private commitment preview', 'status' => 'preview', 'version' => 1,
                'data' => ['meeting_id' => $row->id, 'meeting_version' => $row->version, 'user_id' => (string) $user->id, 'digest' => $digest,
                    'actions' => $actions, 'expires_at' => now()->addMinutes(15)->toIso8601String()]]);
            $this->store->audit($preview, $user->id, 'meeting.previewed', ['meeting_id' => $row->id, 'meeting_version' => $row->version, 'digest' => $digest]);

            return ['preview_id' => $preview->id, 'meeting_id' => $row->id, 'version' => $row->version, 'digest' => $digest,
                'expires_at' => $preview->data['expires_at'], 'actions' => $actions, 'effect' => 'No native task has changed. Confirm this exact preview to apply these actions.'];
        });
    }

    public function confirm(Tenant $tenant, User $user, string $id, array $raw): array
    {
        $input = Validator::make($raw, ['request_id' => ['required', 'uuid'], 'preview_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1'],
            'digest' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'confirmed' => ['required', 'accepted']])->validate();

        return $this->store->locked($tenant, function () use ($tenant, $user, $id, $input): array {
            $row = $this->row($tenant, $user, $id, true);
            $hash = hash('sha256', json_encode([$id, $input], JSON_THROW_ON_ERROR));
            if ($this->receipt($tenant, $user, $input['request_id'], 'ent.meeting.confirm', $hash)) {
                return $this->show($tenant, $user, $row->id) + ['replayed' => true];
            }
            $preview = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'kind' => 'ent_meeting_preview'])->findOrFail($input['preview_id']);
            $review = $preview->data;
            abort_unless($review['user_id'] === (string) $user->id && $review['meeting_id'] === $id && $preview->status === 'preview', 404);
            abort_unless(now()->lt(\Carbon\CarbonImmutable::parse($review['expires_at'])), 409, 'The preview expired. Review a fresh preview.');
            abort_unless($row->version === (int) $input['expected_version'] && $row->version === $review['meeting_version'], 409);
            $actions = $this->actions($tenant, $user, $row);
            $digest = hash('sha256', json_encode([$row->id, $row->version, (string) $user->id, $actions], JSON_THROW_ON_ERROR));
            abort_unless(hash_equals($review['digest'], $input['digest']) && hash_equals($digest, $input['digest']), 409, 'The reviewed actions or target records changed. Review a fresh preview.');
            $data = $row->data;
            foreach ($actions as $action) {
                $command = $action['command'] + ['idempotency_key' => TeamRecurrence::stableId('meeting-confirm:'.$row->id.':'.$action['commitment_id'])];
                $this->work->execute($tenant->id, fn () => $this->access->actor($tenant, $user, true), $command);
                $commitment = collect($data['commitments'])->firstWhere('id', $action['commitment_id']);
                $data['applied'][$action['commitment_id']] = ['task_id' => $command['id'], 'at' => now()->toIso8601String(),
                    'by' => (string) $user->id, 'commitment_hash' => hash('sha256', json_encode($commitment, JSON_THROW_ON_ERROR))];
            }
            $row->data = $data;
            $row->version++;
            $row->save();
            $preview->status = 'confirmed';
            $preview->version++;
            $preview->save();
            $this->store->audit($row, $user->id, 'meeting.commitments_confirmed', ['preview_id' => $preview->id, 'digest' => $digest, 'task_ids' => array_column($data['applied'], 'task_id')]);
            $this->remember($tenant, $user, $row, $input['request_id'], 'ent.meeting.confirm', $hash);

            return $this->show($tenant, $user, $row->id) + ['replayed' => false];
        });
    }

    public function document(Tenant $tenant, User $user, string $id): array
    {
        $meeting = $this->show($tenant, $user, $id);
        $ownerIds = array_unique([...array_column($meeting['commitments'], 'owner_id'), ...array_column($meeting['blockers'], 'owner_id')]);
        $owners = $tenant->users()->wherePivot('membership_active', true)->where('users.is_active', true)->whereNotNull('users.email_verified_at')
            ->whereIn('users.id', $ownerIds)->get()->mapWithKeys(fn ($person) => [(string) $person->id => $person->name])->all();

        return ['meeting' => $meeting, 'roll_call' => $this->rollCall->build($tenant, $user, $meeting['scope']), 'owners' => $owners];
    }

    private function receipt(Tenant $tenant, User $user, string $requestId, string $kind, string $hash): ?TeamOperationAttempt
    {
        $row = TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $requestId])->first();
        if ($row) {
            abort_unless($row->kind === $kind && hash_equals($row->input_hash, $hash), 409);
        }

        return $row;
    }

    private function remember(Tenant $tenant, User $user, TeamOperationRecord $meeting, string $requestId, string $kind, string $hash): void
    {
        TeamOperationAttempt::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $meeting->area_id, 'record_id' => $meeting->id,
            'user_id' => $user->id, 'request_id' => $requestId, 'kind' => $kind, 'status' => 'accepted', 'input_hash' => $hash, 'result' => ['id' => $meeting->id]]);
    }
}
