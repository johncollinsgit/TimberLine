<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Team\Operations\EventTasks;
use App\Services\Team\Operations\OperationsAccess;
use Illuminate\Http\Request;

class TeamEventTasksController extends Controller
{
    public function __invoke(Request $request, EventTasks $tasks, OperationsAccess $access)
    {
        $action = $request->route('action', 'listing');
        $id = $request->route('id');
        $kind = $request->route('kind', 'task');
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $request->validate(['area' => 'required|uuid', 'connection_id' => 'nullable|uuid', 'cursor' => 'nullable|string|max:8192', 'parent_id' => 'nullable|regex:/^[0-9]{1,120}$/']);
        $area = $access->area($tenant, $request->user(), $request->input('area'));
        abort_unless($area->profile === 'events', 404);
        if ($action === 'listing') {
            $result = $tasks->listing($tenant, $request->user(), $area, $request->input('connection_id'), $request->input('cursor'));
        } elseif ($action === 'detail') {
            $request->validate(['connection_id' => 'required|uuid']);
            $result = $tasks->detail($tenant, $request->user(), $area, $request->input('connection_id'), $id, $kind, $request->input('cursor'), $request->input('parent_id'));
        } elseif ($action === 'prepare') {
            $input = $request->validate(['connection_id' => 'required|uuid', 'task_id' => 'required|regex:/^[0-9]{1,120}$/', 'request_id' => 'required|uuid', 'version' => 'required|string|size:64',
                'changes' => 'required|array:name,due_on,start_on,completed|min:1|max:4', 'changes.name' => 'sometimes|required|string|max:500',
                'changes.due_on' => 'sometimes|nullable|date_format:Y-m-d', 'changes.start_on' => 'sometimes|nullable|date_format:Y-m-d', 'changes.completed' => 'sometimes|boolean']);
            if (isset($input['changes']['completed'])) {
                $input['changes']['completed'] = filter_var($input['changes']['completed'], FILTER_VALIDATE_BOOLEAN);
            }
            $input['parent_id'] = $request->input('parent_id');
            $result = $tasks->prepare($tenant, $request->user(), $area, $input);
        } else {
            $request->validate(['reviewed' => 'accepted', 'acknowledge' => 'sometimes|accepted']);
            $result = $action === 'dispatch' ? $tasks->dispatch($tenant, $request->user(), $area, $id) : $tasks->reconcile($tenant, $request->user(), $area, $id, $request->boolean('acknowledge'));
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
