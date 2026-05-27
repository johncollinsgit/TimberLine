<?php

namespace App\Http\Controllers;

use App\Models\ClientProject;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ClientProjectController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $this->tenant($request);

        $projects = ClientProject::query()
            ->forTenantId((int) $tenant->id)
            ->with([
                'milestones' => fn ($query) => $query->orderBy('due_on')->orderBy('sort_order')->orderBy('id'),
                'updates' => fn ($query) => $query->where('visibility', 'client')->latest('published_at')->latest('id'),
            ])
            ->orderBy('sort_order')
            ->orderByRaw('due_on is null')
            ->orderBy('due_on')
            ->orderBy('id')
            ->get();

        return view('client.projects.index', [
            'tenant' => $tenant,
            'projects' => $projects,
            'statusLabels' => $this->statusLabels(),
            'healthLabels' => $this->healthLabels(),
        ]);
    }

    public function show(Request $request, ClientProject $project): View
    {
        $tenant = $this->tenant($request);
        abort_unless((int) $project->tenant_id === (int) $tenant->id, 404);

        $project->load([
            'phases.milestones',
            'milestones.phase',
            'updates' => fn ($query) => $query->where('visibility', 'client')->latest('published_at')->latest('id'),
            'links',
        ]);

        return view('client.projects.show', [
            'tenant' => $tenant,
            'project' => $project,
            'statusLabels' => $this->statusLabels(),
            'healthLabels' => $this->healthLabels(),
        ]);
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    /**
     * @return array<string,string>
     */
    protected function statusLabels(): array
    {
        return [
            'planning' => 'Planning',
            'not_started' => 'Not started',
            'in_progress' => 'In progress',
            'blocked' => 'Blocked',
            'review' => 'In review',
            'upcoming' => 'Upcoming',
            'complete' => 'Complete',
        ];
    }

    /**
     * @return array<string,string>
     */
    protected function healthLabels(): array
    {
        return [
            'on_track' => 'On track',
            'watch' => 'Watch',
            'blocked' => 'Blocked',
            'done' => 'Done',
        ];
    }
}
