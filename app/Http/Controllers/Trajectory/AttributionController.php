<?php

namespace App\Http\Controllers\Trajectory;

use App\Http\Controllers\Controller;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Services\Trajectory\AttributionService;
use App\Services\Trajectory\FinanceAccess;
use App\Services\Trajectory\WorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttributionController extends Controller
{
    public function definitions(Request $request, Space $space, FinanceAccess $access, AttributionService $service)
    {
        $access->authorize($request->user(), $space);

        return response()->json(['space_id' => $space->id, 'categories' => app(WorkspaceService::class)->categories($space),
            'category_definitions' => $service->categoryDefinitions($space), 'programs' => $service->programs($space)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function saveDefinition(Request $request, Space $space, AttributionService $service, ?Record $record = null)
    {
        $data = $request->validate(['kind' => ['required', Rule::in(['category_definition', 'program'])], 'name' => 'required|string|max:160',
            'key' => 'required|string|max:40', 'direction' => ['sometimes', Rule::in(['expense', 'income'])],
            'parent_record_id' => 'nullable|integer|min:1', 'active' => 'sometimes|boolean', 'version' => 'required_with:record|integer|min:1']);
        abort_if($record && $record->space_id !== $space->id, 404);
        abort_if($record && ! isset($data['version']), 422, 'Use the current definition version.');
        $result = $service->saveDefinition($request->user(), $space, $data['kind'], $data['name'], $data, $record, $data['version'] ?? null);

        return response()->json(['record' => $result->only(['id', 'space_id', 'kind', 'name', 'data', 'version', 'active'])])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Space $space, Transaction $transaction, AttributionService $service)
    {
        return response()->json($service->describe($request->user(), $space, $transaction))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Space $space, Transaction $transaction, AttributionService $service)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'lines' => 'required|array|min:1|max:50']);

        return response()->json($service->save($request->user(), $space, $transaction, $data['lines'], $data['version']))->header('Cache-Control', 'private, no-store');
    }

    public function undo(Request $request, Space $space, Transaction $transaction, AttributionService $service)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'event_id' => 'required|integer|min:1']);

        return response()->json($service->undo($request->user(), $space, $transaction, $data['version'], $data['event_id']))->header('Cache-Control', 'private, no-store');
    }
}
