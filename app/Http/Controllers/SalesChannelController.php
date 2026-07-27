<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Dashboard\DashboardDateRange;
use App\Services\Reporting\SalesChannelSummaryService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesChannelController extends Controller
{
    public function index(Request $request, DashboardDateRange $dateRanges, SalesChannelSummaryService $salesChannels): View
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 404);

        $range = $dateRanges->resolve($request->query('range'));
        $summary = $salesChannels->forTenant($tenant, $range['starts_at'], $range['ends_at']);

        return view('sales-channels.index', compact('tenant', 'range', 'summary'));
    }
}
