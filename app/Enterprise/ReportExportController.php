<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class ReportExportController
{
    public function show(Request $request, ReportExport $reports)
    {
        return response()->json($this->report($request, $reports))->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function json(Request $request, ReportExport $reports)
    {
        return $this->show($request, $reports)->header('Content-Disposition', 'attachment; filename="everbranch-period-activity.json"');
    }

    public function csv(Request $request, ReportExport $reports)
    {
        return response($reports->csv($this->report($request, $reports)))->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="everbranch-period-activity.csv"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    private function report(Request $request, ReportExport $reports): array
    {
        return $reports->build($request->attributes->get('current_tenant'), $request->user(), $request->query());
    }
}
