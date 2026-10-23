<?php

namespace App\Http\Controllers\Trajectory;

use App\Http\Controllers\Controller;
use App\Models\Trajectory\Space;
use App\Services\Trajectory\DebtPlanService;
use Illuminate\Http\Request;

class DebtPlanController extends Controller
{
    public function save(Request $request, Space $space, DebtPlanService $plans)
    {
        return response()->json($plans->save($request->user(), $space, $request->all()))->header('Cache-Control', 'private, no-store');
    }
}
