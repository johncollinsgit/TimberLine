<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\AiBranch\AiWeeklyReport;
use App\Services\AiBranch\AiWorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class AiReportController extends Controller
{
    private function workspace(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    public function store(Request $request, string $tenant = '')
    {
        $workspace = $this->workspace($request);
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'week_start' => ['nullable', 'date_format:Y-m-d'],
            'audience' => ['required', 'in:executive,team'], 'audience_user_ids' => ['sometimes', 'array', 'max:50'], 'audience_user_ids.*' => ['integer', 'min:1', 'distinct']]);
        $start = $data['week_start'] ?? CarbonImmutable::now(app(AiWorkspaceContext::class)->timezone($workspace))->startOfWeek()->toDateString();
        $report = app(AiWeeklyReport::class)->capture($workspace, $request->user(), $start, $data['audience'], $data['audience_user_ids'] ?? [], $data['request_id']);

        return response()->json($report, 201)->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, string $tenant, string $report)
    {
        $workspace = $this->workspace($request);

        return response()->json(app(AiWeeklyReport::class)->read($workspace, $request->user(), $report))->header('Cache-Control', 'no-store, private');
    }

    public function print(Request $request, string $report)
    {
        $workspace = $this->workspace($request);
        $snapshot = app(AiWeeklyReport::class)->read($workspace, $request->user(), $report);

        return response()->view('ai-branch.weekly-report', ['tenant' => $workspace, 'report' => $snapshot])->header('Cache-Control', 'no-store, private');
    }
}
