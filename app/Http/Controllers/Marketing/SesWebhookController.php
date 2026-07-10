<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Marketing\MarketingEmailDeliveryTrackingService;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SesWebhookController extends Controller
{
    public function events(Request $request, MarketingEmailDeliveryTrackingService $tracking): JsonResponse
    {
        try {
            $message = Message::fromJsonString($request->getContent());
        } catch (\Throwable) {
            return response()->json(['ok' => false, 'error' => 'invalid_payload'], 400);
        }

        $verify = app()->environment('production') || (bool) config('marketing.messaging.responses.verify_ses_sns_signature');
        if ($verify && ! (new MessageValidator)->isValid($message)) {
            return response()->json(['ok' => false, 'error' => 'invalid_signature'], 403);
        }

        if ((string) $message['Type'] !== 'Notification') {
            return response()->json(['ok' => true, 'status' => 'confirmation_required'], 202);
        }

        $event = json_decode((string) $message['Message'], true);
        if (! is_array($event)) {
            return response()->json(['ok' => false, 'error' => 'invalid_notification'], 400);
        }

        $normalized = $this->normalizedEvent($event, (string) $message['MessageId']);
        $summary = $tracking->handleSendGridEvents([$normalized]);

        return response()->json(['ok' => true, 'matched' => $summary['matched'], 'updated' => $summary['updated']]);
    }

    /** @param array<string,mixed> $event */
    protected function normalizedEvent(array $event, string $snsMessageId): array
    {
        $type = strtolower(trim((string) ($event['eventType'] ?? $event['notificationType'] ?? '')));
        $eventName = match ($type) {
            'send' => 'processed',
            'delivery' => 'delivered',
            'open' => 'open',
            'click' => 'click',
            'bounce', 'complaint', 'reject', 'rendering failure' => 'bounce',
            default => 'processed',
        };
        $tags = collect((array) data_get($event, 'mail.tags', []))
            ->map(fn (mixed $value): mixed => is_array($value) ? ($value[0] ?? null) : $value)
            ->all();

        return [
            'event' => $eventName,
            'email' => data_get($event, 'mail.destination.0'),
            'sg_message_id' => data_get($event, 'mail.messageId'),
            'sg_event_id' => $snsMessageId,
            'timestamp' => data_get($event, 'mail.timestamp'),
            'url' => data_get($event, 'click.link'),
            'ip' => data_get($event, 'open.ipAddress', data_get($event, 'click.ipAddress')),
            'useragent' => data_get($event, 'open.userAgent', data_get($event, 'click.userAgent')),
            'custom_args' => $tags,
            'tenant_id' => $tags['tenant_id'] ?? null,
            'ses_event' => $event,
        ];
    }
}
