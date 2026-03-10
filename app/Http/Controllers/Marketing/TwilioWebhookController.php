<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Marketing\MarketingDeliveryTrackingService;
use App\Services\Marketing\TwilioSmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TwilioWebhookController extends Controller
{
    public function status(Request $request, TwilioSmsService $twilioSmsService, MarketingDeliveryTrackingService $trackingService): JsonResponse
    {
        $payload = $request->all();

        $signature = (string) $request->header('X-Twilio-Signature', '');
        $requestUrl = (string) $request->fullUrl();
        if (! $twilioSmsService->validateSignature($requestUrl, $payload, $signature)) {
            Log::warning('marketing twilio callback signature validation failed', [
                'url' => $requestUrl,
                'payload' => $payload,
            ]);

            return response()->json(['ok' => false, 'error' => 'invalid_signature'], 403);
        }

        $result = $trackingService->handleTwilioCallback($payload);

        return response()->json([
            'ok' => true,
            'matched' => (bool) $result['matched'],
            'delivery_id' => $result['delivery_id'],
            'status' => $result['status'],
        ]);
    }
}
