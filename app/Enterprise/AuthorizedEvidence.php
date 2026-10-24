<?php

namespace App\Enterprise;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;

/** Exact authorized source evidence. Never resolves a generic operation record or a guessed text label. */
class AuthorizedEvidence
{
    public function __construct(private WorkspaceAccess $access) {}

    public function capture(Tenant $tenant, User $user, string $area, string $kind, string $id): array
    {
        abort_unless(config('enterprise.read_only_assistant_enabled'), 404);
        $actor = $this->access->actor($tenant, $user);
        $this->access->area($tenant, $actor, $area);
        $value = $this->read($tenant, $user, $actor, $area, $kind, $id);
        $fresh = $this->access->actor($tenant, $user);
        $this->access->area($tenant, $fresh, $area);
        $current = $this->read($tenant, $user, $fresh, $area, $kind, $id);
        $latest = $this->access->actor($tenant, $user);
        $this->access->area($tenant, $latest, $area);
        abort_unless(hash_equals(AskEverbranch::digest($actor), AskEverbranch::digest($fresh))
            && hash_equals(AskEverbranch::digest($actor), AskEverbranch::digest($latest))
            && hash_equals(AskEverbranch::digest($value), AskEverbranch::digest($current)), 409,
            'Current access or source changed. Check the source again.');

        return $current;
    }

    private function read(Tenant $tenant, User $user, array $actor, string $area, string $kind, string $id): array
    {
        $section = match ($kind) {
            'request' => 'requests', 'project', 'task' => 'projects', 'building' => 'buildings', 'cost' => 'contractors', default => abort(404),
        };
        $selected = $id;
        $extra = [];
        if ($kind === 'request') {
            $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area, 'kind' => 'enterprise_request'])->findOrFail($id);
            $record = app(RequestWorkflow::class)->view($tenant, $actor, $row);
            $title = $record['title'];
            $facts = ['Status' => $record['status'], 'Priority' => $record['task']['priority'] ?? null,
                'Due date' => $record['due_on'], 'Recorded location' => $record['data']['location'] ?? null, 'Version' => $record['version']];
        } elseif ($kind === 'cost') {
            abort_unless(config('enterprise.planning_enabled'), 404);
            $planning = app(Planning::class);
            $row = $planning->record($tenant, $actor, 'cost', $id);
            abort_unless($row->area_id === $area, 404);
            $record = $planning->view($tenant, $actor, $row);
            $state = app(TeamWorkRepository::class)->state($tenant->id);
            $graph = app(WorkGraph::class);
            abort_unless($graph->can($state, $actor, 'project', $record['data']['project_id']), 404);
            $project = $graph->read($state, $actor, 'project', $record['data']['project_id']);
            $title = $record['title'];
            $facts = ['Status' => $record['status'], 'Amount in minor units' => $record['data']['amount_minor'] ?? null,
                'Currency' => $record['data']['currency'] ?? null, 'Basis' => $record['data']['basis'] ?? null,
                'Project' => $project['name'], 'Version' => $record['version'], 'Recorded at' => $record['updated_at']];
        } elseif ($kind === 'project') {
            $state = app(TeamWorkRepository::class)->state($tenant->id);
            $graph = app(WorkGraph::class);
            abort_unless($graph->can($state, $actor, 'project', $id), 404);
            $record = $graph->read($state, $actor, 'project', $id);
            abort_unless(($record['enterprise_area_id'] ?? null) === $area, 404);
            $title = $record['name'];
            $facts = ['Status' => $record['archived'] ? 'archived' : 'active', 'Version' => $record['version']];
        } elseif ($kind === 'building') {
            $record = app(Resources::class)->show($tenant, $user, $id);
            abort_unless($record['area_id'] === $area && $record['kind'] === 'location' && ($record['data']['level'] ?? null) === 'facility', 404);
            $title = $record['title'];
            $facts = ['Status' => $record['status'], 'Saved location ID' => $record['id'], 'Version' => $record['version'], 'Recorded at' => $record['updated_at']];
        } else {
            $record = app(Operations::class)->show($tenant, $user, $id);
            abort_unless($record['area_id'] === $area, 404);
            $title = $record['title'];
            $facts = ['Status' => $record['status'], 'Priority' => $record['priority'], 'Due date' => $record['due_on'],
                'Next action' => $record['next_action'] ?: null, 'Version' => $record['task_version'], 'Recorded at' => $record['updated_at']];
            $selected = $record['project_id'];
            $extra = ['task' => $id];
        }

        return ['ref' => $kind.':'.$id, 'title' => $title, 'facts' => $facts, 'version' => (int) $facts['Version'],
            'url' => route('enterprise.assistant_source', ['tenant' => $tenant->slug, 'area_id' => $area, 'kind' => $kind, 'id' => $id]),
            'workspace_url' => route('enterprise.index', ['tenant' => $tenant->slug, 'area_id' => $area, 'section' => $section, 'selected' => $selected] + $extra)];
    }
}
