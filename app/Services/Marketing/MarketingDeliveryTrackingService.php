<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingDeliveryEvent;
use App\Models\MarketingMessageDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class MarketingDeliveryTrackingService
{
    /**
     * @param array<string,mixed> $payload
     * @return array{matched:bool,delivery_id:?int,status:string,event_id:?int}
     */
    public function handleTwilioCallback(array $payload): array
    {
        $providerMessageId = $this->nullableString($payload['MessageSid'] ?? $payload['SmsSid'] ?? null);
        $status = $this->mapProviderStatus($payload['MessageStatus'] ?? $payload['SmsStatus'] ?? null);
        $occurredAt = $this->asDate($payload['Timestamp'] ?? null) ?: now();

        if (! $providerMessageId) {
            $event = $this->appendEvent(
                delivery: null,
                provider: 'twilio',
                providerMessageId: null,
                eventType: 'webhook_received',
                eventStatus: null,
                payload: $payload,
                occurredAt: $occurredAt
            );

            Log::warning('marketing twilio callback missing message sid', ['payload' => $payload]);

            return [
                'matched' => false,
                'delivery_id' => null,
                'status' => 'ignored_missing_sid',
                'event_id' => $event?->id,
            ];
        }

        $delivery = MarketingMessageDelivery::query()
            ->where('provider', 'twilio')
            ->where('provider_message_id', $providerMessageId)
            ->first();

        $received = $this->appendEvent(
            delivery: $delivery,
            provider: 'twilio',
            providerMessageId: $providerMessageId,
            eventType: 'webhook_received',
            eventStatus: $status,
            payload: $payload,
            occurredAt: $occurredAt
        );

        if (! $delivery) {
            Log::warning('marketing twilio callback unmatched message sid', [
                'provider_message_id' => $providerMessageId,
                'payload' => $payload,
            ]);

            return [
                'matched' => false,
                'delivery_id' => null,
                'status' => 'unmatched',
                'event_id' => $received?->id,
            ];
        }

        $errorCode = $this->nullableString($payload['ErrorCode'] ?? null);
        $errorMessage = $this->nullableString($payload['ErrorMessage'] ?? null);

        $delivery->forceFill([
            'send_status' => $status,
            'error_code' => $errorCode ?: $delivery->error_code,
            'error_message' => $errorMessage ?: $delivery->error_message,
            'provider_payload' => [
                ...((array) $delivery->provider_payload),
                'last_callback' => $payload,
                'status' => $status,
            ],
            'sent_at' => in_array($status, ['sent', 'delivered', 'undelivered'], true) && ! $delivery->sent_at
                ? $occurredAt
                : $delivery->sent_at,
            'delivered_at' => $status === 'delivered' && ! $delivery->delivered_at ? $occurredAt : $delivery->delivered_at,
            'failed_at' => in_array($status, ['failed', 'undelivered', 'canceled'], true) && ! $delivery->failed_at
                ? $occurredAt
                : $delivery->failed_at,
        ])->save();

        $this->appendEvent(
            delivery: $delivery,
            provider: 'twilio',
            providerMessageId: $providerMessageId,
            eventType: 'status_updated',
            eventStatus: $status,
            payload: $payload,
            occurredAt: $occurredAt
        );

        if ($delivery->recipient) {
            $this->updateRecipientFromDelivery($delivery->recipient, $status, $occurredAt);
        }

        return [
            'matched' => true,
            'delivery_id' => (int) $delivery->id,
            'status' => $status,
            'event_id' => $received?->id,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function appendEvent(
        ?MarketingMessageDelivery $delivery,
        string $provider,
        ?string $providerMessageId,
        string $eventType,
        ?string $eventStatus,
        array $payload = [],
        mixed $occurredAt = null
    ): ?MarketingDeliveryEvent {
        $occurred = $this->asDate($occurredAt) ?: now();
        $hash = $this->eventHash(
            deliveryId: $delivery?->id,
            provider: $provider,
            providerMessageId: $providerMessageId,
            eventType: $eventType,
            eventStatus: $eventStatus,
            payload: $payload
        );

        $existing = MarketingDeliveryEvent::query()->where('event_hash', $hash)->first();
        if ($existing) {
            return $existing;
        }

        return MarketingDeliveryEvent::query()->create([
            'marketing_message_delivery_id' => $delivery?->id,
            'provider' => $provider,
            'provider_message_id' => $providerMessageId,
            'event_type' => $eventType,
            'event_status' => $eventStatus,
            'event_hash' => $hash,
            'payload' => $payload,
            'occurred_at' => $occurred,
        ]);
    }

    public function mapProviderStatus(mixed $status): string
    {
        return match (strtolower(trim((string) $status))) {
            'accepted', 'queued' => 'queued',
            'sending' => 'sending',
            'sent' => 'sent',
            'delivered' => 'delivered',
            'undelivered' => 'undelivered',
            'failed' => 'failed',
            'canceled', 'cancelled' => 'canceled',
            default => 'sent',
        };
    }

    protected function updateRecipientFromDelivery(
        MarketingCampaignRecipient $recipient,
        string $status,
        CarbonImmutable $occurredAt
    ): void {
        $statusMap = [
            'queued' => 'sending',
            'sending' => 'sending',
            'sent' => 'sent',
            'delivered' => 'delivered',
            'undelivered' => 'undelivered',
            'failed' => 'failed',
            'canceled' => 'failed',
        ];

        $recipientStatus = $statusMap[$status] ?? null;
        if (! $recipientStatus) {
            return;
        }

        $recipient->forceFill([
            'status' => $recipientStatus,
            'sent_at' => in_array($recipientStatus, ['sent', 'delivered', 'undelivered', 'failed'], true)
                ? ($recipient->sent_at ?: $occurredAt)
                : $recipient->sent_at,
            'delivered_at' => $recipientStatus === 'delivered'
                ? ($recipient->delivered_at ?: $occurredAt)
                : $recipient->delivered_at,
            'failed_at' => in_array($recipientStatus, ['failed', 'undelivered'], true)
                ? ($recipient->failed_at ?: $occurredAt)
                : $recipient->failed_at,
        ])->save();
    }

    /**
     * @param array<string,mixed> $payload
     */
    protected function eventHash(
        ?int $deliveryId,
        string $provider,
        ?string $providerMessageId,
        string $eventType,
        ?string $eventStatus,
        array $payload
    ): string {
        $payloadSignature = [
            'MessageSid' => $payload['MessageSid'] ?? null,
            'SmsSid' => $payload['SmsSid'] ?? null,
            'MessageStatus' => $payload['MessageStatus'] ?? null,
            'SmsStatus' => $payload['SmsStatus'] ?? null,
            'ErrorCode' => $payload['ErrorCode'] ?? null,
            'ErrorMessage' => $payload['ErrorMessage'] ?? null,
        ];

        return sha1(json_encode([
            'delivery_id' => $deliveryId,
            'provider' => $provider,
            'provider_message_id' => $providerMessageId,
            'event_type' => $eventType,
            'event_status' => $eventStatus,
            'payload_signature' => $payloadSignature,
        ]));
    }

    protected function asDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance(\DateTime::createFromInterface($value));
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
