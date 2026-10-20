<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Marketing\Email\SesDeliveryEvents;
use App\Services\Marketing\Email\SesSnsVerifier;
use Aws\Sns\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SesWebhookController extends Controller
{
    public function events(Request $request, SesSnsVerifier $verifier, SesDeliveryEvents $events): JsonResponse
    {
        if (strlen($request->getContent()) > 262144) {
            return response()->json(['ok' => false], 413);
        }
        try {
            $message = Message::fromJsonString($request->getContent());
        } catch (\Throwable) {
            return response()->json(['ok' => false, 'error' => 'invalid_payload'], 400);
        }
        if (! $verifier->valid($message)) {
            return response()->json(['ok' => false, 'error' => 'invalid_signature_or_topic'], 403);
        }
        if ((string) $message['Type'] !== 'Notification') {
            // Confirm subscriptions separately after reviewing the exact topic.
            // Never follow the caller-supplied SubscribeURL.
            return response()->json(['ok' => true, 'status' => 'confirmation_required'], 202);
        }
        $event = json_decode((string) $message['Message'], true);
        if (! is_array($event)) {
            return response()->json(['ok' => false, 'error' => 'invalid_notification'], 400);
        }
        $summary = $events->handle($event, (string) $message['MessageId']);

        return response()->json(['ok' => true, 'matched' => $summary['matched'], 'updated' => $summary['updated']]);
    }
}
