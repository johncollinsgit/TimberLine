<?php

namespace App\Http\Controllers\Trajectory;

use App\Http\Controllers\Controller;
use App\Jobs\Trajectory\SyncBank;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Space;
use App\Services\Trajectory\SimpleFinService;
use App\Services\Trajectory\WorkspaceService;
use Illuminate\Http\Request;

class SimpleFinController extends Controller
{
    public function store(Request $request, Space $space, SimpleFinService $simplefin, WorkspaceService $workspaces)
    {
        $workspaces->manage($request->user(), $space);
        $data = $request->validate(['setup_token' => 'required|string|max:4096', 'request_id' => 'required|uuid', 'connection_id' => 'nullable|integer']);
        $connection = empty($data['connection_id']) ? null : Connection::where('space_id', $space->id)->findOrFail($data['connection_id']);
        $connection = $simplefin->connect($space, $request->user(), $data['setup_token'], $data['request_id'], $request->session()->getId(), $connection);
        if (! empty($connection->coverage['account_spaces'])) {
            SyncBank::dispatch($connection->id);
        }

        return response()->json(['ok' => true, 'connection_id' => $connection->id, 'needs_mapping' => true])->header('Cache-Control', 'private, no-store');
    }
}
