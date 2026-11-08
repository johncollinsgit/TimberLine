<?php

namespace App\Http\Controllers;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Services\Team\Studio\EventCommerceReport;
use Illuminate\Http\Request;

class TeamEventProfitabilityController extends Controller
{
    public function __invoke(Request $request, string $id, EventCommerceReport $report)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $event = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'kind' => 'event'])->findOrFail($id);

        return response()->json($report->forEvent($tenant, $request->user(), $event))->header('Cache-Control', 'private, no-store');
    }
}
