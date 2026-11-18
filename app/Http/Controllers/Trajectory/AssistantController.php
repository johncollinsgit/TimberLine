<?php

namespace App\Http\Controllers\Trajectory;

use App\Http\Controllers\Controller;
use App\Models\Trajectory\AssistantTurn;
use App\Models\Trajectory\Space;
use App\Services\Trajectory\AssistantService;
use App\Services\Trajectory\FinanceAccess;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function index(Request $request, Space $space, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        $rows = AssistantTurn::where('space_id', $space->id)->where('user_id', $request->user()->id)->orderByDesc('id')->limit(40)->get()->reverse()->values()->map(fn ($turn) => [
            'id' => $turn->id, 'question' => $turn->question, 'response' => $turn->response, 'context' => $turn->context, 'created_at' => $turn->created_at->toIso8601String(),
        ]);

        return response()->json(['rows' => $rows])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Space $space, AssistantService $assistant, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        $data = $request->validate([
            'question' => 'required|string|max:2000', 'request_id' => 'required|uuid', 'use_ai' => 'sometimes|boolean',
            'context' => 'sometimes|array:from,through,account_id,program_id,level,direction,category,subcategory,transaction_ids,transaction_versions,space_id,basis,selection_truncated,focused_transaction',
            'context.selection_truncated' => 'sometimes|boolean', 'context.focused_transaction' => 'sometimes|boolean',
            'context.basis' => 'sometimes|in:spending,cash',
            'context.from' => 'sometimes|date_format:Y-m-d|before_or_equal:today', 'context.through' => 'required_with:context.from|date_format:Y-m-d|after_or_equal:context.from|before_or_equal:today',
            'context.account_id' => 'nullable|integer|min:1', 'context.program_id' => 'nullable|integer|min:1',
            'context.level' => 'sometimes|string|max:40', 'context.direction' => 'sometimes|in:in,out,income,expense,both',
            'context.category' => 'nullable|string|max:80', 'context.subcategory' => 'nullable|string|max:80',
            'context.space_id' => 'sometimes|integer|min:1',
            'context.transaction_ids' => 'sometimes|array|max:100', 'context.transaction_ids.*' => 'integer|distinct|min:1',
            'context.transaction_versions' => 'sometimes|array|max:100', 'context.transaction_versions.*' => 'integer|min:1',
        ]);
        abort_if(isset($data['context']['space_id']) && $data['context']['space_id'] !== $space->id, 403);

        return response()->json($assistant->respond($request->user(), $space, $data['question'], $data['context'] ?? [], $data['request_id'], $data['use_ai'] ?? false))->header('Cache-Control', 'private, no-store');
    }

    public function apply(Request $request, Space $space, AssistantTurn $turn, AssistantService $assistant)
    {
        return response()->json($assistant->apply($request->user(), $space, $turn))->header('Cache-Control', 'private, no-store');
    }

    public function undo(Request $request, Space $space, AssistantTurn $turn, AssistantService $assistant)
    {
        return response()->json($assistant->undo($request->user(), $space, $turn))->header('Cache-Control', 'private, no-store');
    }
}
