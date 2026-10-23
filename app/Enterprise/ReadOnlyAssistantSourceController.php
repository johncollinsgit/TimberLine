<?php

namespace App\Enterprise;

use App\Models\TeamOperationRecord;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use Illuminate\Http\Request;

/** Exact current evidence, independent of bounded workspace lists. No work or provider action. */
class ReadOnlyAssistantSourceController
{
    public function __invoke(Request $request, string $kind, string $id, WorkspaceAccess $access)
    {
        abort_unless(config('enterprise.read_only_assistant_enabled'), 404);
        $areaId = $request->validate(['area_id' => ['required', 'uuid']])['area_id'];
        $tenant = $request->attributes->get('current_tenant');
        $actor = $access->actor($tenant, $request->user());
        $area = $access->area($tenant, $actor, $areaId);
        $section = match ($kind) {
            'request' => 'requests', 'project', 'task' => 'projects', 'building' => 'buildings', 'cost' => 'contractors', default => abort(404),
        };
        $selected = $id;
        $extra = [];
        if ($kind === 'request') {
            $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId, 'kind' => 'enterprise_request'])->findOrFail($id);
            $record = app(RequestWorkflow::class)->view($tenant, $actor, $row);
            $title = $record['title'];
            $facts = ['Status' => $record['status'], 'Priority' => $record['task']['priority'] ?? null,
                'Due date' => $record['due_on'], 'Recorded location' => $record['data']['location'] ?? null, 'Version' => $record['version']];
        } elseif ($kind === 'cost') {
            abort_unless(config('enterprise.planning_enabled'), 404);
            $planning = app(Planning::class);
            $row = $planning->record($tenant, $actor, 'cost', $id);
            abort_unless($row->area_id === $areaId, 404);
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
            abort_unless(($record['enterprise_area_id'] ?? null) === $areaId, 404);
            $title = $record['name'];
            $facts = ['Status' => $record['archived'] ? 'archived' : 'active', 'Version' => $record['version']];
        } elseif ($kind === 'building') {
            $record = app(Resources::class)->show($tenant, $request->user(), $id);
            abort_unless($record['area_id'] === $areaId && $record['kind'] === 'location' && ($record['data']['level'] ?? null) === 'facility', 404);
            $title = $record['title'];
            $facts = ['Status' => $record['status'], 'Saved location ID' => $record['id'], 'Version' => $record['version'], 'Recorded at' => $record['updated_at']];
        } else {
            $record = app(Operations::class)->show($tenant, $request->user(), $id);
            abort_unless($record['area_id'] === $areaId, 404);
            $title = $record['title'];
            $facts = ['Status' => $record['status'], 'Priority' => $record['priority'], 'Due date' => $record['due_on'],
                'Next action' => $record['next_action'] ?: null, 'Version' => $record['task_version'], 'Recorded at' => $record['updated_at']];
            $selected = $record['project_id'];
            $extra = ['task' => $id];
        }
        $fresh = $access->actor($tenant, $request->user());
        $access->area($tenant, $fresh, $areaId);
        abort_unless(hash_equals(AskEverbranch::digest($actor), AskEverbranch::digest($fresh)), 409);

        return response()->view('enterprise.read-only-assistant-source', [
            'title' => $title, 'kind' => $kind, 'sourceId' => $id, 'facts' => $facts, 'areaName' => $area->name,
            'sampleData' => (bool) config('enterprise.sample_data'), 'checkedAt' => now()->toIso8601String(),
            'workspaceUrl' => route('enterprise.index', ['tenant' => $tenant->slug, 'area_id' => $areaId, 'section' => $section, 'selected' => $selected] + $extra),
        ])->header('Cache-Control', 'private, no-store');
    }
}
