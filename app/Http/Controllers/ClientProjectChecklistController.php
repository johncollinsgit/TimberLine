<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\ClientProjects\ProjectChecklistService;
use Illuminate\Http\Request;

class ClientProjectChecklistController extends Controller
{
    public function index(Request $request, ProjectChecklistService $checklists)
    {
        $tenant = $this->tenant($request);
        $checklists->assertMembership($request->user(), $tenant);

        return response()->view('client.projects.checklist', [
            'tenant' => $tenant,
            'projects' => $checklists->projects($tenant),
            'isOperator' => $checklists->isOperator($request->user()),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, int $task, ProjectChecklistService $checklists)
    {
        $validated = $request->validate(['completed' => ['required', 'boolean']]);
        $tenant = $this->tenant($request);
        $checklists->setComplete($tenant, $request->user(), $task, (bool) $validated['completed']);

        if ($request->expectsJson()) {
            return response()->json(['completed' => (bool) $validated['completed']]);
        }

        return redirect()->route('client.projects.checklist', ['tenant' => $tenant->slug])
            ->withFragment('task-'.$task)->with('status', 'Checklist saved.');
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }
}
