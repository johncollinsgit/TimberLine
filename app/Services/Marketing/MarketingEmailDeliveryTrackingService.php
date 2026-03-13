<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingEmailDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class MarketingEmailDeliveryTrackingService
{
    /**
     * @param array<int,array<string,mixed>> $events
     * @return array{processed:int,matched:int,updated:int,duplicates:int,unmatched:int}
     */
    public function handleSendGridEvents(array $events): array
    {
        $summary = [
            'processed' => 0,
            'matched' => 0,
            'updated' => 0,
            'duplicates' => 0,
            'unmatched' => 0,
        ];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $summary['processed']++;
            $delivery = $this->resolveDelivery($event);
            if (! $delivery) {
                $summary['unmatched']++;
                Log::warning('marketing sendgrid callback unmatched', ['event' => $event]);
                continue;
            }

            $summary['matched']++;

            $hash = $this->eventHash($event);
            $rawPayload = (array) $delivery->raw_payload;
            $receivedHashes = collect((array) ($rawPayload['received_event_hashes'] ?? []))
                ->map(fn ($value) => (string) $value)
                ->filter()
                ->values()
                ->all();

            if (in_array($hash, $receivedHashes, true)) {
                $summary['duplicates']++;
                continue;
            }

            $occurredAt = $this->occurredAtForEvent($event);
            $mapped = $this->mapEvent((string) ($event['event'] ?? ''));

            $receivedHashes[] = $hash;
            $receivedHashes = array_slice(array_values(array_unique($receivedHashes)), -60);

            $eventLog = collect((array) ($rawPayload['events'] ?? []))
                ->push([
                    'event' => (string) ($event['event'] ?? 'unknown'),
                    'at' => $occurredAt?->toIso8601String(),
                    'sg_message_id' => $event['sg_message_id'] ?? null,
                ])
                ->slice(-30)
                ->values()
                ->all();

            $delivery->forceFill([
                'status' => $mapped['delivery_status'] ?? $delivery->status,
                'delivered_at' => ($mapped['set_delivered_at'] ?? false) && ! $delivery->delivered_at ? $occurredAt : $delivery->delivered_at,
                'opened_at' => ($mapped['set_opened_at'] ?? false) && ! $delivery->opened_at ? $occurredAt : $delivery->opened_at,
                'clicked_at' => ($mapped['set_clicked_at'] ?? false) && ! $delivery->clicked_at ? $occurredAt : $delivery->clicked_at,
                'failed_at' => ($mapped['set_failed_at'] ?? false) && ! $delivery->failed_at ? $occurredAt : $delivery->failed_at,
                'sent_at' => ($mapped['set_sent_at'] ?? false) && ! $delivery->sent_at ? $occurredAt : $delivery->sent_at,
                'raw_payload' => [
                    ...$rawPayload,
                    'last_event' => $event,
                    'events' => $eventLog,
                    'received_event_hashes' => $receivedHashes,
                ],
            ])->save();

            if ($delivery->recipient) {
                $this->updateRecipientFromDelivery($delivery->recipient, (string) ($mapped['recipient_status'] ?? ''));
            }

            $summary['updated']++;
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $event
     */
    protected function resolveDelivery(array $event): ?MarketingEmailDelivery
    {
        $customArgs = (array) ($event['custom_args'] ?? []);
        $deliveryId = (int) ($customArgs['marketing_email_delivery_id'] ?? 0);
        if ($deliveryId > 0) {
            $delivery = MarketingEmailDelivery::query()->find($deliveryId);
            if ($delivery) {
                return $delivery;
            }
        }

        $messageId = $this->normalizedMessageId((string) ($event['sg_message_id'] ?? ''));
        if ($messageId !== null) {
            $delivery = MarketingEmailDelivery::query()
                ->where('sendgrid_message_id', $messageId)
                ->orWhere('sendgrid_message_id', (string) ($event['sg_message_id'] ?? ''))
                ->first();
            if ($delivery) {
                return $delivery;
            }
        }

        $email = trim((string) ($event['email'] ?? ''));
        if ($email !== '' && $messageId !== null) {
            return MarketingEmailDelivery::query()
                ->where('email', $email)
                ->where(function ($query) use ($messageId): void {
                    $query->where('sendgrid_message_id', $messageId)
                        ->orWhereNull('sendgrid_message_id');
                })
                ->latest('id')
                ->first();
        }

        return null;
    }

    /**
     * @param array<string,mixed> $event
     */
    protected function eventHash(array $event): string
    {
        return sha1(json_encode([
            'event' => $event['event'] ?? null,
            'email' => $event['email'] ?? null,
            'sg_message_id' => $event['sg_message_id'] ?? null,
            'timestamp' => $event['timestamp'] ?? null,
            'sg_event_id' => $event['sg_event_id'] ?? null,
            'marketing_email_delivery_id' => data_get($event, 'custom_args.marketing_email_delivery_id'),
        ]));
    }

    /**
     * @return array{delivery_status:string,recipient_status:string,set_sent_at:bool,set_delivered_at:bool,set_opened_at:bool,set_clicked_at:bool,set_failed_at:bool}
     */
    protected function mapEvent(string $event): array
    {
        return match (strtolower(trim($event))) {
            'processed' => [
                'delivery_status' => 'sent',
                'recipient_status' => 'sent',
                'set_sent_at' => true,
                'set_delivered_at' => false,
                'set_opened_at' => false,
                'set_clicked_at' => false,
                'set_failed_at' => false,
            ],
            'delivered' => [
                'delivery_status' => 'delivered',
                'recipient_status' => 'delivered',
                'set_sent_at' => true,
                'set_delivered_at' => true,
                'set_opened_at' => false,
                'set_clicked_at' => false,
                'set_failed_at' => false,
            ],
            'open' => [
                'delivery_status' => 'opened',
                'recipient_status' => 'delivered',
                'set_sent_at' => true,
                'set_delivered_at' => true,
                'set_opened_at' => true,
                'set_clicked_at' => false,
                'set_failed_at' => false,
            ],
            'click' => [
                'delivery_status' => 'clicked',
                'recipient_status' => 'delivered',
                'set_sent_at' => true,
                'set_delivered_at' => true,
                'set_opened_at' => true,
                'set_clicked_at' => true,
                'set_failed_at' => false,
            ],
            'bounce', 'dropped' => [
                'delivery_status' => 'failed',
                'recipient_status' => 'failed',
                'set_sent_at' => false,
                'set_delivered_at' => false,
                'set_opened_at' => false,
                'set_clicked_at' => false,
                'set_failed_at' => true,
            ],
            default => [
                'delivery_status' => 'sent',
                'recipient_status' => 'sent',
                'set_sent_at' => true,
                'set_delivered_at' => false,
                'set_opened_at' => false,
                'set_clicked_at' => false,
                'set_failed_at' => false,
            ],
        };
    }

    protected function updateRecipientFromDelivery(MarketingCampaignRecipient $recipient, string $nextStatus): void
    {
        $nextStatus = strtolower(trim($nextStatus));
        if (! in_array($nextStatus, ['sent', 'delivered', 'failed'], true)) {
            return;
        }

        $rank = ['pending' => 0, 'queued_for_approval' => 1, 'approved' => 2, 'sending' => 3, 'sent' => 4, 'failed' => 5, 'delivered' => 6];
        $currentRank = $rank[(string) $recipient->status] ?? 0;
        $nextRank = $rank[$nextStatus] ?? $currentRank;

        if ($nextRank < $currentRank) {
            return;
        }

        $recipient->forceFill([
            'status' => $nextStatus,
            'sent_at' => in_array($nextStatus, ['sent', 'delivered'], true) ? ($recipient->sent_at ?: now()) : $recipient->sent_at,
            'delivered_at' => $nextStatus === 'delivered' ? ($recipient->delivered_at ?: now()) : $recipient->delivered_at,
            'failed_at' => $nextStatus === 'failed' ? ($recipient->failed_at ?: now()) : $recipient->failed_at,
        ])->save();
    }

    protected function normalizedMessageId(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parts = explode('.', $value);
        $base = trim((string) ($parts[0] ?? ''));

        return $base !== '' ? $base : $value;
    }

    /**
     * @param array<string,mixed> $event
     */
    protected function occurredAtForEvent(array $event): ?CarbonImmutable
    {
        $timestamp = $event['timestamp'] ?? null;
        if (is_numeric($timestamp)) {
            return CarbonImmutable::createFromTimestamp((int) $timestamp);
        }

        $value = trim((string) $timestamp);
        if ($value === '') {
            return now()->toImmutable();
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return now()->toImmutable();
        }
    }
}
