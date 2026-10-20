<?php

namespace App\Services\Mail;

use App\Jobs\SendPlatformEmail;
use App\Models\PlatformEmailDelivery;
use App\Models\PlatformEmailMessage;
use App\Services\Marketing\Email\SesSendSafety;
use App\Support\Tenancy\TenantContext;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\SesV2\SesV2Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;

class PlatformSesOutbox
{
    public function recipientHash(string $email): string
    {
        return $this->recipientHashes($email)[0];
    }

    public function recipientHashes(string $email): array
    {
        return array_values(array_unique(array_map(fn ($key) => hash_hmac('sha256', strtolower(trim($email)), (string) $key),
            [config('app.key'), ...(array) config('app.previous_keys', [])])));
    }

    public function heldAddress(string $email): bool
    {
        return PlatformEmailDelivery::whereIn('recipient_hash', $this->recipientHashes($email))
            ->where(fn ($q) => $q->whereIn('state', ['submitting', 'unknown'])
                ->orWhereIn('event_state', ['hard_bounce', 'complaint']))->exists();
    }

    public function fingerprint(Email $message, Envelope $envelope): string
    {
        $headers = [];
        foreach ($message->getHeaders()->all() as $header) {
            $name = strtolower($header->getName());
            if (! in_array($name, ['date', 'message-id', 'mime-version', 'content-type', 'content-transfer-encoding', 'x-everbranch-logical-message-id'], true)
                && ! str_starts_with($name, 'x-ses-')) {
                $headers[] = [$name, $header->getBodyAsString()];
            }
        }
        sort($headers);
        $recipients = array_map(fn ($address) => strtolower($address->getAddress()), $envelope->getRecipients());
        sort($recipients);
        $parts = array_map(fn ($attachment) => hash('sha256', $attachment->toString()), $message->getAttachments());

        return hash('sha256', json_encode([$headers, $recipients, $message->getSubject(), $this->body($message->getTextBody()), $this->body($message->getHtmlBody()), $parts], JSON_THROW_ON_ERROR));
    }

    private function body(mixed $body): ?string
    {
        if (! is_resource($body)) {
            return $body;
        }
        $position = ftell($body);
        rewind($body);
        $text = stream_get_contents($body);
        fseek($body, $position);

        return $text === false ? null : $text;
    }

