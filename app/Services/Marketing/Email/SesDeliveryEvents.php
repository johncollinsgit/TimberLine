<?php

namespace App\Services\Marketing\Email;

use App\Models\MarketingEmailDelivery;
use App\Services\Marketing\MarketingEmailDeliveryTrackingService;
use Illuminate\Support\Facades\DB;

class SesDeliveryEvents
{
    public function handle(array $event, string $snsMessageId): array
    {
        $tags = collect((array) data_get($event, 'mail.tags', []))->map(fn ($v) => is_array($v) && array_is_list($v) && count($v) === 1 ? $v[0] : null)->all();
        $tenantId = filter_var($tags['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $deliveryId = filter_var($tags['marketing_email_delivery_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
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
            // Serialize SES feedback with the final recipient eligibility claim.
            if (! \App\Models\Tenant::query()->whereKey($tenantId)->lockForUpdate()->first()) {
                return ['matched' => 0, 'updated' => 0];
            }
            $delivery = MarketingEmailDelivery::query()->where('tenant_id', $tenantId)->where('provider', 'ses_tenant')->lockForUpdate()->find($deliveryId);
            $attempt = (array) data_get($delivery?->metadata, 'ses_attempt', []);
            $receipt = trim((string) data_get($event, 'mail.messageId'));
            $nativeTenant = SesEventContext::nativeTenant($event);
            // Require the exact pre-send binding; never guess the latest email row.
            $destination = (array) data_get($event, 'mail.destination', []);
            if (! $delivery || ! $attempt || $receipt === '' || count($destination) !== 1
                || strtolower(trim((string) ($destination[0] ?? ''))) !== strtolower(trim($delivery->email))
                || $nativeTenant === null || $nativeTenant !== ($attempt['tenant_name'] ?? null)
                || ($tags['ses:configuration-set'] ?? null) !== ($attempt['configuration_set'] ?? null)
                || SesEventContext::source($event) !== strtolower((string) ($attempt['from'] ?? ''))
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
            // Keep SES permanent feedback in the immutable attempt too. An
            // existing unsubscribe must not mask a complaint, and later list
            // reconsent/temporary events cannot clear provider safety holds.
            if ($type === 'complaint' || ($type === 'bounce' && ($detail['bounceType'] ?? '') === 'Permanent')) {
                $attempt['recipient_hold'] = ($attempt['recipient_hold'] ?? '') === 'complaint' || $type === 'complaint'
                    ? 'complaint' : 'hard_bounce';
                DB::table('marketing_email_deliveries')->where('id', $deliveryId)->update([
                    'metadata' => json_encode([...(array) $delivery->metadata, 'ses_attempt' => $attempt], JSON_THROW_ON_ERROR),
                ]);
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
