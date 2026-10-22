<?php

namespace App\Enterprise;

use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use Illuminate\Support\Str;

/** Durable human workflow execution over the existing tenant lock and encrypted operation history. */
class Workflows
{
    public const WORKFLOW = 'ent_workflow';

    public const RUN = 'ent_wf_run';

    public function __construct(private WorkspaceAccess $access, private RecordStore $store, private TeamWorkRepository $work, private WorkGraph $graph, private WorkflowDefinition $compiler) {}

    private function actor(Tenant $tenant, User $user, bool $write = false): array
    {
        abort_unless(config('enterprise.workflow_enabled'), 404);

        return $this->access->actor($tenant, $user, $write);
    }

    private function project(Tenant $tenant, array $actor, string $areaId, string $id, bool $write = false): array
    {
        $this->access->area($tenant, $actor, $areaId);
        $state = $this->work->state($tenant->id);
        abort_unless($this->graph->can($state, $actor, 'project', $id, $write), 404);
        $project = $this->graph->read($state, $actor, 'project', $id);
        abort_unless(($project['enterprise_area_id'] ?? null) === $areaId && ! $project['archived'], 404);

        return $project;
    }

    public function record(Tenant $tenant, array $actor, string $id): TeamOperationRecord
    {
        $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => self::WORKFLOW])->findOrFail($id);
        $this->project($tenant, $actor, $row->area_id, $row->data['project_id']);

        return $row;
    }

    private function canDesign(array $actor, string $areaId): bool
    {
        return WorkspaceAccess::permits($actor, $areaId, true, true);
    }

    public function members(Tenant $tenant, array $actor, string $areaId, string $projectId): array
    {
        $state = $this->work->state($tenant->id);
        $members = [];
        foreach ($tenant->users()->wherePivot('membership_active', true)->where('is_active', true)->whereNotNull('email_verified_at')->get() as $user) {
            $member = array_replace($actor, ['user_id' => (string) $user->id, 'role' => $actor['active_roles'][(string) $user->id] ?? 'guest']);
            if (WorkspaceAccess::permits($member, $areaId, true) && $this->graph->can($state, $member, 'project', $projectId, true)) {
                $members[] = ['id' => (string) $user->id, 'name' => $user->name, 'can_approve' => $this->canDesign($member, $areaId)];
            }
        }

        return $members;
    }

    public function scope(Tenant $tenant, User $user, string $areaId, string $projectId): array
    {
        $actor = $this->actor($tenant, $user);
        $project = $this->project($tenant, $actor, $areaId, $projectId);
        $editable = $this->graph->can($this->work->state($tenant->id), $actor, 'project', $projectId, true);

        return ['project' => ['id' => $project['id'], 'name' => $project['name']], 'members' => $this->members($tenant, $actor, $areaId, $projectId),
            'can_design' => $this->canDesign($actor, $areaId) && $editable, 'can_run' => WorkspaceAccess::permits($actor, $areaId, true) && $editable];
    }

    public function listing(Tenant $tenant, User $user, string $areaId, string $projectId): array
    {
        $scope = $this->scope($tenant, $user, $areaId, $projectId);
        $actor = $this->actor($tenant, $user);
        $items = [];
        foreach (TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId, 'kind' => self::WORKFLOW])->orderByDesc('updated_at')->limit(1000)->cursor() as $row) {
            if ($row->data['project_id'] === $projectId) {
                $items[] = $this->view($tenant, $actor, $row);
                if (count($items) === 100) {
                    break;
                }
            }
        }
        $assignee = collect($scope['members'])->firstWhere('can_approve', true)['id'] ?? null;

        return $scope + ['items' => $items, 'templates' => $assignee ? [['key' => 'maintenance', 'name' => 'Illustrative maintenance workflow', 'sample' => true, 'definition' => WorkflowDefinition::template($assignee)]] : [],
            'coverage' => 'Up to 100 workflows in this project from a bounded scan of 1000 department workflow records.', 'as_of' => now()->toIso8601String()];
    }

    private function view(Tenant $tenant, array $actor, TeamOperationRecord $row): array
    {
        $row = $this->record($tenant, $actor, $row->id);

        return ['id' => $row->id, 'name' => $row->title, 'status' => $row->status, 'area_id' => $row->area_id,
            'project_id' => $row->data['project_id'], 'version' => $row->version, 'definition' => $row->data['draft'],
            'digest' => WorkflowDefinition::digest($row->data['draft']), 'active_version' => $row->data['active']['version'] ?? null,
            'active_digest' => $row->data['active']['digest'] ?? null, 'activated_at' => $row->data['active']['at'] ?? null,
            'updated_at' => $row->updated_at?->toIso8601String()];
    }

    public function show(Tenant $tenant, User $user, string $id): array
    {
        $actor = $this->actor($tenant, $user);
        $workflow = $this->record($tenant, $actor, $id);
        $runs = [];
        foreach (TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $workflow->area_id, 'kind' => self::RUN])->orderByDesc('updated_at')->limit(1000)->cursor() as $run) {
            if ($run->data['workflow_id'] === $id && $run->data['project_id'] === $workflow->data['project_id']) {
                $runs[] = $this->runView($tenant, $actor, $workflow, $run);
                if (count($runs) === 100) {
                    break;
                }
            }
        }

        return $this->scope($tenant, $user, $workflow->area_id, $workflow->data['project_id']) + ['workflow' => $this->view($tenant, $actor, $workflow), 'runs' => $runs,
            'coverage' => 'Up to 100 runs from a bounded scan of 1000 department run records; each run preserves its activated definition.'];
    }

    private function assignments(Tenant $tenant, array $actor, string $area, string $projectId, array $definition): void
    {
        $members = array_column($this->members($tenant, $actor, $area, $projectId), null, 'id');
        foreach ($definition['nodes'] as $node) {
            if (isset($node['assignee_id'])) {
                abort_unless(isset($members[$node['assignee_id']]) && ($node['type'] !== 'approval' || $members[$node['assignee_id']]['can_approve']), 422, 'Choose existing project editors; approval requires a current coordinator or organization owner/admin.');
            }
        }
    }

    public function preview(Tenant $tenant, User $user, string $areaId, string $projectId, array $definition, array $values = []): array
    {
        $actor = $this->actor($tenant, $user);
        $this->project($tenant, $actor, $areaId, $projectId);
        $definition = $this->compiler->compile($definition);
        $this->assignments($tenant, $actor, $areaId, $projectId, $definition);
        $fields = array_merge(...array_map(fn ($n) => $n['screen']['fields'] ?? [], $definition['nodes']));
        // A preview can omit fields for screens not yet visited; supplied values still obey field types.
        $fields = array_map(fn ($f) => array_replace($f, ['required' => false]), $fields);
        $values = $this->compiler->values($fields, $values);
        $data = ['definition' => $definition, 'current_node_id' => $this->startId($definition), 'values' => $values, 'history' => []];
        $status = $this->walk($data, (string) $user->id);

        return ['definition' => $definition, 'digest' => WorkflowDefinition::digest($definition), 'status' => $status, 'current_node_id' => $data['current_node_id'],
            'history' => $data['history'], 'steps' => array_map(fn ($n) => ['id' => $n['id'], 'label' => $n['label'], 'type' => $n['type'], 'screen' => $n['screen'] ?? null], $definition['nodes']),
            'preview' => true, 'notice' => 'Preview only. No run, assignment, message or external action was created.'];
    }

    private function receipt(Tenant $tenant, User $user, array $input, string $action): ?TeamOperationAttempt
    {
        $receipt = TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
        if ($receipt) {
            abort_unless($receipt->kind === 'ent.workflow.'.$action && hash_equals($receipt->input_hash, hash('sha256', json_encode($input, JSON_THROW_ON_ERROR))), 409, 'This operation key was already used with different input.');
        }

        return $receipt;
    }

    private function remember(Tenant $tenant, User $user, TeamOperationRecord $row, array $input, string $action, array $result = []): void
    {
        TeamOperationAttempt::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $row->area_id,
            'record_id' => $row->id, 'user_id' => $user->id, 'kind' => 'ent.workflow.'.$action, 'status' => 'accepted',
            'request_id' => $input['request_id'], 'input_hash' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)), 'result' => $result]);
    }

    public function save(Tenant $tenant, User $user, array $input): array
    {
        return $this->store->locked($tenant, function () use ($tenant, $user, $input): array {
            $actor = $this->actor($tenant, $user, true);
            $this->project($tenant, $actor, $input['area_id'], $input['project_id'], true);
            abort_unless($this->canDesign($actor, $input['area_id']), 403);
            $definition = $this->compiler->compile($input['definition']);
            $this->assignments($tenant, $actor, $input['area_id'], $input['project_id'], $definition);
            if ($this->receipt($tenant, $user, $input, 'save')) {
                return $this->view($tenant, $actor, $this->record($tenant, $actor, $input['id'])) + ['replayed' => true];
            }
            if (TeamOperationRecord::query()->find($input['id'])) {
                $row = $this->record($tenant, $actor, $input['id']);
                abort_unless($row->area_id === $input['area_id'] && $row->data['project_id'] === $input['project_id'] && $row->version === $input['expected_version'], 409);
            } else {
                abort_unless($input['expected_version'] === 0, 409);
                $row = new TeamOperationRecord(['id' => $input['id'], 'tenant_id' => $tenant->id, 'area_id' => $input['area_id'], 'kind' => self::WORKFLOW,
                    'visibility' => 'area', 'source_key' => hash('sha256', 'ent-workflow:'.$input['id']), 'version' => 0, 'status' => 'draft', 'data' => []]);
            }
            $row->title = $input['name'];
            $row->data = array_replace($row->data, ['project_id' => $input['project_id'], 'draft' => $definition]);
            $row->version++;
            $row->save();
            $this->store->audit($row, $user->id, 'workflow.draft.save', ['definition' => $definition, 'name' => $row->title]);
            $this->remember($tenant, $user, $row, $input, 'save');

            return $this->view($tenant, $actor, $row) + ['replayed' => false];
        });
    }

    public function activate(Tenant $tenant, User $user, string $id, array $input, bool $pause = false): array
    {
        return $this->store->locked($tenant, function () use ($tenant, $user, $id, $input, $pause): array {
            $actor = $this->actor($tenant, $user, true);
            $row = $this->record($tenant, $actor, $id);
            $this->project($tenant, $actor, $row->area_id, $row->data['project_id'], true);
            abort_unless($this->canDesign($actor, $row->area_id), 403);
            $action = $pause ? 'pause' : 'activate';
            if ($this->receipt($tenant, $user, $input + ['workflow_id' => $id], $action)) {
                return $this->view($tenant, $actor, $row) + ['replayed' => true];
            }
            abort_unless($row->version === $input['expected_version'], 409, 'The draft changed. Review the current version.');
            if (! $pause) {
                abort_unless(($input['confirmed'] ?? false) === true, 422, 'Review and explicitly confirm this definition before activation.');
                $definition = $this->compiler->compile($row->data['draft']);
                $this->assignments($tenant, $actor, $row->area_id, $row->data['project_id'], $definition);
                $digest = WorkflowDefinition::digest($definition);
                abort_unless(hash_equals($digest, $input['digest'] ?? ''), 409, 'The reviewed definition no longer matches.');
                $row->data = array_replace($row->data, ['active' => ['definition' => $definition, 'digest' => $digest,
                    'version' => $row->version + 1, 'at' => now()->toIso8601String(), 'user_id' => (string) $user->id]]);
            }
            $row->status = $pause ? 'paused' : 'active';
            $row->version++;
            $row->save();
            $this->store->audit($row, $user->id, 'workflow.'.$action, ['active' => $row->data['active'] ?? null]);
            $this->remember($tenant, $user, $row, $input + ['workflow_id' => $id], $action);

            return $this->view($tenant, $actor, $row) + ['replayed' => false];
        });
    }

    public function start(Tenant $tenant, User $user, string $id, array $input): array
    {
        return $this->store->locked($tenant, function () use ($tenant, $user, $id, $input): array {
            $actor = $this->actor($tenant, $user, true);
            $workflow = $this->record($tenant, $actor, $id);
            $this->project($tenant, $actor, $workflow->area_id, $workflow->data['project_id'], true);
            abort_unless(WorkspaceAccess::permits($actor, $workflow->area_id, true), 403);
            if ($this->receipt($tenant, $user, $input + ['workflow_id' => $id], 'start')) {
                return $this->runView($tenant, $actor, $workflow, $this->run($tenant, $workflow, $input['id'])) + ['replayed' => true];
            }
            abort_unless($workflow->status === 'active' && isset($workflow->data['active']) && $workflow->version === $input['expected_version'], 409, 'Start requires the current active workflow version.');
            abort_if(TeamOperationRecord::query()->whereKey($input['id'])->exists(), 409);
            $active = $workflow->data['active'];
            $this->assignments($tenant, $actor, $workflow->area_id, $workflow->data['project_id'], $active['definition']);
            $data = ['workflow_id' => $id, 'project_id' => $workflow->data['project_id'], 'definition' => $active['definition'], 'active_version' => $active['version'],
                'definition_digest' => $active['digest'], 'current_node_id' => $this->startId($active['definition']), 'values' => [], 'history' => [], 'started_by' => (string) $user->id];
            $status = $this->walk($data, (string) $user->id);
            $run = TeamOperationRecord::query()->create(['id' => $input['id'], 'tenant_id' => $tenant->id, 'area_id' => $workflow->area_id, 'kind' => self::RUN,
                'visibility' => 'area', 'source_key' => hash('sha256', 'ent-wf-run:'.$input['id']), 'version' => 1, 'title' => $workflow->title,
                'status' => $status, 'data' => $data]);
            $this->store->audit($run, $user->id, 'workflow.run.start', ['active_version' => $active['version'], 'definition_digest' => $active['digest']]);
            $this->remember($tenant, $user, $run, $input + ['workflow_id' => $id], 'start');

            return $this->runView($tenant, $actor, $workflow, $run) + ['replayed' => false];
        });
    }

    private function run(Tenant $tenant, TeamOperationRecord $workflow, string $id): TeamOperationRecord
    {
        $run = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $workflow->area_id, 'kind' => self::RUN])->findOrFail($id);
        abort_unless($run->data['workflow_id'] === $workflow->id && $run->data['project_id'] === $workflow->data['project_id'], 404);

        return $run;
    }

    private function allowed(array $actor, TeamOperationRecord $workflow, array $node): bool
    {
        return $workflow->status === 'active' && WorkspaceAccess::permits($actor, $workflow->area_id, true)
            && ($node['type'] === 'form' || (isset($node['assignee_id']) && $node['assignee_id'] === $actor['user_id']
                && ($node['type'] !== 'approval' || $this->canDesign($actor, $workflow->area_id))));
    }

    private function runView(Tenant $tenant, array $actor, TeamOperationRecord $workflow, TeamOperationRecord $run): array
    {
        $this->project($tenant, $actor, $workflow->area_id, $workflow->data['project_id']);
        $data = $run->data;
        $node = collect($data['definition']['nodes'])->firstWhere('id', $data['current_node_id']);
        $can = $run->status === 'waiting' && $this->allowed($actor, $workflow, $node)
            && $this->graph->can($this->work->state($tenant->id), $actor, 'project', $data['project_id'], true);
        $collected = [];
        foreach ($data['definition']['nodes'] as $step) {
            foreach ($step['screen']['fields'] ?? [] as $field) {
                if (array_key_exists($field['key'], $data['values'])) {
                    $collected[] = ['key' => $field['key'], 'label' => $field['label'], 'type' => $field['type'], 'value' => $data['values'][$field['key']]];
                }
            }
        }

        return ['id' => $run->id, 'version' => $run->version, 'status' => $run->status, 'current_node_id' => $data['current_node_id'],
            'current_node' => $node, 'values' => $data['values'], 'collected_fields' => $collected, 'history' => $data['history'], 'active_version' => $data['active_version'],
            'definition_digest' => $data['definition_digest'], 'can_advance' => $can, 'allowed_decisions' => $can ? ($node['type'] === 'approval' ? ['approve', 'reject'] : ['complete']) : [],
            'updated_at' => $run->updated_at?->toIso8601String()];
    }

    public function advance(Tenant $tenant, User $user, string $id, string $runId, array $input): array
    {
        return $this->store->locked($tenant, function () use ($tenant, $user, $id, $runId, $input): array {
            $actor = $this->actor($tenant, $user, true);
            $workflow = $this->record($tenant, $actor, $id);
            $this->project($tenant, $actor, $workflow->area_id, $workflow->data['project_id'], true);
            $run = $this->run($tenant, $workflow, $runId);
            $data = $run->data;
            $receipt = $this->receipt($tenant, $user, $input + ['workflow_id' => $id, 'run_id' => $runId], 'advance');
            $nodeId = $receipt ? $receipt->result['node_id'] : $data['current_node_id'];
            $node = collect($data['definition']['nodes'])->firstWhere('id', $nodeId);
            abort_unless($node && $this->allowed($actor, $workflow, $node), 403, 'Only the currently authorized screen operator can complete this step.');
            if ($receipt) {
                return $this->runView($tenant, $actor, $workflow, $run) + ['replayed' => true];
            }
            abort_unless($run->status === 'waiting' && $run->version === $input['expected_version'], 409, 'This run changed. Reload its current screen.');
            $decision = $input['decision'] ?? 'complete';
            abort_unless(in_array($decision, $node['type'] === 'approval' ? ['approve', 'reject'] : ['complete'], true), 422);
            $values = $this->compiler->values($node['screen']['fields'], $input['values'] ?? []);
            $data['values'] = array_replace($data['values'], $values);
            $data['history'][] = ['node_id' => $node['id'], 'type' => $node['type'], 'label' => $node['label'], 'decision' => $decision, 'user_id' => (string) $user->id,
                'at' => now()->toIso8601String(), 'fields' => array_keys($values)];
            if ($decision === 'reject') {
                $status = 'rejected';
            } else {
                $data['current_node_id'] = $this->next($data['definition'], $node['id'], 'always');
                $status = $this->walk($data, (string) $user->id);
            }
            $run->data = $data;
            $run->status = $status;
            $run->version++;
            $run->save();
            $this->store->audit($run, $user->id, 'workflow.run.advance', ['node_id' => $node['id'], 'decision' => $decision, 'fields' => array_keys($values), 'status' => $status]);
            $this->remember($tenant, $user, $run, $input + ['workflow_id' => $id, 'run_id' => $runId], 'advance', ['node_id' => $node['id']]);

            return $this->runView($tenant, $actor, $workflow, $run) + ['replayed' => false];
        });
    }

    private function startId(array $definition): string
    {
        return collect($definition['nodes'])->firstWhere('type', 'start')['id'];
    }

    private function next(array $definition, string $nodeId, string $branch): string
    {
        foreach ($definition['edges'] as $edge) {
            if ($edge['source'] === $nodeId && $edge['branch'] === $branch) {
                return $edge['target'];
            }
        }
        abort(422, 'The workflow has no valid next connection.');
    }

    private function walk(array &$data, string $userId): string
    {
        $nodes = array_column($data['definition']['nodes'], null, 'id');
        for ($i = 0; $i < 24; $i++) {
            $node = $nodes[$data['current_node_id']];
            if (in_array($node['type'], ['form', 'assignment', 'approval'], true)) {
                return 'waiting';
            }
            $event = ['node_id' => $node['id'], 'type' => $node['type'], 'label' => $node['label'], 'user_id' => $userId, 'at' => now()->toIso8601String()];
            if ($node['type'] === 'complete') {
                $data['history'][] = $event + ['decision' => 'completed'];

                return 'completed';
            }
            $branch = 'always';
            if ($node['type'] === 'condition') {
                $c = $node['condition'];
                abort_unless(array_key_exists($c['field'], $data['values']), 422, 'The condition field has not been collected.');
                $matches = $data['values'][$c['field']] === $c['value'];
                $branch = ($c['operator'] === 'equals' ? $matches : ! $matches) ? 'yes' : 'no';
            }
            $data['history'][] = $event + ['decision' => $branch];
            $data['current_node_id'] = $this->next($data['definition'], $node['id'], $branch);
        }
        abort(422, 'The workflow exceeded its execution bound.');
    }
}
