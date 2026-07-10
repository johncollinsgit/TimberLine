<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Marketing\MarketingEmailDeliveryTrackingService;
use App\Services\Marketing\SendGridEventWebhookSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SendGridWebhookController extends Controller
{
    public function events(
        Request $request,
        MarketingEmailDeliveryTrackingService $trackingService,
        SendGridEventWebhookSignatureVerifier $signatureVerifier,
    ): JsonResponse {
        if (! $signatureVerifier->verify(
            $request->getContent(),
            $request->header('X-Twilio-Email-Event-Webhook-Signature'),
            $request->header('X-Twilio-Email-Event-Webhook-Timestamp'),
        )) {
            return response()->json(['ok' => false, 'error' => 'invalid_signature'], 403);
        }

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
