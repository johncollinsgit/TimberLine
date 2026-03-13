<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Marketing\MarketingEmailDeliveryTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SendGridWebhookController extends Controller
{
    public function events(Request $request, MarketingEmailDeliveryTrackingService $trackingService): JsonResponse
    {
        $payload = $request->json()->all();
        $events = is_array($payload) ? $payload : [];

        $summary = $trackingService->handleSendGridEvents($events);

        return response()->json([
            'ok' => true,
            'processed' => $summary['processed'],
            'matched' => $summary['matched'],
            'updated' => $summary['updated'],
            'duplicates' => $summary['duplicates'],
            'unmatched' => $summary['unmatched'],
        ]);
    }
}

