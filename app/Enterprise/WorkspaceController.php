<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class WorkspaceController
{
    public function show(Request $request)
    {
        abort_unless(config('enterprise.workflow_enabled'), 404);
        $tenant = $request->attributes->get('current_tenant');
        $input = $request->validate(['area_id' => ['required', 'uuid'], 'project_id' => ['required', 'uuid']]);
        // Reuse the same server projection as the operating APIs before mounting any UI.
        $bootstrap = app(Workflows::class)->listing($tenant, $request->user(), $input['area_id'], $input['project_id']);

        return response()->view('enterprise.workspace', ['tenant' => $tenant, 'bootstrap' => $bootstrap, 'scope' => $input])
            ->header('Cache-Control', 'private, no-store');
    }
}
