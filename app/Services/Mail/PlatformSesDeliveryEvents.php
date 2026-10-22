<?php

namespace App\Services\Mail;

use App\Models\PlatformEmailDelivery;
use Illuminate\Support\Facades\DB;

class PlatformSesDeliveryEvents
{
    /** Called only after the shared exact-topic/account SNS signature verifier. */
    public function handle(array $event, string $snsMessageId): array
    {
        $rawTags = (array) data_get($event, 'mail.tags', []);
        // Platform submissions never supply a native tenant or marketing IDs.
        if (array_intersect(['ses:tenant', 'tenant_id', 'marketing_email_delivery_id'], array_keys($rawTags)) !== []) {
            return ['matched' => 0, 'updated' => 0];
        }
        $tags = collect($rawTags)->map(fn ($value) => is_array($value) && array_is_list($value) && count($value) === 1 ? $value[0] : null)->all();
        $id = filter_var($tags['platform_delivery_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $type = strtolower((string) ($event['eventType'] ?? ''));
        if (! $id || ($tags['everbranch_lane'] ?? '') !== 'platform' || ! in_array($type, ['send', 'delivery', 'bounce', 'complaint', 'reject', 'rendering failure', 'deliverydelay'], true)) {
            return ['matched' => 0, 'updated' => 0];
        }

        return DB::transaction(function () use ($id, $event, $snsMessageId, $tags, $type): array {
            $row = PlatformEmailDelivery::with('message')->lockForUpdate()->find($id);
            $receipt = trim((string) data_get($event, 'mail.messageId'));
            $destination = (array) data_get($event, 'mail.destination', []);
            if (! $row || ! $row->submitted_at || $receipt === '' || count($destination) !== 1
                || ! in_array($row->recipient_hash, app(PlatformSesOutbox::class)->recipientHashes((string) $destination[0]), true)
                || ($tags['ses:configuration-set'] ?? '') !== $row->message->configuration_set
                || \App\Services\Marketing\Email\SesEventContext::source($event) !== strtolower($row->message->from_email)
                || ($row->provider_message_id && $row->provider_message_id !== $receipt)) {
                return ['matched' => 0, 'updated' => 0];
            }
            $hash = hash('sha256', $snsMessageId);
            $hashes = (array) $row->event_hashes;
            if (in_array($hash, $hashes, true)) {
                return ['matched' => 1, 'updated' => 0, 'duplicates' => 1];
            }
            $state = match ($type) {
                'delivery' => 'delivered',
                'complaint' => 'complaint',
                'bounce' => data_get($event, 'bounce.bounceType') === 'Permanent' ? 'hard_bounce' : 'deferred',
                'deliverydelay' => 'deferred', 'reject', 'rendering failure' => 'rejected', default => 'processed',
            };
            if (in_array($type, ['bounce', 'complaint'], true)) {
                $recipients = array_column((array) data_get($event, $type.'.'.($type === 'bounce' ? 'bouncedRecipients' : 'complainedRecipients'), []), 'emailAddress');
                if (! collect($recipients)->contains(fn ($email) => in_array($row->recipient_hash, app(PlatformSesOutbox::class)->recipientHashes($email), true))) {
                    return ['matched' => 0, 'updated' => 0];
                }
            }
            // Suppression and delivered evidence never regress on late events.
            if (in_array($row->event_state, ['hard_bounce', 'complaint'], true)
                || ($row->event_state === 'delivered' && ! in_array($state, ['hard_bounce', 'complaint'], true))) {
                $state = $row->event_state;
            }
            $row->update(['state' => 'accepted', 'provider_message_id' => $receipt, 'accepted_at' => $row->accepted_at ?: now(),
                'event_state' => $state, 'event_at' => now(), 'event_hashes' => array_slice([...$hashes, $hash], -256)]);

            return ['matched' => 1, 'updated' => 1];
        });
    }
}
