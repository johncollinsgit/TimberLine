<?php

namespace App\Http\Controllers;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Services\Team\Studio\EventSalesMappingCandidates;
use Illuminate\Http\Request;

class TeamEventSalesCandidatesController extends Controller
{
    public function __invoke(Request $request)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $event = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'kind' => 'event'])->findOrFail($request->route('id'));

        return response()->json(app(EventSalesMappingCandidates::class)->read($tenant, $request->user(), $event))
            ->header('Cache-Control', 'private, no-store');
    }
}
