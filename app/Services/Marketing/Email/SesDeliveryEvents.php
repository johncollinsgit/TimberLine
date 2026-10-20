<?php

namespace App\Services\Marketing\Email;

use App\Models\MarketingEmailDelivery;
use App\Services\Marketing\MarketingEmailDeliveryTrackingService;
use Illuminate\Support\Facades\DB;

class SesDeliveryEvents
{
    public function handle(array $event, string $snsMessageId): array
    {
        $tags = collect((array) data_get($event, 'mail.tags', []))->map(fn ($v) => is_array($v) ? ($v[0] ?? null) : null)->all();
        $tenantId = (int) ($tags['tenant_id'] ?? 0);
        $deliveryId = (int) ($tags['marketing_email_delivery_id'] ?? 0);
        $type = strtolower((string) ($event['eventType'] ?? $event['notificationType'] ?? ''));
        $name = match ($type) {
            'send' => 'processed', 'delivery' => 'delivered', 'open' => 'open', 'click' => 'click',
            'complaint' => 'spamreport', 'bounce' => 'bounce', 'deliverydelay' => 'deferred',
            'reject', 'rendering failure' => 'dropped', default => null,
        };
        if ($name === null || $tenantId < 1 || $deliveryId < 1) {
            return ['matched' => 0, 'updated' => 0];
        }

        return DB::transaction(function () use ($event, $snsMessageId, $tags, $tenantId, $deliveryId, $type, $name): array {
            $delivery = MarketingEmailDelivery::query()->where('tenant_id', $tenantId)->where('provider', 'ses_tenant')->lockForUpdate()->find($deliveryId);
            $attempt = (array) data_get($delivery?->metadata, 'ses_attempt', []);
            $receipt = trim((string) data_get($event, 'mail.messageId'));
            // Require the exact pre-send binding; never guess the latest email row.
            if (! $delivery || ! $attempt || $receipt === ''
                || ($tags['ses:tenant-name'] ?? null) !== ($attempt['tenant_name'] ?? null)
                || ($tags['ses:configuration-set'] ?? null) !== ($attempt['configuration_set'] ?? null)
                || data_get($event, 'mail.source') !== ($attempt['from'] ?? null)
                || ($delivery->provider_message_id && $delivery->provider_message_id !== $receipt)) {
                return ['matched' => 0, 'updated' => 0];
            }
            $detail = match ($type) {
                'bounce' => (array) ($event['bounce'] ?? []),
                'complaint' => (array) ($event['complaint'] ?? []),
                'deliverydelay' => (array) ($event['deliveryDelay'] ?? []),
                default => (array) ($event[$type] ?? []),
            };
            $recipients = match ($type) {
                'bounce' => array_column((array) ($detail['bouncedRecipients'] ?? []), 'emailAddress'),
                'complaint' => array_column((array) ($detail['complainedRecipients'] ?? []), 'emailAddress'),
                'delivery' => (array) ($detail['recipients'] ?? []),
                default => (array) data_get($event, 'mail.destination', []),
            };
            $recipients = array_map(fn ($email) => strtolower(trim((string) $email)), $recipients);
            if (! in_array(strtolower(trim($delivery->email)), $recipients, true)) {
                return ['matched' => 0, 'updated' => 0];
            }
            $recipientDetail = collect((array) ($detail['bouncedRecipients'] ?? []))->first(fn ($r) => strtolower(trim((string) ($r['emailAddress'] ?? ''))) === strtolower(trim($delivery->email))) ?? [];
            $normalized = [
                'provider' => 'ses_tenant', 'event' => $name, 'email' => $delivery->email,
                'sg_message_id' => $receipt, 'sg_event_id' => $snsMessageId,
                'timestamp' => $detail['timestamp'] ?? data_get($event, 'mail.timestamp'),
                'type' => $type === 'bounce' && ($detail['bounceType'] ?? '') === 'Permanent' ? 'bounce' : 'blocked',
                'status' => $recipientDetail['status'] ?? null,
                'reason' => $recipientDetail['diagnosticCode'] ?? $detail['reason'] ?? '',
                'url' => $detail['link'] ?? null,
                'custom_args' => ['tenant_id' => $tenantId, 'marketing_email_delivery_id' => $deliveryId],
            ];

            $summary = app(MarketingEmailDeliveryTrackingService::class)->handleSendGridEvents([$normalized]);
            if ($summary['matched'] > 0) {
                // Every authenticated SES event here proves AWS accepted this
                // submission, including a later recipient bounce/complaint.
                $result = ['success' => true, 'provider' => 'ses_tenant', 'status' => 'sent',
                    'message_id' => $receipt, 'error_code' => null, 'error_message' => null,
                    'retryable' => false, 'payload' => [], 'dry_run' => false];
                app(SesSendSafety::class)->complete($deliveryId, $tenantId, $result);
            }

            return $summary;
        });
    }
}
