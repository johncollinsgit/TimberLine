<?php

namespace App\Http\Controllers;

use App\Models\TeamArea;
use App\Models\Tenant;
use App\Services\Team\Studio\EventPerformance;
use Illuminate\Http\Request;

class TeamEventPerformanceController extends Controller
{
    public function __invoke(Request $request)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $input = $request->validate(EventPerformance::rules());
        $area = TeamArea::where('tenant_id', $tenant->id)->findOrFail($input['area']);

        return response()->json(app(EventPerformance::class)->read($tenant, $request->user(), $area, $input))->header('Cache-Control', 'private, no-store');
    }
}
