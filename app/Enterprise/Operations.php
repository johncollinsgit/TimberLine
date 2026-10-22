<?php

namespace App\Enterprise;

use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Accountable operational metadata over canonical native task IDs. Never creates membership. */
class Operations
{
    public const TASK = 'ent_ops_task';

    public const HANDOFF = 'ent_ops_handoff';

    public const REVIEW = 'ent_ops_review';

    public function __construct(private WorkspaceAccess $access, private RecordStore $store, private TeamWorkRepository $work, private WorkGraph $graph) {}

    /** Trusted DB-only proof input to WorkGraph. This fence survives a disabled operations rollout. */
    public static function graphAuthority(Tenant $tenant, array $scopes): array
    {
        $managed = $proofs = $reviewers = [];
        foreach (TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => self::TASK])->cursor() as $row) {
            $data = $row->data;
            $taskId = $data['task_id'] ?? null;
            if (! is_string($taskId) || ! isset($scopes[$row->area_id])) {
                continue;
            }
            $managed[$taskId] = true;
            $approval = $data['approval'] ?? null;
            $items = $data['checklist'] ?? [];
            if (! is_array($approval) || ! $items || count(array_filter($items, fn ($i) => ($i['completed'] ?? false) === true)) !== count($items)
                || ! isset($approval['by'], $approval['native_version'], $approval['checklist_digest'])
                || ! hash_equals($approval['checklist_digest'], hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)))) {
                continue;
            }
            if (! array_key_exists($approval['by'], $reviewers)) {
                $member = DB::table('tenant_user')->join('users', 'users.id', '=', 'tenant_user.user_id')->where('tenant_user.tenant_id', $tenant->id)
                    ->where('tenant_user.user_id', $approval['by'])->where('tenant_user.membership_active', true)->where('users.is_active', true)->whereNotNull('users.email_verified_at')
                    ->select('tenant_user.role')->first();
                $reviewers[$approval['by']] = $member ? strtolower((string) $member->role) : '';
            }
            $role = $reviewers[$approval['by']];
            if (in_array($role, ['tenant_owner', 'owner', 'admin', 'manager', 'employee', 'member'], true) && (in_array($role, ['tenant_owner', 'owner', 'admin'], true)
                || ($scopes[$row->area_id]['members'][(string) $approval['by']] ?? null) === 'coordinator')) {
                $proofs[$taskId] = ['task_version' => $approval['native_version'], 'approver_id' => (string) $approval['by']];
            }
        }

        return ['enterprise_ops_managed' => $managed, 'enterprise_ops_completion_proofs' => $proofs];
    }

    private function actor(Tenant $tenant, User $user, bool $write = false): array
    {
        abort_unless(config('enterprise.operations_enabled'), 404);

        return $this->access->actor($tenant, $user, $write);
    }

    private function task(Tenant $tenant, array $actor, string $id, bool $write = false, ?array $state = null): array
    {
        $state ??= $this->work->state($tenant->id);
        abort_unless($this->graph->can($state, $actor, 'task', $id, $write), 404);
        $task = $this->graph->read($state, $actor, 'task', $id);
        $area = $task['enterprise_area_id'] ?? '';
        $this->access->area($tenant, $actor, $area);
        abort_if(isset($task['enterprise_discussion_project']), 404);
        $projects = array_filter(array_keys($task['memberships']), fn ($projectId) => isset($state['projects'][$projectId])
            && ($state['projects'][$projectId]['enterprise_area_id'] ?? '') === $area && $this->graph->can($state, $actor, 'project', $projectId, $write)
            && ! $state['projects'][$projectId]['archived']);
        abort_unless($projects, 404);

        return $task + ['project_id' => array_values($projects)[0]];
    }

    private function metadata(Tenant $tenant, array $task): ?TeamOperationRecord
    {
        return TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $task['enterprise_area_id'], 'kind' => self::TASK,
            'source_key' => hash('sha256', 'ops-task:'.$task['id'])])->first();
    }

    private function defaults(array $task): array
    {
        return ['task_id' => $task['id'], 'owner_user_id' => $task['assignee_id'] ?? $task['creator_id'],
            'ball_user_id' => $task['assignee_id'], 'ball_team' => $task['assignee_id'] === null, 'next_action' => '',
            'blockers' => [], 'checklist' => [], 'checkpoint' => null, 'approval' => null, 'closure' => null];
    }

    private function manager(array $actor, string $areaId): bool
    {
        return WorkspaceAccess::permits($actor, $areaId, true, true);
    }

    private function eligible(Tenant $tenant, array $actor, array $task, string $userId): void
    {
        $recipient = array_replace($actor, ['user_id' => $userId, 'role' => $actor['active_roles'][$userId] ?? 'guest']);
        abort_unless(in_array($userId, $actor['verified_user_ids'], true) && WorkspaceAccess::permits($recipient, $task['enterprise_area_id'], true)
            && $this->graph->can($this->work->state($tenant->id), $recipient, 'task', $task['id'], true), 422, 'Choose an existing active verified task editor.');
    }

    private function canUpdate(array $actor, array $task, array $data): bool
    {
        return WorkspaceAccess::permits($actor, $task['enterprise_area_id'], true) && ($this->manager($actor, $task['enterprise_area_id'])
            || $data['ball_user_id'] === $actor['user_id'] || ($data['ball_team'] && $data['ball_user_id'] === null));
    }

    private function progress(array $data): array
    {
        $total = array_sum(array_column($data['checklist'], 'weight'));
        $done = array_sum(array_column(array_filter($data['checklist'], fn ($item) => $item['completed']), 'weight'));
        $performed = $total > 0 && $done === $total;
        $percent = $performed ? 100.0 : ($total > 0 ? min(99.9, round($done * 100 / $total, 1)) : 0.0);

        return ['progress_percent' => $percent, 'completion_score' => $performed ? ($data['approval'] ? 4.0 : 3.9) : round(min(3.8, $percent * 4 / 100), 1),
            'approval_required' => $performed && ! $data['approval']];
    }

    private function view(Tenant $tenant, array $actor, array $task, ?TeamOperationRecord $row, ?array $state = null): array
    {
        $state ??= $this->work->state($tenant->id);
        $data = $row?->data ?? $this->defaults($task);
        $data['ball_user_id'] = $task['assignee_id'];
        $data['ball_team'] = $task['assignee_id'] === null;
        if ($data['approval'] && (! $task['completed_at'] || $task['version'] !== $data['approval']['native_version'] + 1
            || ! hash_equals($data['approval']['checklist_digest'], hash('sha256', json_encode($data['checklist'], JSON_THROW_ON_ERROR))))) {
            $data['approval'] = null;
        }
        $progress = $this->progress($data);
        $status = $row?->status ?? ($task['completed_at'] ? 'completed' : 'active');
        if ($status === 'active' && $progress['approval_required']) {
            $status = 'awaiting_verification';
        }
        // A native completion outside this lane is reported honestly, never treated as a manager grade.
        if ($task['completed_at'] && ! in_array($status, ['closed', 'interrupted'], true)) {
            $status = $data['approval'] ? 'completed' : 'awaiting_verification';
        }
        $manager = $this->manager($actor, $task['enterprise_area_id']);

        return ['id' => $task['id'], 'task_id' => $task['id'], 'project_id' => $task['project_id'], 'area_id' => $task['enterprise_area_id'],
            'title' => $task['title'], 'status' => $status, 'priority' => $task['priority'], 'emergency' => $task['priority'] === 'urgent', 'version' => $row?->version ?? 0, 'task_version' => $task['version'],
            'owner_user_id' => $data['owner_user_id'], 'ball_user_id' => $data['ball_user_id'], 'ball_team' => $data['ball_team'],
            'next_action' => $data['next_action'], 'due_on' => $task['due_on'], 'blockers' => $data['blockers'], 'checklist' => $data['checklist'],
            'source_request_id' => $data['source_request_id'] ?? null, 'handoff_id' => $data['handoff_id'] ?? null, 'owning_area_id' => $data['owning_area_id'] ?? $task['enterprise_area_id'],
            ...$progress, 'checkpoint' => $data['checkpoint'], 'approval' => $data['approval'], 'closure' => $data['closure'],
            'can_manage' => $manager && $this->graph->can($state, $actor, 'task', $task['id'], true),
            'can_update' => $this->canUpdate($actor, $task, $data) && $this->graph->can($state, $actor, 'task', $task['id'], true),
            'updated_at' => $row?->updated_at?->toIso8601String() ?? $task['updated_at'],
            'url' => route('enterprise.index', ['tenant' => $tenant->slug, 'area_id' => $task['enterprise_area_id'], 'section' => 'projects', 'selected' => $task['project_id']])];
    }

    public function show(Tenant $tenant, User $user, string $id): array
    {
        $actor = $this->actor($tenant, $user);
        $task = $this->task($tenant, $actor, $id);

        return $this->view($tenant, $actor, $task, $this->metadata($tenant, $task));
    }

    public function assertApproved(Tenant $tenant, User $user, string $taskId): void
    {
        $task = $this->show($tenant, $user, $taskId);
        abort_unless($task['status'] === 'completed' && $task['completion_score'] === 4.0 && $task['approval'] !== null, 409,
            'Complete the required checklist and obtain current native-version manager approval before completing the owning request.');
    }

    public function listing(Tenant $tenant, User $user, string $areaId, ?string $projectId = null): array
    {
        $actor = $this->actor($tenant, $user);
        $this->access->area($tenant, $actor, $areaId);
        $state = $this->work->state($tenant->id);
        if ($projectId) {
            abort_unless(($state['projects'][$projectId]['enterprise_area_id'] ?? '') === $areaId && $this->graph->can($state, $actor, 'project', $projectId), 404);
        }
        $items = [];
        foreach ($state['tasks'] as $id => $task) {
            if (($task['enterprise_area_id'] ?? '') !== $areaId || isset($task['enterprise_discussion_project']) || ($projectId && ! isset($task['memberships'][$projectId])) || ! $this->graph->can($state, $actor, 'task', $id)) {
                continue;
            }
            try {
                $t = $this->task($tenant, $actor, $id, false, $state);
                $items[] = $this->view($tenant, $actor, $t, $this->metadata($tenant, $t), $state);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                if ($error->getStatusCode() !== 404) {
                    throw $error;
                }
            }
            if (count($items) === 200) {
                break;
            }
        }
        $handoffs = [];
        foreach (TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId, 'kind' => self::HANDOFF])->orderByDesc('updated_at')->limit(1000)->cursor() as $row) {
            if ($row->status === 'pending' && (! $projectId || $row->data['target_project_id'] === $projectId)) {
                try {
                    $handoffs[] = $this->handoffView($tenant, $actor, $row);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                    if ($error->getStatusCode() !== 404) {
                        throw $error;
                    }
                }
                if (count($handoffs) === 100) {
                    break;
                }
            }
        }
        $members = $tenant->users()->wherePivot('membership_active', true)->where('is_active', true)->whereNotNull('email_verified_at')->get()
            ->filter(fn ($u) => WorkspaceAccess::permits(array_replace($actor, ['user_id' => (string) $u->id, 'role' => $actor['active_roles'][(string) $u->id] ?? 'guest']), $areaId, true))
            ->map(fn ($u) => ['id' => (string) $u->id, 'name' => $u->name])->values()->all();

        return ['items' => $items, 'resumption_queue' => array_values(array_filter($items, fn ($item) => $item['status'] === 'interrupted')),
            'pending_handoffs' => $handoffs, 'can_manage' => $this->manager($actor, $areaId), 'members' => $members,
            'coverage' => 'Up to 200 authorized native tasks and 100 pending handoffs from a bounded scan of 1000 destination records. Internal operational notes remain within existing task and department permissions.',
            'as_of' => now()->toIso8601String()];
    }

    private function receipt(Tenant $tenant, User $user, array $input): ?TeamOperationAttempt
    {
        $receipt = TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
        if ($receipt) {
            abort_unless($receipt->kind === 'ent.operations' && hash_equals($receipt->input_hash, hash('sha256', json_encode($input, JSON_THROW_ON_ERROR))), 409);
        }

        return $receipt;
    }

    private function remember(Tenant $tenant, User $user, TeamOperationRecord $row, array $input, array $result = []): void
    {
        TeamOperationAttempt::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $row->area_id, 'record_id' => $row->id,
            'user_id' => $user->id, 'kind' => 'ent.operations', 'status' => 'accepted', 'request_id' => $input['request_id'],
            'input_hash' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)), 'result' => $result]);
    }

    private function native(Tenant $tenant, User $user, array $task, string $key, string $kind, array $changes): void
    {
        $this->work->execute($tenant->id, fn () => $this->access->actor($tenant, $user, true), ['id' => $task['id'], 'idempotency_key' => 'ops:'.$key,
            'kind' => $kind, 'expected_version' => $task['version'], ...$changes]);
    }

    public function execute(Tenant $tenant, User $user, array $input): array
    {
        return $this->store->locked($tenant, function () use ($tenant, $user, $input): array {
            $actor = $this->actor($tenant, $user, true);
            if (str_starts_with($input['action'], 'handoff.')) {
                return $this->handoff($tenant, $user, $actor, $input);
            }
            if ($input['action'] === 'contractor.review') {
                return $this->review($tenant, $user, $actor, $input);
            }
            $task = $this->task($tenant, $actor, $input['task_id'], true);
            $row = $this->metadata($tenant, $task);
            $data = $row?->data ?? $this->defaults($task);
            // The native assignee is the current ball; metadata never overrides a native reassignment.
            $data['ball_user_id'] = $task['assignee_id'];
            $data['ball_team'] = $task['assignee_id'] === null;
            if ($data['approval'] && (! $task['completed_at'] || $task['version'] !== $data['approval']['native_version'] + 1)) {
                $data['approval'] = null;
            }
            $manage = $this->manager($actor, $task['enterprise_area_id']);
            $workerAction = in_array($input['action'], ['task.check', 'task.interrupt', 'task.resume'], true);
            abort_unless($workerAction ? $this->canUpdate($actor, $task, $data) : $manage, 403);
            if ($this->receipt($tenant, $user, $input)) {
                return $this->view($tenant, $actor, $task, $row) + ['replayed' => true];
            }
            abort_unless(($row?->version ?? 0) === $input['expected_version'] && $task['version'] === $input['expected_task_version'], 409, 'This task changed. Reload the current task before applying another operation.');
            $row ??= new TeamOperationRecord(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $task['enterprise_area_id'], 'kind' => self::TASK,
                'source_key' => hash('sha256', 'ops-task:'.$task['id']), 'visibility' => 'area', 'title' => 'Native task operations', 'status' => 'active', 'version' => 0]);
            $action = $input['action'];
            abort_if($row->status === 'closed' || ($row->status === 'completed' && ! ($action === 'task.approve' && $data['approval'] === null)), 409, 'This operational task is closed. Its history is retained.');
            if ($action === 'task.configure') {
                $this->eligible($tenant, $actor, $task, $input['owner_user_id']);
                if (! $input['ball_team']) {
                    $this->eligible($tenant, $actor, $task, $input['ball_user_id']);
                }
                $old = array_column($data['checklist'], null, 'id');
                $data = array_replace($data, ['owner_user_id' => $input['owner_user_id'], 'ball_user_id' => $input['ball_team'] ? null : $input['ball_user_id'],
                    'ball_team' => $input['ball_team'], 'next_action' => $input['next_action'], 'blockers' => $input['blockers'] ?? [],
                    'checklist' => array_map(fn ($item) => $item + ['completed' => ($old[$item['id']]['label'] ?? null) === $item['label'] && ($old[$item['id']]['weight'] ?? null) === $item['weight'] ? $old[$item['id']]['completed'] : false], $input['checklist']), 'approval' => null]);
                $this->native($tenant, $user, $task, $input['request_id'], 'task.update', ['changes' => ['assignee_id' => $data['ball_user_id'],
                    'due_on' => array_key_exists('due_on', $input) ? $input['due_on'] : $task['due_on'], 'priority' => $input['priority'] ?? $task['priority']]]);
            } elseif ($action === 'task.check') {
                abort_unless($row->status === 'active' && ! $task['completed_at'], 409, 'Resume interrupted work before updating its checklist.');
                $found = false;
                foreach ($data['checklist'] as &$item) {
                    if ($item['id'] === $input['item_id']) {
                        $item['completed'] = $input['completed'];
                        $found = true;
                    }
                }
                unset($item);
                abort_unless($found, 422);
                $data['approval'] = null;
            } elseif ($action === 'task.interrupt') {
                abort_unless($row->status === 'active' && ! $task['completed_at'], 409);
                if (! empty($input['emergency_task_id'])) {
                    $emergency = $this->task($tenant, $actor, $input['emergency_task_id']);
                    abort_unless($emergency['enterprise_area_id'] === $task['enterprise_area_id'] && $emergency['id'] !== $task['id'], 422);
                }
                $data['checkpoint'] = ['at' => now()->toIso8601String(), 'by' => (string) $user->id, 'note' => $input['checkpoint'], 'reason' => $input['reason'],
                    'progress_percent' => $this->progress($data)['progress_percent'], 'ball_user_id' => $data['ball_user_id'], 'emergency_task_id' => $input['emergency_task_id'] ?? null];
                $row->status = 'interrupted';
            } elseif ($action === 'task.resume') {
                abort_unless($row->status === 'interrupted', 409);
                $row->status = 'active';
                $data['last_resolution'] = ['action' => $action, 'reason' => $input['reason'], 'by' => (string) $user->id, 'at' => now()->toIso8601String()];
            } elseif ($action === 'task.reassign') {
                $this->eligible($tenant, $actor, $task, $input['ball_user_id']);
                $data['ball_user_id'] = $input['ball_user_id'];
                $data['ball_team'] = false;
                $data['last_resolution'] = ['action' => $action, 'reason' => $input['reason'], 'by' => (string) $user->id, 'at' => now()->toIso8601String()];
                $row->status = 'active';
                $this->native($tenant, $user, $task, $input['request_id'], 'task.update', ['changes' => ['assignee_id' => $input['ball_user_id']]]);
            } elseif ($action === 'task.reschedule') {
                $this->native($tenant, $user, $task, $input['request_id'], 'task.update', ['changes' => ['due_on' => $input['due_on']]]);
                $data['last_resolution'] = ['action' => $action, 'reason' => $input['reason'], 'by' => (string) $user->id, 'at' => now()->toIso8601String()];
                $row->status = 'active';
            } elseif ($action === 'task.close') {
                $data['closure'] = ['reason' => $input['reason'], 'by' => (string) $user->id, 'at' => now()->toIso8601String()];
                $row->status = 'closed';
                // Reasoned closure is not a fabricated native completion or approved 4.0 grade.
            } elseif ($action === 'task.approve') {
                abort_unless(in_array($row->status, ['active', 'completed'], true) && $this->progress($data)['approval_required'], 409, 'Every required checklist item must be performed before manager approval.');
                $data['approval'] = ['by' => (string) $user->id, 'at' => now()->toIso8601String(), 'note' => $input['note'] ?? '', 'native_version' => $task['version'],
                    'checklist_digest' => hash('sha256', json_encode($data['checklist'], JSON_THROW_ON_ERROR))];
                $row->data = $data;
                $row->save(); // Proof staged under the existing tenant transaction; native failure rolls everything back.
                $this->native($tenant, $user, $task, $input['request_id'], 'task.complete', ['completed' => true]);
                $row->status = 'completed';
            } else {
                abort(422);
            }
            $row->data = $data;
            $row->version++;
            $row->save();
            $this->store->audit($row, $user->id, $action, ['task_id' => $task['id'], 'after' => $data]);
            $this->remember($tenant, $user, $row, $input);
            $task = $this->task($tenant, $actor, $task['id']);

            return $this->view($tenant, $actor, $task, $row) + ['replayed' => false];
        });
    }

    private function handoffView(Tenant $tenant, array $actor, TeamOperationRecord $row): array
    {
        $data = $row->data;
        $this->access->area($tenant, $actor, $row->area_id);
        $state = $this->work->state($tenant->id);
        abort_unless($this->graph->can($state, $actor, 'project', $data['target_project_id']), 404);

        return ['id' => $row->id, 'version' => $row->version, 'status' => $row->status, 'source_request_id' => $data['source_request_id'], 'owning_area_id' => $data['owning_area_id'],
            'target_area_id' => $row->area_id, 'target_project_id' => $data['target_project_id'], 'title' => $row->title, 'next_action' => $data['next_action'],
            'assignee_id' => $data['assignee_id'], 'task_id' => $data['task_id'] ?? null, 'can_accept' => $this->manager($actor, $row->area_id) && $this->graph->can($state, $actor, 'project', $data['target_project_id'], true)];
    }

    private function handoff(Tenant $tenant, User $user, array $actor, array $input): array
    {
        if ($input['action'] === 'handoff.propose') {
            $request = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => 'enterprise_request'])->findOrFail($input['source_request_id']);
            app(RequestWorkflow::class)->view($tenant, $actor, $request);
            abort_unless($this->manager($actor, $request->area_id), 403);
            abort_unless(\App\Models\TeamArea::query()->where(['tenant_id' => $tenant->id, 'id' => $input['target_area_id']])->whereIn('profile', WorkspaceAccess::PROFILES)->exists()
                && WorkspaceAccess::permits($actor, $input['target_area_id']), 404, 'The selected destination is unavailable.');
            abort_unless($request->area_id !== $input['target_area_id'], 422);
            $state = $this->work->state($tenant->id);
            $project = $state['projects'][$input['target_project_id']] ?? null;
            abort_unless($project && ! $project['archived'] && ($project['enterprise_area_id'] ?? '') === $input['target_area_id'] && $this->graph->can($state, $actor, 'project', $project['id']), 404);
            if ($receipt = $this->receipt($tenant, $user, $input)) {
                $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => self::HANDOFF])->findOrFail($receipt->result['id']);

                return $this->handoffView($tenant, $actor, $row) + ['replayed' => true];
            }
            abort_unless($request->version === $input['expected_request_version'], 409);
            $recipient = array_replace($actor, ['user_id' => $input['assignee_id'], 'role' => $actor['active_roles'][$input['assignee_id']] ?? 'guest']);
            abort_unless(in_array($input['assignee_id'], $actor['verified_user_ids'], true) && WorkspaceAccess::permits($recipient, $input['target_area_id'], true)
                && $this->graph->can($state, $recipient, 'project', $project['id'], true), 422);
            abort_if(TeamOperationRecord::query()->find($input['id']), 409);
            $row = TeamOperationRecord::query()->create(['id' => $input['id'], 'tenant_id' => $tenant->id, 'area_id' => $input['target_area_id'], 'kind' => self::HANDOFF,
                'source_key' => hash('sha256', 'ops-handoff:'.$input['id']), 'visibility' => 'area', 'title' => $input['title'], 'status' => 'pending', 'version' => 1,
                'data' => ['source_request_id' => $request->id, 'owning_area_id' => $request->area_id, 'target_project_id' => $project['id'],
                    'next_action' => $input['next_action'], 'assignee_id' => $input['assignee_id'], 'proposed_by' => (string) $user->id]]);
            $request->data = array_replace($request->data, ['handoff_ids' => [...($request->data['handoff_ids'] ?? []), $row->id]]);
            $request->version++;
            $request->save();
            $this->store->audit($request, $user->id, 'handoff.proposed', ['handoff_id' => $row->id, 'target_area_id' => $row->area_id]);
        } else {
            $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => self::HANDOFF])->findOrFail($input['id']);
            $view = $this->handoffView($tenant, $actor, $row);
            abort_unless($view['can_accept'], 403);
            if ($this->receipt($tenant, $user, $input)) {
                return $view + ['replayed' => true];
            }
            abort_unless($row->status === 'pending' && $row->version === $input['expected_version'], 409);
            $data = $row->data;
            $request = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $data['owning_area_id'], 'kind' => 'enterprise_request'])->findOrFail($data['source_request_id']);
            if ($input['action'] === 'handoff.accept') {
                $taskId = (string) Str::uuid();
                $this->work->execute($tenant->id, fn () => $this->access->actor($tenant, $user, true), ['kind' => 'task.create', 'id' => $taskId,
                    'idempotency_key' => 'ops-handoff:'.$input['request_id'], 'title' => $row->title, 'description' => $data['next_action'],
                    'assignee_id' => $data['assignee_id'], 'memberships' => [$data['target_project_id'] => []]]);
                $task = $this->task($tenant, $actor, $taskId);
                $this->eligible($tenant, $actor, $task, $data['assignee_id']);
                $meta = TeamOperationRecord::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $row->area_id, 'kind' => self::TASK,
                    'source_key' => hash('sha256', 'ops-task:'.$taskId), 'visibility' => 'area', 'title' => 'Native task operations', 'status' => 'active', 'version' => 1,
                    'data' => array_replace($this->defaults($task), ['owner_user_id' => $data['assignee_id'], 'next_action' => $data['next_action'],
                        'handoff_id' => $row->id, 'source_request_id' => $request->id, 'owning_area_id' => $request->area_id])]);
                $this->store->audit($meta, $user->id, 'handoff.task_created', ['task_id' => $taskId, 'handoff_id' => $row->id]);
                $data['task_id'] = $taskId;
                $row->status = 'accepted';
            } else {
                abort_unless($input['action'] === 'handoff.decline', 422);
                $row->status = 'declined';
            }
            $data['resolution'] = ['by' => (string) $user->id, 'at' => now()->toIso8601String(), 'reason' => $input['reason']];
            $row->data = $data;
            $row->version++;
            $row->save();
            $request->version++;
            $request->save();
            $this->store->audit($request, $user->id, 'handoff.'.$row->status, ['handoff_id' => $row->id, 'task_id' => $data['task_id'] ?? null]);
        }
        $this->store->audit($row, $user->id, $input['action'], ['selected_shared_fields' => $this->handoffView($tenant, $actor, $row)]);
        $this->remember($tenant, $user, $row, $input, ['id' => $row->id]);

        return $this->handoffView($tenant, $actor, $row) + ['replayed' => false];
    }

    private function review(Tenant $tenant, User $user, array $actor, array $input): array
    {
        $task = $this->task($tenant, $actor, $input['task_id']);
        abort_unless($this->manager($actor, $task['enterprise_area_id']), 403);
        $contractor = app(Planning::class)->record($tenant, $actor, 'contractor', $input['contractor_id']);
        abort_unless($contractor->area_id === $task['enterprise_area_id'], 404);
        $source = hash('sha256', 'ops-review:'.$contractor->id.':'.$task['id']);
        $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $contractor->area_id, 'kind' => self::REVIEW, 'source_key' => $source])->first();
        if ($this->receipt($tenant, $user, $input)) {
            return $this->quality($tenant, $user, $contractor->area_id, $contractor->id) + ['replayed' => true];
        }
        abort_unless(($row?->version ?? 0) === $input['expected_version'], 409);
        $row ??= new TeamOperationRecord(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $contractor->area_id, 'kind' => self::REVIEW,
            'source_key' => $source, 'visibility' => 'manager', 'title' => 'Private contractor evaluation', 'status' => 'reviewed', 'version' => 0]);
        $row->data = ['task_id' => $task['id'], 'contractor_id' => $contractor->id, 'dimensions' => array_intersect_key($input, array_flip(['workmanship', 'timeliness', 'communication', 'documentation'])),
            'note' => $input['note'] ?? '', 'reviewed_by' => (string) $user->id, 'reviewed_at' => now()->toIso8601String()];
        $row->version++;
        $row->save();
        $this->store->audit($row, $user->id, 'contractor.reviewed', ['after' => $row->data]);
        $this->remember($tenant, $user, $row, $input);

        return $this->quality($tenant, $user, $contractor->area_id, $contractor->id) + ['replayed' => false];
    }

    public function quality(Tenant $tenant, User $user, string $areaId, string $contractorId): array
    {
        $actor = $this->actor($tenant, $user);
        $this->access->area($tenant, $actor, $areaId);
        abort_unless($this->manager($actor, $areaId), 404);
        $contractor = app(Planning::class)->record($tenant, $actor, 'contractor', $contractorId);
        abort_unless($contractor->area_id === $areaId, 404);
        $reviews = [];
        foreach (TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId, 'kind' => self::REVIEW])->orderByDesc('updated_at')->limit(1000)->cursor() as $row) {
            if ($row->data['contractor_id'] !== $contractorId) {
                continue;
            }
            try {
                $task = $this->task($tenant, $actor, $row->data['task_id']);
                $reviews[] = ['id' => $row->id, 'version' => $row->version, 'task_id' => $task['id'], 'task_title' => $task['title'], ...$row->data];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                if ($error->getStatusCode() !== 404) {
                    throw $error;
                }
            }
            if (count($reviews) === 100) {
                break;
            }
        }
        $score = $reviews ? round(array_sum(array_map(fn ($r) => array_sum($r['dimensions']) / 4, $reviews)) / count($reviews), 2) : null;

        return ['contractor_id' => $contractorId, 'quality_score' => $score, 'quality_label' => $score === null ? 'Unrated' : number_format($score, 2).' / 4',
            'review_count' => count($reviews), 'reviews' => $reviews, 'coverage' => 'Manager-only quality; up to 100 authorized supporting jobs from at most 1000 department evaluations. Separate from checklist completion.'];
    }
}
