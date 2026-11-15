<?php

namespace App\Http\Controllers;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Services\Team\Studio\EventSalesHistory;
use Illuminate\Http\Request;

class TeamEventSalesHistoryController extends Controller
{
    public function __invoke(Request $request, EventSalesHistory $history)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $event = TeamOperationRecord::where('tenant_id', $tenant->id)->findOrFail($request->route('id'));

        return response()->json($history->read($tenant, $request->user(), $event))->header('Cache-Control', 'private, no-store');
    }
}
