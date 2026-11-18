<?php

namespace App\Http\Controllers\Trajectory;

use App\Http\Controllers\Controller;
use App\Models\Trajectory\Space;
use App\Services\Trajectory\AnalysisService;
use App\Services\Trajectory\BudgetEnvelopeService;
use App\Services\Trajectory\FinanceAccess;
use Illuminate\Http\Request;

class AnalysisController extends Controller
{
    public function show(Request $request, Space $space, AnalysisService $analysis)
    {
        app(FinanceAccess::class)->authorize($request->user(), $space);
        $data = $request->validate(['year' => 'sometimes|integer|between:1902,2199', 'comparison_year' => 'sometimes|integer|between:1900,2199',
            'grain' => 'sometimes|in:year,month,week', 'basis' => 'sometimes|in:spending,cash',
            'range' => 'sometimes|in:day,week,month,quarter,year,all',
            'from' => 'sometimes|required_with:through|date_format:Y-m-d|after_or_equal:1900-01-01|before:2200-01-01',
            'through' => 'sometimes|required_with:from|date_format:Y-m-d|after_or_equal:from|before:2200-01-01',
            'account_id' => 'sometimes|nullable|integer|min:1', 'program_id' => 'sometimes|nullable|integer|min:1']);
        abort_if(isset($data['through']) && $data['through'] > now($space->timezone)->toDateString(), 422, 'Actual analysis cannot include future dates.');
        abort_if(isset($data['range']) && (isset($data['from']) || isset($data['through'])), 422, 'Choose a calendar period or explicit dates.');

        return response()->json($analysis->build($request->user(), $space, $data))->header('Cache-Control', 'private, no-store');
    }

    public function budget(Request $request, Space $space, BudgetEnvelopeService $budgets)
    {
        app(FinanceAccess::class)->authorize($request->user(), $space);
        $data = $request->validate(['month' => 'sometimes|date_format:Y-m', 'program_id' => 'sometimes|nullable|integer|min:1']);

        return response()->json($budgets->build($space, $data['month'] ?? now($space->timezone)->format('Y-m'), isset($data['program_id']) ? (int) $data['program_id'] : null))->header('Cache-Control', 'private, no-store');
    }
}
