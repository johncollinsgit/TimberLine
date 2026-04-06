<?php

namespace App\Services\Marketing;

use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\MessagingConversation;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class TwilioIncomingMessageService
{
    protected const STOP_KEYWORDS = ['STOP', 'STOPALL'];
    protected const UNSUBSCRIBE_KEYWORDS = ['UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'];
    protected const HELP_KEYWORDS = ['HELP'];
    protected const START_KEYWORDS = ['START', 'UNSTOP'];

    public function __construct(
        protected MessagingConversationService $conversationService,
        protected MessagingContactChannelStateService $channelStateService,
        protected MarketingIdentityNormalizer $identityNormalizer
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,matched:bool,status:string,tenant_id:?int,conversation_id:?int,message_type:?string}
     */
    public function handleInbound(array $payload): array
    {
        $fromPhone = $this->identityNormalizer->toE164($payload['From'] ?? null);
        $toPhone = $this->identityNormalizer->toE164($payload['To'] ?? null);
        $body = trim((string) ($payload['Body'] ?? ''));
        $providerMessageId = $this->nullableString($payload['MessageSid'] ?? $payload['SmsSid'] ?? null);
        $occurredAt = $this->occurredAt($payload);
        $classification = $this->classify($body);
        $matchedDelivery = $this->resolveDelivery($payload, $fromPhone, $toPhone);
        $profile = null;
        $tenantId = null;
        $storeKey = null;
        $conversationContext = [];

        if ($matchedDelivery instanceof MarketingMessageDelivery) {
            $tenantId = (int) $matchedDelivery->tenant_id;
            $storeKey = $this->nullableString($matchedDelivery->store_key);
            $profile = $matchedDelivery->profile;
            $conversationContext = [
                'source_type' => 'marketing_message_delivery',
                'source_id' => (int) $matchedDelivery->id,
                'source_context' => [
                    'campaign_id' => $matchedDelivery->campaign_id,
                    'campaign_recipient_id' => $matchedDelivery->campaign_recipient_id,
                    'delivery_id' => (int) $matchedDelivery->id,
                    'source_label' => $matchedDelivery->source_label,
                    'sender_key' => data_get($matchedDelivery->provider_payload, 'sender_key'),
                ],
            ];
        } else {
            $profile = $this->resolveUniqueProfile($fromPhone);
            if ($profile instanceof MarketingProfile) {
                $tenantId = (int) $profile->tenant_id;
            }
        }

        if ($tenantId === null || $fromPhone === null) {
            Log::warning('messaging inbound sms unmatched', [
                'from' => $fromPhone,
                'to' => $toPhone,
                'provider_message_id' => $providerMessageId,
                'payload' => $payload,
            ]);

            return [
                'ok' => true,
                'matched' => false,
                'status' => 'unmatched_tenant',
                'tenant_id' => null,
                'conversation_id' => null,
                'message_type' => null,
            ];
        }

        $conversation = $this->conversationService->findOrCreateSmsConversation(
            tenantId: $tenantId,
            storeKey: $storeKey,
            profile: $profile,
            phone: $fromPhone,
            context: $conversationContext
        );

        if ($matchedDelivery instanceof MarketingMessageDelivery) {
            $this->conversationService->ensureSmsDeliverySeed($conversation, $matchedDelivery);
        }

        $message = $this->conversationService->appendMessage($conversation, [
            'marketing_profile_id' => $profile?->id,
            'channel' => 'sms',
            'direction' => 'inbound',
            'provider' => 'twilio',
            'provider_message_id' => $providerMessageId,
            'body' => $body,
            'normalized_body' => $classification['normalized_body'],
            'from_identity' => $fromPhone,
            'to_identity' => $toPhone,
            'received_at' => $occurredAt,
            'message_type' => $classification['message_type'],
            'raw_payload' => $payload,
            'metadata' => [
                'keyword' => $classification['keyword'],
            ],
        ]);

        $conversation = $conversation->fresh() ?? $conversation;
        $status = 'received';

        if ($message->wasRecentlyCreated) {
            if (in_array($classification['keyword'], ['stop', 'unsubscribe'], true)) {
                $this->channelStateService->markSmsUnsubscribed(
                    tenantId: $tenantId,
                    profile: $profile,
                    phone: $fromPhone,
                    reason: $classification['keyword'],
                    providerSource: 'twilio_inbound',
                    metadata: [
                        'provider_message_id' => $providerMessageId,
                    ],
                    occurredAt: $occurredAt
                );

                $conversation->forceFill([
                    'status' => 'opted_out',
                ])->save();

                $this->conversationService->appendSystemNote(
                    $conversation,
                    'SMS opt-out recorded from inbound reply.',
                    [
                        'event' => 'sms_opt_out',
                        'provider_message_id' => $providerMessageId,
                    ],
                    'system-optout-' . ($providerMessageId ?? sha1($fromPhone . $occurredAt->toIso8601String()))
                );
                $status = 'opted_out';
            } elseif ($classification['keyword'] === 'help') {
                $conversation->forceFill([
                    'status' => in_array((string) $conversation->status, ['closed', 'archived'], true) ? 'open' : $conversation->status,
                ])->save();
                $status = 'help';
            } elseif ($classification['keyword'] === 'start' && (bool) config('marketing.messaging.responses.allow_start_resubscribe', false)) {
                $this->channelStateService->markSmsSubscribed(
                    tenantId: $tenantId,
                    profile: $profile,
                    phone: $fromPhone,
                    reason: 'start_keyword',
                    providerSource: 'twilio_inbound',
                    metadata: [
                        'provider_message_id' => $providerMessageId,
                    ],
                    occurredAt: $occurredAt
                );

                $conversation->forceFill([
                    'status' => 'open',
                ])->save();

                $this->conversationService->appendSystemNote(
                    $conversation,
                    'SMS resubscribe recorded from START/UNSTOP reply.',
                    [
                        'event' => 'sms_resubscribe',
                        'provider_message_id' => $providerMessageId,
                    ],
                    'system-resubscribe-' . ($providerMessageId ?? sha1($fromPhone . $occurredAt->toIso8601String()))
                );
                $status = 'resubscribed';
            } elseif (! in_array((string) $conversation->status, ['opted_out', 'archived'], true)) {
                $conversation->forceFill([
                    'status' => 'open',
                ])->save();
            }
        } else {
            $status = 'duplicate';
        }

        return [
            'ok' => true,
            'matched' => true,
            'status' => $status,
            'tenant_id' => $tenantId,
            'conversation_id' => (int) $conversation->id,
            'message_type' => $classification['message_type'],
        ];
    }

    protected function resolveDelivery(array $payload, ?string $fromPhone, ?string $toPhone): ?MarketingMessageDelivery
    {
        $replyToMessageId = $this->nullableString($payload['OriginalRepliedMessageSid'] ?? null);
        if ($replyToMessageId !== null) {
            $delivery = MarketingMessageDelivery::query()
                ->where('provider_message_id', $replyToMessageId)
                ->latest('id')
                ->first();
            if ($delivery instanceof MarketingMessageDelivery) {
                return $delivery;
            }
        }

        $fromCandidates = $this->identityNormalizer->phoneMatchCandidates($fromPhone);
        $toCandidates = $this->identityNormalizer->phoneMatchCandidates($toPhone);
        if ($fromCandidates === []) {
            return null;
        }

        if ($toCandidates !== []) {
            $matched = MarketingMessageDelivery::query()
                ->whereIn('to_phone', $fromCandidates)
                ->whereIn('from_identifier', $toCandidates)
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->first();
            if ($matched instanceof MarketingMessageDelivery) {
                return $matched;
            }
        }

        return MarketingMessageDelivery::query()
            ->whereIn('to_phone', $fromCandidates)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();
    }

    protected function resolveUniqueProfile(?string $phone): ?MarketingProfile
    {
        $phoneCandidates = $this->identityNormalizer->phoneMatchCandidates($phone);
        if ($phoneCandidates === []) {
            return null;
        }

        $profiles = MarketingProfile::query()
            ->whereIn('normalized_phone', array_map(
                fn (string $value): string => preg_replace('/\D+/', '', $value) ?? $value,
                $phoneCandidates
            ))
            ->limit(2)
            ->get();

        return $profiles->count() === 1 ? $profiles->first() : null;
    }

    /**
     * @return array{keyword:string,message_type:string,normalized_body:?string}
     */
    protected function classify(string $body): array
    {
        $trimmed = trim($body);
        $normalized = strtoupper(preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed);

        if (in_array($normalized, self::STOP_KEYWORDS, true)) {
            return ['keyword' => 'stop', 'message_type' => 'stop', 'normalized_body' => $normalized];
        }
        if (in_array($normalized, self::UNSUBSCRIBE_KEYWORDS, true)) {
            return ['keyword' => 'unsubscribe', 'message_type' => 'unsubscribe', 'normalized_body' => $normalized];
        }
        if (in_array($normalized, self::HELP_KEYWORDS, true)) {
            return ['keyword' => 'help', 'message_type' => 'help', 'normalized_body' => $normalized];
        }
        if (in_array($normalized, self::START_KEYWORDS, true)) {
            return ['keyword' => 'start', 'message_type' => 'normal', 'normalized_body' => $normalized];
        }

        return [
            'keyword' => 'normal',
            'message_type' => 'normal',
            'normalized_body' => $trimmed !== '' ? $trimmed : null,
        ];
    }

    protected function occurredAt(array $payload): CarbonImmutable
    {
        $value = $this->nullableString($payload['Timestamp'] ?? null);
        if ($value !== null) {
            try {
                return CarbonImmutable::parse($value);
            } catch (\Throwable) {
                // fall through
            }
        }

        return now()->toImmutable();
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
