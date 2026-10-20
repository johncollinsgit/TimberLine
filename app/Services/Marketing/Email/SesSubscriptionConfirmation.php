<?php

namespace App\Services\Marketing\Email;

use Aws\Sns\Message;
use Aws\Sns\SnsClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SesSubscriptionConfirmation
{
    public function remember(Message $message): void
    {
        if (! $this->freshConfirmation($message) || ! app(SesSnsVerifier::class)->valid($message)) {
            return;
        }
        Cache::put($this->key((string) $message['TopicArn']), Crypt::encryptString(json_encode($message->toArray(), JSON_THROW_ON_ERROR)), now()->addHour());
    }

    public function pending(string $topic): ?Message
    {
        try {
            $encrypted = Cache::get($this->key($topic));
            $message = $encrypted ? Message::fromJsonString(Crypt::decryptString($encrypted)) : null;

            return $message && (string) $message['TopicArn'] === $topic && $this->freshConfirmation($message)
                && app(SesSnsVerifier::class)->valid($message) ? $message : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function confirm(string $topic): string
    {
        $message = $this->pending($topic);
        if (! $message) {
            throw new RuntimeException('No fresh authenticated confirmation exists for this configured topic.');
        }
        // Confirm only through the fixed regional AWS API. Never follow SubscribeURL.
        $arn = (string) $this->client()->confirmSubscription(['TopicArn' => $topic, 'Token' => (string) $message['Token']])->get('SubscriptionArn');
        if (! str_starts_with($arn, $topic.':') || ! preg_match('/:[a-f0-9-]{36}$/iD', $arn)) {
            throw new RuntimeException('AWS confirmation response needs operator reconciliation.');
        }
        Log::notice('SES SNS subscription confirmed by operator command', ['topic_arn' => $topic, 'subscription_arn' => $arn, 'sns_message_id' => $message['MessageId']]);
        Cache::forget($this->key($topic));

        return $arn;
    }

    protected function client(): SnsClient
    {
        // AWS supports token-authorized confirmation without an IAM signature.
        // This grants no general SNS permission and does not create credentials.
        return new SnsClient(['version' => 'latest', 'region' => config('services.ses.region'), 'credentials' => false,
            'endpoint' => 'https://sns.'.config('services.ses.region').'.amazonaws.com', 'retries' => 0,
            'http' => ['connect_timeout' => 5, 'timeout' => 15]]);
    }

    protected function key(string $topic): string
    {
        return 'ses:subscription-confirmation:'.hash('sha256', $topic);
    }

    protected function freshConfirmation(Message $message): bool
    {
        try {
            $timestamp = Carbon::parse((string) ($message['Timestamp'] ?? ''));

            return (string) ($message['Type'] ?? '') === 'SubscriptionConfirmation'
                && filled($message['Token'] ?? null) && filled($message['Timestamp'] ?? null)
                && $timestamp->between(now()->subHour(), now()->addMinutes(5));
        } catch (\Throwable) {
            return false;
        }
    }
}
