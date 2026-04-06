<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingMessageDelivery;
use App\Services\Marketing\EmbeddedMessagingCampaignDispatchService;
use App\Services\Marketing\MarketingDeliveryTrackingService;
use App\Services\Marketing\TwilioIncomingMessageService;
use App\Services\Marketing\TwilioSmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class TwilioWebhookController extends Controller
{
    public function status(
        Request $request,
        TwilioSmsService $twilioSmsService,
        MarketingDeliveryTrackingService $trackingService,
        EmbeddedMessagingCampaignDispatchService $dispatchService
    ): JsonResponse {
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

        $deliveryId = (int) ($result['delivery_id'] ?? 0);
        if ($deliveryId > 0) {
            $delivery = MarketingMessageDelivery::query()->find($deliveryId);
            if ($delivery instanceof MarketingMessageDelivery) {
                $dispatchService->handleTwilioDeliveryCallback(
                    delivery: $delivery,
                    providerStatus: (string) ($result['status'] ?? ''),
                    errorCode: trim((string) ($payload['ErrorCode'] ?? '')) ?: null,
                    errorMessage: trim((string) ($payload['ErrorMessage'] ?? '')) ?: null
                );
            }
        }

        return response()->json([
            'ok' => true,
            'matched' => (bool) $result['matched'],
            'delivery_id' => $result['delivery_id'],
            'status' => $result['status'],
        ]);
    }

    public function inbound(
        Request $request,
        TwilioSmsService $twilioSmsService,
        TwilioIncomingMessageService $incomingMessageService
    ): Response {
        $payload = $request->all();

        $signature = (string) $request->header('X-Twilio-Signature', '');
        $requestUrl = (string) $request->fullUrl();
        if (! $twilioSmsService->validateSignature($requestUrl, $payload, $signature)) {
            Log::warning('marketing twilio inbound signature validation failed', [
                'url' => $requestUrl,
                'payload' => $payload,
            ]);

            return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 403, [
                'Content-Type' => 'application/xml',
            ]);
        }

        $incomingMessageService->handleInbound($payload);

        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200, [
            'Content-Type' => 'application/xml',
        ]);
    }
}
