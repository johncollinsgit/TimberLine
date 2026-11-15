<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Inventory\StockLedger;
use App\Services\Pos\PosAccess;
use App\Services\Pos\StockPosCommerce;
use App\Services\Team\Studio\EventCommerceReport;
use App\Services\Team\Studio\EventPerformance;
use Illuminate\Http\Request;

class PosEventPerformanceController extends Controller
{
    public function __invoke(Request $request, string $tenant, string $register)
    {
        abort_unless(config('pos_live.event_stats_enabled'), 404);
        $scope = app(PosAccess::class)->register($register);
        abort_unless(in_array($scope['role'], ['owner', 'tenant_owner', 'admin'], true), 403);
        $settings = app(StockPosCommerce::class)->settings($scope);
        $pool = app(StockLedger::class)->rows('pools', $scope['tenant_id'])->where('id', $settings['inventory_pool_id'] ?? '')->first();
        abort_unless($pool && preg_match('/^event:([1-9][0-9]*)$/D', (string) $pool->event_key, $match), 422, 'Choose an event register.');
        $event = app(EventCommerceReport::class)->candidate($scope['tenant_id'], (int) $match[1]);
        abort_unless($event, 422, 'Review this register’s shared event association.');
        $rules = EventPerformance::rules();
        unset($rules['area']);
        $input = $request->validate($rules);
        $company = Tenant::findOrFail($scope['tenant_id']);
        $area = \App\Models\TeamArea::where('tenant_id', $company->id)->findOrFail($event->area_id);

        return response()->json(app(EventPerformance::class)->read($company, $request->user(), $area, $input))->header('Cache-Control', 'private, no-store');
    }
}
