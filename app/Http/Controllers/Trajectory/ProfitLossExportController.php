<?php

namespace App\Http\Controllers\Trajectory;

use App\Http\Controllers\Controller;
use App\Models\Trajectory\Space;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\FinanceAccess;
use App\Services\Trajectory\ProfitLossExportService;
use Illuminate\Http\Request;

class ProfitLossExportController extends Controller
{
    public function show(Request $request, Space $space, string $format, FinanceAccess $access, DashboardService $dashboard, ProfitLossExportService $exports)
    {
        $access->authorize($request->user(), $space);
        abort_unless($space->kind === 'business', 404);
        abort_unless(in_array($format, ['csv', 'pdf', 'print'], true), 404);
        $data = $request->validate([
            'range' => 'nullable|in:day,week,month,quarter,year,all,custom',
            'from' => 'required_if:range,custom|nullable|date_format:Y-m-d|after_or_equal:1900-01-01|before_or_equal:today',
            'through' => 'required_if:range,custom|nullable|date_format:Y-m-d|after_or_equal:from|before_or_equal:today',
            'accounting_basis' => 'sometimes|in:cash,accrual', 'comparison' => 'sometimes|in:previous_period,previous_year',
            'account_id' => 'prohibited', 'location' => 'prohibited', 'department' => 'prohibited', 'business_unit' => 'prohibited', 'budget' => 'prohibited',
        ]);
        $payload = $dashboard->build($space, $data['range'] ?? 'month', null, $data['from'] ?? null, $data['through'] ?? null, false, $request->user(), null, $data['accounting_basis'] ?? 'accrual');
        $statement = $exports->statement($space->name, $payload['business']['profit_loss'], $data['comparison'] ?? 'previous_period');
        $filename = 'profit-loss-'.$statement['report']['period']['start'].'-'.$statement['report']['period']['end'];
        $response = match ($format) {
            'csv' => response($exports->csv($statement))->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="'.$filename.'.csv"'),
            'pdf' => response($exports->pdf($statement))->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="'.$filename.'.pdf"'),
            default => response()->view('trajectory.profit-loss-statement', ['statement' => $statement, 'exporter' => $exports, 'pdf' => false]),
        };

        return $response->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
