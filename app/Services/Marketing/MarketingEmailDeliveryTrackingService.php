<?php

namespace App\Services\Marketing;

use App\Models\BirthdayMessageEvent;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageEngagementEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MarketingEmailDeliveryTrackingService
{
    public function __construct(
        protected MessageOrderAttributionService $messageOrderAttributionService,
        protected MessagingContactChannelStateService $channelStateService
    ) {
    }

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
            $currentStatusRank = $this->statusRank((string) $delivery->status);
            $incomingStatusRank = $this->statusRank((string) ($mapped['delivery_status'] ?? ''));
            $currentStatus = strtolower(trim((string) $delivery->status));
            $isFailureTransition = strtolower(trim((string) ($mapped['delivery_status'] ?? ''))) === 'failed';
            $hasDeliveredEngagement = in_array($currentStatus, ['delivered', 'opened', 'clicked'], true);
            $lastEventAt = $this->asDate(data_get($rawPayload, 'last_event_at'));
            $isOlderEvent = $lastEventAt instanceof CarbonImmutable
                ? $occurredAt->lessThan($lastEventAt)
                : false;
            $shouldApplyState = (bool) ($mapped['apply_state'] ?? false)
                && ! $isOlderEvent
                && $incomingStatusRank >= $currentStatusRank
                && (! $isFailureTransition || ! $hasDeliveredEngagement);
            $normalizedProviderMessageId = $this->normalizedMessageId((string) ($event['sg_message_id'] ?? ''));

            $receivedHashes[] = $hash;
            $receivedHashes = array_slice(array_values(array_unique($receivedHashes)), -60);

            $eventLog = collect((array) ($rawPayload['events'] ?? []))
                ->push([
                    'event' => (string) ($event['event'] ?? 'unknown'),
                    'at' => $occurredAt?->toIso8601String(),
                    'sg_message_id' => $event['sg_message_id'] ?? null,
                    'applied' => $shouldApplyState,
                ])
                ->slice(-30)
                ->values()
                ->all();

            $nextLastEventAt = $isOlderEvent && $lastEventAt instanceof CarbonImmutable
                ? $lastEventAt
                : $occurredAt;

            $delivery->forceFill([
                'provider_message_id' => $delivery->provider_message_id ?: $normalizedProviderMessageId,
                'sendgrid_message_id' => $delivery->sendgrid_message_id ?: $normalizedProviderMessageId,
                'status' => $shouldApplyState ? ((string) ($mapped['delivery_status'] ?? $delivery->status)) : $delivery->status,
                'delivered_at' => $shouldApplyState && ($mapped['set_delivered_at'] ?? false) && ! $delivery->delivered_at
                    ? $occurredAt
                    : $delivery->delivered_at,
                'opened_at' => $shouldApplyState && ($mapped['set_opened_at'] ?? false) && ! $delivery->opened_at
                    ? $occurredAt
                    : $delivery->opened_at,
                'clicked_at' => $shouldApplyState && ($mapped['set_clicked_at'] ?? false) && ! $delivery->clicked_at
                    ? $occurredAt
                    : $delivery->clicked_at,
                'failed_at' => $shouldApplyState && ($mapped['set_failed_at'] ?? false) && ! $delivery->failed_at
                    ? $occurredAt
                    : $delivery->failed_at,
                'sent_at' => $shouldApplyState && ($mapped['set_sent_at'] ?? false) && ! $delivery->sent_at
                    ? $occurredAt
                    : $delivery->sent_at,
                'raw_payload' => [
                    ...$rawPayload,
                    'last_event' => $event,
                    'events' => $eventLog,
                    'received_event_hashes' => $receivedHashes,
                    'last_event_at' => $nextLastEventAt?->toIso8601String(),
                ],
            ])->save();

            $engagementEvent = $this->recordEngagementEvent($delivery, $event, $occurredAt);
            if ($engagementEvent instanceof MarketingMessageEngagementEvent
                && strtolower(trim((string) $engagementEvent->event_type)) === 'click') {
                $this->messageOrderAttributionService->syncForClickEvent($engagementEvent);
            }

            if ($delivery->recipient && $shouldApplyState) {
                $this->updateRecipientFromDelivery($delivery->recipient, (string) ($mapped['recipient_status'] ?? ''));
            }

            $this->syncChannelStateFromEvent($delivery, (string) ($event['event'] ?? ''), $occurredAt, $event);

            $this->syncBirthdayMessageEvent($delivery, $event, $occurredAt);

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
                ->where('provider_message_id', $messageId)
                ->orWhere('provider_message_id', (string) ($event['sg_message_id'] ?? ''))
                ->orWhere('sendgrid_message_id', $messageId)
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
                    $query->where('provider_message_id', $messageId)
                        ->orWhere('sendgrid_message_id', $messageId)
                        ->orWhereNull('provider_message_id')
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
     * @return array{
     *   delivery_status:string,
     *   recipient_status:string,
     *   set_sent_at:bool,
     *   set_delivered_at:bool,
     *   set_opened_at:bool,
     *   set_clicked_at:bool,
     *   set_failed_at:bool,
     *   apply_state:bool
     * }
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
                'apply_state' => true,
            ],
            'delivered' => [
                'delivery_status' => 'delivered',
                'recipient_status' => 'delivered',
                'set_sent_at' => true,
                'set_delivered_at' => true,
                'set_opened_at' => false,
                'set_clicked_at' => false,
                'set_failed_at' => false,
                'apply_state' => true,
            ],
            'open' => [
                'delivery_status' => 'opened',
                'recipient_status' => 'delivered',
                'set_sent_at' => true,
                'set_delivered_at' => true,
                'set_opened_at' => true,
                'set_clicked_at' => false,
                'set_failed_at' => false,
                'apply_state' => true,
            ],
            'click' => [
                'delivery_status' => 'clicked',
                'recipient_status' => 'delivered',
                'set_sent_at' => true,
                'set_delivered_at' => true,
                'set_opened_at' => true,
                'set_clicked_at' => true,
                'set_failed_at' => false,
                'apply_state' => true,
            ],
            'bounce', 'bounced', 'dropped', 'drop', 'blocked', 'spamreport', 'spam_report' => [
                'delivery_status' => 'failed',
                'recipient_status' => 'failed',
                'set_sent_at' => false,
                'set_delivered_at' => false,
                'set_opened_at' => false,
                'set_clicked_at' => false,
                'set_failed_at' => true,
                'apply_state' => true,
            ],
            'unsubscribe', 'group_unsubscribe' => [
                'delivery_status' => 'failed',
                'recipient_status' => 'failed',
                'set_sent_at' => false,
                'set_delivered_at' => false,
                'set_opened_at' => false,
                'set_clicked_at' => false,
                'set_failed_at' => false,
                'apply_state' => false,
            ],
            'deferred' => [
                'delivery_status' => 'sent',
                'recipient_status' => 'sent',
                'set_sent_at' => true,
                'set_delivered_at' => false,
                'set_opened_at' => false,
                'set_clicked_at' => false,
                'set_failed_at' => false,
                'apply_state' => true,
            ],
            default => [
                'delivery_status' => '',
                'recipient_status' => '',
                'set_sent_at' => false,
                'set_delivered_at' => false,
                'set_opened_at' => false,
                'set_clicked_at' => false,
                'set_failed_at' => false,
                'apply_state' => false,
            ],
        };
    }

    /**
     * @param array<string,mixed> $event
     */
    protected function syncChannelStateFromEvent(
        MarketingEmailDelivery $delivery,
        string $eventType,
        CarbonImmutable $occurredAt,
        array $event
    ): void {
        $normalized = strtolower(trim($eventType));
        $status = match ($normalized) {
            'bounce', 'bounced' => 'bounced',
            'dropped', 'drop', 'blocked', 'spamreport', 'spam_report' => 'suppressed',
            'unsubscribe', 'group_unsubscribe' => 'unsubscribed',
            default => null,
        };

        if ($status === null) {
            return;
        }

        $this->channelStateService->markEmailStatus(
            tenantId: (int) $delivery->tenant_id,
            profile: $delivery->profile,
            email: $delivery->email,
            status: $status,
            reason: $normalized,
            providerSource: 'sendgrid_events',
            metadata: [
                'marketing_email_delivery_id' => (int) $delivery->id,
                'provider_message_id' => $delivery->provider_message_id,
                'event' => $event,
            ],
            occurredAt: $occurredAt
        );
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

    protected function syncBirthdayMessageEvent(MarketingEmailDelivery $delivery, array $event, CarbonImmutable $occurredAt): void
    {
        if (strtolower(trim((string) ($delivery->campaign_type ?? ''))) !== 'birthday') {
            return;
        }

        $birthdayEvent = $this->resolveBirthdayMessageEvent($delivery);
        if (! $birthdayEvent) {
            return;
        }

        $metadata = is_array($birthdayEvent->metadata) ? $birthdayEvent->metadata : [];
        $metadata['canonical_delivery_id'] = (int) $delivery->id;
        $metadata['canonical_delivery_status'] = (string) ($delivery->status ?? '');
        $metadata['last_webhook_event'] = strtolower(trim((string) ($event['event'] ?? 'unknown')));
        $metadata['last_webhook_at'] = $occurredAt->toIso8601String();

        $birthdayEvent->forceFill([
            'provider' => trim((string) ($delivery->provider ?? '')) !== ''
                ? (string) $delivery->provider
                : $birthdayEvent->provider,
            'provider_message_id' => trim((string) ($delivery->provider_message_id ?? '')) !== ''
                ? (string) $delivery->provider_message_id
                : $birthdayEvent->provider_message_id,
            'status' => $this->birthdayEventStatusFromDelivery($delivery),
            'sent_at' => $birthdayEvent->sent_at ?: $delivery->sent_at,
            'delivered_at' => $birthdayEvent->delivered_at ?: $delivery->delivered_at,
            'opened_at' => $birthdayEvent->opened_at ?: $delivery->opened_at,
            'clicked_at' => $birthdayEvent->clicked_at ?: $delivery->clicked_at,
            'metadata' => $metadata,
        ])->save();
    }

    protected function resolveBirthdayMessageEvent(MarketingEmailDelivery $delivery): ?BirthdayMessageEvent
    {
        $rawPayload = is_array($delivery->raw_payload) ? $delivery->raw_payload : [];
        $metadata = is_array($delivery->metadata) ? $delivery->metadata : [];

        $eventKey = trim((string) ($rawPayload['event_key'] ?? ''));
        if ($eventKey !== '') {
            $event = BirthdayMessageEvent::query()->where('event_key', $eventKey)->first();
            if ($event) {
                return $event;
            }
        }

        $providerMessageId = trim((string) ($delivery->provider_message_id ?? ''));
        if ($providerMessageId !== '') {
            $event = BirthdayMessageEvent::query()
                ->where('provider_message_id', $providerMessageId)
                ->latest('id')
                ->first();
            if ($event) {
                return $event;
            }
        }

        $issuanceId = $this->positiveInt($metadata['birthday_reward_issuance_id'] ?? null);
        if ($issuanceId !== null) {
            $event = BirthdayMessageEvent::query()
                ->where('birthday_reward_issuance_id', $issuanceId)
                ->where('channel', 'email')
                ->latest('id')
                ->first();
            if ($event) {
                return $event;
            }
        }

        $profileId = $this->positiveInt($delivery->marketing_profile_id);
        if ($profileId !== null) {
            return BirthdayMessageEvent::query()
                ->where('marketing_profile_id', $profileId)
                ->where('channel', 'email')
                ->where(function (Builder $query): void {
                    $query
                        ->where('campaign_type', 'birthday_email')
                        ->orWhere('campaign_type', 'birthday');
                })
                ->latest('id')
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $event
     */
    protected function recordEngagementEvent(
        MarketingEmailDelivery $delivery,
        array $event,
        CarbonImmutable $occurredAt
    ): ?MarketingMessageEngagementEvent {
        if (! Schema::hasTable('marketing_message_engagement_events')) {
            return null;
        }

        $eventType = strtolower(trim((string) ($event['event'] ?? '')));
        if (! in_array($eventType, ['open', 'click'], true)) {
            return null;
        }

        $normalizedType = $eventType === 'open' ? 'open' : 'click';
        $url = $this->nullableString($event['url'] ?? null);
        $normalizedUrl = $this->normalizedUrl($url);
        $urlDomain = $this->urlDomain($normalizedUrl ?? $url);
        $storeKey = $this->nullableString(
            $delivery->store_key
            ?? data_get($delivery->metadata, 'shopify_store_key')
            ?? data_get($delivery->raw_payload, 'shopify_store_key')
        );
        $tenantId = $this->positiveInt($delivery->tenant_id);
        $profileId = $this->positiveInt($delivery->marketing_profile_id);
        $providerEventId = $this->nullableString($event['sg_event_id'] ?? null);
        $providerMessageId = $this->normalizedMessageId((string) ($event['sg_message_id'] ?? ''))
            ?? $this->nullableString($event['sg_message_id'] ?? null)
            ?? $this->nullableString($delivery->provider_message_id);

        $eventHash = hash('sha256', implode('|', [
            (string) ($delivery->id ?? 0),
            $normalizedType,
            (string) ($providerEventId ?? ''),
            (string) ($providerMessageId ?? ''),
            (string) ($event['timestamp'] ?? ''),
            (string) ($url ?? ''),
        ]));

        return MarketingMessageEngagementEvent::query()->firstOrCreate(
            ['event_hash' => $eventHash],
            [
                'tenant_id' => $tenantId,
                'store_key' => $storeKey,
                'marketing_email_delivery_id' => (int) $delivery->id,
                'marketing_message_delivery_id' => null,
                'marketing_profile_id' => $profileId,
                'channel' => 'email',
                'event_type' => $normalizedType,
                'provider' => $this->nullableString($delivery->provider) ?? 'sendgrid',
                'provider_event_id' => $providerEventId,
                'provider_message_id' => $providerMessageId,
                'link_label' => $this->deriveLinkLabel($event, $url),
                'url' => $url,
                'normalized_url' => $normalizedUrl,
                'url_domain' => $urlDomain,
                'ip_address' => $this->nullableString($event['ip'] ?? null),
                'user_agent' => $this->nullableString($event['useragent'] ?? null),
                'payload' => $event,
                'occurred_at' => $occurredAt,
            ]
        );
    }

    /**
     * @param  array<string,mixed>  $event
     */
    protected function deriveLinkLabel(array $event, ?string $url): ?string
    {
        $provided = $this->nullableString($event['link_label'] ?? null)
            ?? $this->nullableString($event['link_name'] ?? null);
        if ($provided !== null) {
            return Str::limit($provided, 180);
        }

        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $trackedLabel = $this->nullableString($query['mf_link_label'] ?? null);
        if ($trackedLabel !== null) {
            return Str::limit(urldecode($trackedLabel), 180);
        }

        $path = trim((string) ($parts['path'] ?? ''));
        if ($path === '' || $path === '/') {
            return $this->nullableString((string) ($parts['host'] ?? null));
        }

        $segment = trim((string) basename($path), '/');

        return $segment !== '' ? Str::limit(urldecode($segment), 180) : null;
    }

    protected function normalizedUrl(?string $url): ?string
    {
        $url = $this->nullableString($url);
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === '') {
            return null;
        }

        $scheme = strtolower(trim((string) ($parts['scheme'] ?? 'https')));
        $path = trim((string) ($parts['path'] ?? ''));
        $path = $path !== '' ? $path : '/';
        $path = '/' . ltrim($path, '/');

        $queryString = trim((string) ($parts['query'] ?? ''));
        if ($queryString === '') {
            return $scheme.'://'.$host.$path;
        }

        parse_str($queryString, $query);
        if (! is_array($query)) {
            return $scheme.'://'.$host.$path;
        }

        foreach ([
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term',
            'gclid',
            'fbclid',
            'msclkid',
            '_hsenc',
            '_hsmi',
        ] as $trackedKey) {
            unset($query[$trackedKey]);
        }

        if ($query === []) {
            return $scheme.'://'.$host.$path;
        }

        ksort($query);
        $normalizedQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $normalizedQuery !== ''
            ? $scheme.'://'.$host.$path.'?'.$normalizedQuery
            : $scheme.'://'.$host.$path;
    }

    protected function urlDomain(?string $url): ?string
    {
        $url = $this->nullableString($url);
        if ($url === null) {
            return null;
        }

        $host = strtolower(trim((string) (parse_url($url, PHP_URL_HOST) ?? '')));

        return $host !== '' ? $host : null;
    }

    protected function birthdayEventStatusFromDelivery(MarketingEmailDelivery $delivery): string
    {
        $status = strtolower(trim((string) ($delivery->status ?? '')));

        if (in_array($status, ['clicked', 'opened', 'delivered', 'sent', 'failed'], true)) {
            return $status;
        }

        return $status === '' ? 'sent' : $status;
    }

    protected function statusRank(string $status): int
    {
        return match (strtolower(trim($status))) {
            'sending' => 10,
            'sent' => 20,
            'delivered' => 30,
            'bounced' => 35,
            'opened' => 40,
            'clicked' => 50,
            'failed' => 60,
            default => 0,
        };
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

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