    /** Freeze rendered content before queue dispatch, including inside a caller transaction. */
    public function enqueue(Email $message, Envelope $envelope, bool $queue = true): array
    {
        $this->assertConfiguration();
        $recipients = array_values(array_unique(array_map(fn ($address) => strtolower(trim($address->getAddress())), $envelope->getRecipients())));
        $from = strtolower(trim($envelope->getSender()->getAddress()));
        if ($from !== strtolower((string) config('ses_migration.platform_from')) || count($message->getFrom()) !== 1
            || strtolower($message->getFrom()[0]->getAddress()) !== $from || count($recipients) > 50 || $recipients === []) {
            throw new RuntimeException('Platform SES sender or recipient context is not approved.');
        }
        $allowed = array_map('strtolower', (array) config('ses_migration.platform_recipients', []));
        foreach ($recipients as $recipient) {
            if ((! config('ses_migration.platform_allow_all_recipients') && ! in_array($recipient, $allowed, true)) || $this->heldAddress($recipient)) {
                throw new RuntimeException('Platform email recipient is not approved or requires reconciliation.');
            }
        }
        $group = app(PlatformMailQueueContext::class)->group($message);
        $fingerprint = $this->fingerprint($message, $envelope);
        $copy = clone $message;
        foreach ($copy->getHeaders()->all() as $header) {
            if (str_starts_with(strtolower($header->getName()), 'x-ses-') || in_array(strtolower($header->getName()), ['bcc', 'x-smtpapi', 'x-everbranch-logical-message-id'], true)) {
                $copy->getHeaders()->remove($header->getName());
            }
        }
        $mime = $copy->toString();
        if (strlen($mime) > (int) config('ses_migration.platform_max_bytes', 1048576)) {
            throw new RuntimeException('Platform email exceeds its reviewed payload limit.');
        }

        return DB::transaction(function () use ($recipients, $group, $fingerprint, $mime, $from, $queue): array {
            $intent = PlatformEmailMessage::firstOrCreate(['group_key' => $group], [
                'payload_hash' => $fingerprint, 'mime' => $mime, 'tenant_id' => app(TenantContext::class)->id(),
                'from_email' => $from, 'configuration_set' => config('ses_migration.platform_configuration_set'),
            ]);
            $intent = PlatformEmailMessage::whereKey($intent->id)->lockForUpdate()->firstOrFail();
            if ($intent->payload_hash !== $fingerprint) {
                throw new RuntimeException('A platform message identity cannot be reused for changed content.');
            }
            $ids = [];
            foreach ($recipients as $recipient) {
                $hash = $this->recipientHash($recipient);
                $delivery = PlatformEmailDelivery::where('platform_email_message_id', $intent->id)->whereIn('recipient_hash', $this->recipientHashes($recipient))->first();
                $delivery ??= PlatformEmailDelivery::create(['platform_email_message_id' => $intent->id, 'recipient_hash' => $hash,
                    'recipient' => $recipient,
                    'state' => $queue ? 'queued' : 'prepared', 'provider_cost_micros' => (int) config('ses_migration.approved_attempt_cost_micros'),
                ]);
                if (($queue && $delivery->state === 'prepared') || (! $queue && $delivery->state === 'queued')) {
                    throw new RuntimeException('A platform intent cannot change its acceptance mode.');
                }
                $ids[] = $delivery->id;
                if ($queue && $delivery->wasRecentlyCreated) {
                    SendPlatformEmail::dispatch($delivery->id)->afterCommit();
                }
            }

            return $ids;
        });
    }

    public function submit(int $id, bool $prepared = false): string
    {
        if (! config('ses_migration.platform_enabled')) {
            return 'paused';
        }
        $this->assertConfiguration();
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Platform SES submission cannot run inside a caller transaction.');
        }
        $delivery = PlatformEmailDelivery::with('message')->findOrFail($id);
        $readyStates = $prepared ? ['prepared'] : ['queued'];
        if (! in_array($delivery->state, $readyStates, true)) {
            return $delivery->state;
        }
        $allowed = array_map('strtolower', (array) config('ses_migration.platform_recipients', []));
        if (! config('ses_migration.platform_allow_all_recipients') && ! in_array($delivery->recipient, $allowed, true)) {
            return $this->hold($id, 'recipient_not_approved');
        }
        if ($delivery->message->from_email !== strtolower(trim((string) config('ses_migration.platform_from'))) || $delivery->message->configuration_set !== config('ses_migration.platform_configuration_set')) {
            return $this->hold($id, 'intent_context_changed');
        }
        if (! app(SesSendSafety::class)->admit(0)) {
            return 'rate_limited';
        }
        $claimed = Cache::store()->lock('ses:platform-claim', 10)->block(2, fn () => DB::transaction(function () use ($id, $readyStates): bool {
            $row = PlatformEmailDelivery::lockForUpdate()->findOrFail($id);
            if (! in_array($row->state, $readyStates, true)) {
                return false;
            }
            if ($this->heldAddress($row->recipient)) {
                $row->update(['state' => 'held', 'hold_reason' => 'prior_unreconciled_recipient']);

                return false;
            }
            $row->update(['state' => 'submitting', 'submitted_at' => now()]);

            return true;
        }));
        if (! $claimed) {
            return PlatformEmailDelivery::findOrFail($id)->state === 'queued' ? 'held' : PlatformEmailDelivery::findOrFail($id)->state;
        }
        $failureReason = null;
        try {
            $receipt = trim((string) $this->client()->sendEmail([
                'FromEmailAddress' => $delivery->message->from_email, 'Destination' => ['ToAddresses' => [$delivery->recipient]],
                'Content' => ['Raw' => ['Data' => $delivery->message->mime]], 'ConfigurationSetName' => $delivery->message->configuration_set,
                'EmailTags' => [['Name' => 'everbranch_lane', 'Value' => 'platform'], ['Name' => 'platform_delivery_id', 'Value' => (string) $id]],
            ])->get('MessageId'));
            $state = $receipt !== '' ? 'accepted' : 'unknown';
        } catch (AwsException $exception) {
            $status = (int) $exception->getStatusCode();
            $state = $status >= 400 && $status < 500 ? 'rejected' : 'unknown';
            $code = (string) $exception->getAwsErrorCode();
            // Never retain exception messages, request/response bodies or secrets.
            $code = preg_match('/^[A-Za-z0-9._-]{1,48}$/D', $code) ? $code : 'UnclassifiedAwsError';
            $failureReason = 'aws_http_'.$status.':'.$code;
            $receipt = '';
        } catch (\Throwable) {
            $state = 'unknown';
            $receipt = '';
        }
        try {
            DB::transaction(function () use ($id, $state, $receipt, $failureReason): void {
                $row = PlatformEmailDelivery::lockForUpdate()->findOrFail($id);
                if ($row->state !== 'accepted') {
                    $row->update(['state' => $state, 'provider_message_id' => $receipt !== '' ? $receipt : null,
                        'accepted_at' => $state === 'accepted' ? now() : null, 'hold_reason' => $failureReason]);
                }
            });
        } catch (\Throwable) {
            Log::critical('Platform SES receipt requires reconciliation', ['delivery_id' => $id, 'provider_message_id' => $receipt ?: null]);

            return 'unknown';
        }

