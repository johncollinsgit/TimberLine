<?php

namespace App\Http\Controllers;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Services\Team\Operations\EventUpdates;
use App\Services\Team\Operations\OperationsAccess;
use App\Services\Team\TeamFileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamEventUpdatesController extends Controller
{
    public function __construct(private EventUpdates $updates, private TeamFileService $files, private OperationsAccess $access) {}

    private function context(Request $request, string $id, bool $write = false): array
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $request->validate(['area' => 'nullable|uuid']);
        $event = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'kind' => 'event'])->findOrFail($id);
        abort_if($request->filled('area') && $request->input('area') !== $event->area_id, 404);
        // An update does not edit the read-only source record. Source comments
        // pass the separate Asana write/scope fence in EventTasks.
        $this->access->record($tenant, $request->user(), $event);
        if ($write) {
            $this->access->area($tenant, $request->user(), $event->area_id, true);
        }

        return [$tenant, $event];
    }

    public function index(Request $request, string $id)
    {
        [$tenant, $event] = $this->context($request, $id);
        $input = $request->validate(['cursor' => 'nullable|string|max:8192']);

        return response()->json($this->updates->listing($tenant, $request->user(), $event, $input['cursor'] ?? null))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $id)
    {
        [$tenant, $event] = $this->context($request, $id, true);
        $input = $request->validate(['request_id' => 'required|uuid', 'body' => 'nullable|string|max:50000', 'file_ids' => 'sometimes|array|max:10', 'file_ids.*' => 'required|uuid|distinct']);

        return response()->json($this->updates->post($tenant, $request->user(), $event, $input))->header('Cache-Control', 'private, no-store');
    }

    public function reconcile(Request $request, string $id, string $attempt)
    {
        [$tenant, $event] = $this->context($request, $id, true);
        $request->validate(['acknowledge' => 'sometimes|accepted', 'reviewed' => $request->boolean('acknowledge') ? 'required|accepted' : 'sometimes|accepted', 'review_kind' => 'sometimes|in:source,unsent']);

        return response()->json($this->updates->reconcile($tenant, $request->user(), $event, $attempt, $request->boolean('acknowledge'), $request->input('review_kind', 'source')))->header('Cache-Control', 'private, no-store');
    }

    public function beginFile(Request $request, string $id)
    {
        [$tenant, $event] = $this->context($request, $id, true);
        $input = $request->validate(['client_key' => 'required|uuid', 'filename' => 'required|string|max:240', 'mime_type' => 'required|string|max:80', 'size' => 'required|integer|min:1', 'sha256' => 'required|regex:/^[a-f0-9]{64}$/D']);
        $input['size'] = (int) $input['size'];

        return response()->json($this->files->beginEvent($tenant, $request->user(), $event->id, $input))->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, string $id, string $upload)
    {
        [$tenant, $event] = $this->context($request, $id);
        abort_unless(DB::table('team_file_uploads')->where(['tenant_id' => $tenant->id, 'target_type' => 'event', 'event_id' => $event->id, 'id' => $upload])->exists(), 404);

        return $this->files->preview($tenant, $request->user(), $upload, $event->id, 'event');
    }
}
