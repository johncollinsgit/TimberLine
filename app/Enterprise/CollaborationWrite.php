<?php

namespace App\Enterprise;

use Closure;
use Illuminate\Http\Request;

class CollaborationWrite
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('enterprise.collaboration_enabled'), 404);
        $tenant = $request->attributes->get('current_tenant');
        $access = app(WorkspaceAccess::class);
        $actor = $access->actor($tenant, $request->user(), true);
        $taskId = $request->route('task');
        if (! $taskId) {
            $taskId = \Illuminate\Support\Facades\DB::table('team_file_uploads')->where('tenant_id', $tenant->id)
                ->where('id', $request->route('upload'))->value('task_id');
        }
        $state = app(\App\Services\Team\TeamWorkRepository::class)->state($tenant->id);
        $task = $state['tasks'][$taskId] ?? null;
        abort_unless($task && filled($task['enterprise_area_id'] ?? null), 404);
        $access->area($tenant, $actor, $task['enterprise_area_id']);

        return $next($request);
    }
}