        return PlatformEmailDelivery::findOrFail($id)->state;
    }

    public function assertConfiguration(): void
    {
        $connection = (string) config('ses_migration.platform_queue_connection', 'database');
        $queue = (string) config('ses_migration.platform_queue', 'ses-platform');
        if (config('mail.mailers.'.config('mail.default').'.transport') === 'ses_platform') {
            throw new RuntimeException('Queue-only platform mail requires explicit selection; provider-receipt callers require the confirmed mailer.');
        }
        if (! config('ses_migration.platform_enabled') || ! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis', 'sqs'], true)
            || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $queue) || $queue === 'default'
            || (int) config('ses_migration.approved_attempt_cost_micros') < max(1, (int) config('ses_migration.platform_min_attempt_cost_micros', 1000))) {
            throw new RuntimeException('Platform SES rollout, asynchronous queue or reviewed cost is not ready.');
        }
        foreach (['platform_from', 'platform_configuration_set', 'platform_key', 'platform_secret'] as $key) {
            if (! filled(config('ses_migration.'.$key))) {
                throw new RuntimeException('Platform SES configuration is incomplete.');
            }
        }
        if (config('ses_migration.platform_configuration_set') === 'everbranch-marketing') {
            throw new RuntimeException('Platform mail requires its own configuration set.');
        }
    }

    private function hold(int $id, string $reason): string
    {
        return DB::transaction(function () use ($id, $reason): string {
            $row = PlatformEmailDelivery::lockForUpdate()->findOrFail($id);
            if (in_array($row->state, ['queued', 'prepared'], true)) {
                $row->update(['state' => 'held', 'hold_reason' => $reason]);
            }

            return $row->state;
        });
    }

    protected function client(): SesV2Client
    {
        return new SesV2Client(['version' => 'latest', 'region' => config('services.ses.region'), 'retries' => 0,
            'credentials' => new Credentials(config('ses_migration.platform_key'), config('ses_migration.platform_secret'), config('ses_migration.platform_token')),
            'http' => ['connect_timeout' => 5, 'timeout' => 20]]);
    }
}
