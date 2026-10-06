<?php

namespace App\Http\Controllers;

use App\Services\FleetTracking\FleetLocationIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FleetTrackingWebhookController extends Controller
{
    public function bouncie(Request $request, FleetLocationIngestionService $locations): JsonResponse
    {
        return response()->json(['ok' => true, ...$locations->ingestBouncie($request)]);
    }
}
