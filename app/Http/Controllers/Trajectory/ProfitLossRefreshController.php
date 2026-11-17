<?php

namespace App\Http\Controllers\Trajectory;

use App\Http\Controllers\Controller;
use App\Models\Trajectory\Space;
use App\Services\Trajectory\FinanceAccess;
use App\Services\Trajectory\ProfitLossRefreshService;
use Illuminate\Http\Request;

class ProfitLossRefreshController extends Controller
{
    public function store(Request $request, Space $space, FinanceAccess $access, ProfitLossRefreshService $reports)
    {
        $access->authorize($request->user(), $space);
        $data = $request->validate([
            'from' => 'required|date_format:Y-m-d|after_or_equal:1900-01-01|before_or_equal:today',
            'through' => 'required|date_format:Y-m-d|after_or_equal:from|before_or_equal:today',
            'accounting_basis' => 'required|in:cash,accrual', 'snapshot_id' => 'sometimes|nullable|integer|min:1',
            'account_id' => 'prohibited', 'location' => 'prohibited', 'department' => 'prohibited', 'business_unit' => 'prohibited', 'budget' => 'prohibited',
        ]);

        return response()->json($reports->refresh($request->user(), $space, $data['from'], $data['through'], $data['accounting_basis'], $data['snapshot_id'] ?? null))->header('Cache-Control', 'private, no-store');
    }
}
