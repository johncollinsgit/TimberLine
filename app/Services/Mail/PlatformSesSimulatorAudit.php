<?php

namespace App\Services\Mail;

use App\Models\PlatformEmailDelivery;
use App\Services\Marketing\Email\SesEventContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/** Retain verified SNS evidence only for the three approved platform pilot intents. */
class PlatformSesSimulatorAudit
{
    private const CASES = [
        'success@simulator.amazonses.com' => 'success',
        'bounce@simulator.amazonses.com' => 'bounce',
        'complaint@simulator.amazonses.com' => 'complaint',
    ];

    public static function key(string $snsMessageId): string
    {
        return 'ses-platform-simulator:notification:'.hash('sha256', $snsMessageId);
    }

    /** Call only after SNS authentication and a matched platform delivery update. */
    public function remember(array $event, string $snsMessageId, string $signedNotification): bool
    {
        $destinations = (array) data_get($event, 'mail.destination', []);
        $recipient = count($destinations) === 1 ? strtolower(trim((string) $destinations[0])) : '';
        $case = self::CASES[$recipient] ?? null;
        $id = filter_var(data_get($event, 'mail.tags.platform_delivery_id.0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $case || ! $id || $snsMessageId === '' || strlen($signedNotification) > 262144
            || data_get($event, 'mail.tags.everbranch_lane') !== ['platform']
            || data_get($event, 'mail.tags.platform_delivery_id') !== [(string) $id]
            || data_get($event, 'mail.tags.ses:configuration-set') !== ['everbranch-platform']
            || SesEventContext::source($event) !== 'no-reply@theeverbranch.com') {
            return false;
        }

        $delivery = PlatformEmailDelivery::with('message')->find($id);
        if (! $delivery?->message || $delivery->message->tenant_id !== null
            || $delivery->message->group_key !== hash('sha256', 'explicit:ses-platform-simulator-20261020-'.$case)
            || $delivery->message->configuration_set !== 'everbranch-platform'
            || $delivery->message->from_email !== 'no-reply@theeverbranch.com'
            || ! $delivery->submitted_at || ! $delivery->provider_message_id
            || $delivery->provider_message_id !== trim((string) data_get($event, 'mail.messageId'))
            || ! in_array($delivery->recipient_hash, app(PlatformSesOutbox::class)->recipientHashes($recipient), true)
            || ! in_array(hash('sha256', $snsMessageId), (array) $delivery->event_hashes, true)) {
            return false;
        }

        try {
            $envelope = json_decode($signedNotification, true, flags: JSON_THROW_ON_ERROR);
            if (($envelope['Type'] ?? null) !== 'Notification' || ($envelope['MessageId'] ?? null) !== $snsMessageId
                || json_decode((string) ($envelope['Message'] ?? ''), true, flags: JSON_THROW_ON_ERROR) !== $event) {
                return false;
            }
        } catch (\JsonException) {
            return false;
        }

        if (! Cache::forever(self::key($snsMessageId), Crypt::encryptString($signedNotification))) {
            // SNS can retry the same notification; the delivery update is already
            // idempotent and no email is submitted by this path.
            throw new RuntimeException('Platform simulator notification could not be retained.');
        }

        return true;
    }
}
