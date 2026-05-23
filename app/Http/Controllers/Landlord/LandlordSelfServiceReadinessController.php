<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Services\Readiness\EverbranchSelfServiceReadinessService;
use Illuminate\Http\Response;

class LandlordSelfServiceReadinessController extends Controller
{
    public function __invoke(EverbranchSelfServiceReadinessService $readinessService): Response
    {
        $readiness = $readinessService->evaluate();

        return response()->view('landlord/readiness/index', [
            'overall' => (array) ($readiness['overall'] ?? []),
            'summary' => (array) ($readiness['summary'] ?? []),
            'sections' => (array) ($readiness['sections'] ?? []),
        ]);
    }
}
