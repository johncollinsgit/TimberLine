<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Marketing\MarketingResultsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class MarketingResultsController extends Controller
{
    public function show(Request $request, MarketingResultsService $service): View
    {
        $tenantId = (int) $request->attributes->get('current_tenant_id');
        abort_unless($tenantId > 0, 403);

        return view('marketing.results', [
            'marketingResults' => $service->report(
                $tenantId,
                filled($request->query('store')) ? (string) $request->query('store') : null,
                $request->query('date_from'),
                $request->query('date_to'),
            ),
        ]);
    }
}
