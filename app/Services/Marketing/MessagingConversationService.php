<?php

namespace App\Services\Marketing;

use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\MessagingConversation;
use App\Models\MessagingConversationMessage;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessagingConversationService
{
    public function __construct(
        protected MarketingIdentityNormalizer $identityNormalizer
    ) {
    }

    /**
     * @param array<string,mixed> $context
     */
    public function findOrCreateSmsConversation(
        int $tenantId,
        ?string $storeKey,
        ?MarketingProfile $profile,
        string $phone,
        array $context = []
    ): MessagingConversation {
        $identity = $this->identityNormalizer->toE164($phone) ?? trim($phone);

        $conversation = MessagingConversation::query()
            ->forTenantId($tenantId)
            ->where('channel', 'sms')
            ->where('phone', $identity)
            ->when(
                $storeKey !== null,
                fn ($query) => $query->where('store_key', $storeKey),
                fn ($query) => $query->whereNull('store_key')
            )
            ->latest('id')
            ->first();

        if (! $conversation instanceof MessagingConversation) {
            $conversation = MessagingConversation::query()->create([
                'tenant_id' => $tenantId,
                'store_key' => $storeKey,
                'channel' => 'sms',
                'marketing_profile_id' => $profile?->id,
                'phone' => $identity,
                'email' => null,
                'subject' => null,
                'status' => 'open',
                'source_type' => $this->nullableString($context['source_type'] ?? null),
                'source_id' => $this->positiveInt($context['source_id'] ?? null),
                'source_context' => is_array($context['source_context'] ?? null) ? $context['source_context'] : [],
            ]);
        } else {
            $conversation->forceFill([
                'marketing_profile_id' => $conversation->marketing_profile_id ?: $profile?->id,
                'source_type' => $conversation->source_type ?: $this->nullableString($context['source_type'] ?? null),
                'source_id' => $conversation->source_id ?: $this->positiveInt($context['source_id'] ?? null),
                'source_context' => $this->mergedContext($conversation->source_context, $context['source_context'] ?? null),
            ])->save();
        }

        return $conversation;
    }

    /**
     * @param array<string,mixed> $context
     */
    public function findOrCreateEmailConversation(
        int $tenantId,
        ?string $storeKey,
        ?MarketingProfile $profile,
        string $email,
        ?string $subject = null,
        array $context = []
    ): MessagingConversation {
        $identity = $this->identityNormalizer->normalizeEmail($email) ?? strtolower(trim($email));
        $normalizedSubject = $this->nullableString($subject);

        $conversation = MessagingConversation::query()
            ->forTenantId($tenantId)
            ->where('channel', 'email')
            ->where('email', $identity)
            ->when(
                $storeKey !== null,
                fn ($query) => $query->where('store_key', $storeKey),
                fn ($query) => $query->whereNull('store_key')
            )
            ->when(
                $normalizedSubject !== null,
                fn ($query) => $query->where(function ($inner) use ($normalizedSubject): void {
                    $inner->where('subject', $normalizedSubject)
                        ->orWhereNull('subject');
                })
            )
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();

        if (! $conversation instanceof MessagingConversation) {
            $conversation = MessagingConversation::query()->create([
                'tenant_id' => $tenantId,
                'store_key' => $storeKey,
                'channel' => 'email',
                'marketing_profile_id' => $profile?->id,
                'phone' => null,
                'email' => $identity,
                'subject' => $normalizedSubject,
                'status' => 'open',
                'source_type' => $this->nullableString($context['source_type'] ?? null),
                'source_id' => $this->positiveInt($context['source_id'] ?? null),
                'source_context' => is_array($context['source_context'] ?? null) ? $context['source_context'] : [],
            ]);
        } else {
            $conversation->forceFill([
                'marketing_profile_id' => $conversation->marketing_profile_id ?: $profile?->id,
                'subject' => $conversation->subject ?: $normalizedSubject,
                'source_type' => $conversation->source_type ?: $this->nullableString($context['source_type'] ?? null),
                'source_id' => $conversation->source_id ?: $this->positiveInt($context['source_id'] ?? null),
                'source_context' => $this->mergedContext($conversation->source_context, $context['source_context'] ?? null),
            ])->save();
        }

        return $conversation;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function appendMessage(MessagingConversation $conversation, array $attributes): MessagingConversationMessage
    {
        $timestamp = $this->messageTimestamp($attributes);
        $dedupeHash = $this->nullableString($attributes['dedupe_hash'] ?? null)
            ?? sha1(json_encode([
                'conversation_id' => (int) $conversation->id,
                'channel' => $attributes['channel'] ?? $conversation->channel,
                'direction' => $attributes['direction'] ?? null,
                'provider' => $attributes['provider'] ?? null,
                'provider_message_id' => $attributes['provider_message_id'] ?? null,
                'body' => $attributes['body'] ?? null,
                'subject' => $attributes['subject'] ?? null,
                'from_identity' => $attributes['from_identity'] ?? null,
                'to_identity' => $attributes['to_identity'] ?? null,
                'timestamp' => $timestamp?->toIso8601String(),
                'message_type' => $attributes['message_type'] ?? null,
            ]));

        $message = MessagingConversationMessage::query()
            ->where('dedupe_hash', $dedupeHash)
            ->first();

        if (! $message instanceof MessagingConversationMessage) {
            $message = MessagingConversationMessage::query()->create([
                'conversation_id' => (int) $conversation->id,
                'tenant_id' => (int) $conversation->tenant_id,
                'store_key' => $conversation->store_key,
                'marketing_profile_id' => $attributes['marketing_profile_id'] ?? $conversation->marketing_profile_id,
                'marketing_message_delivery_id' => $attributes['marketing_message_delivery_id'] ?? null,
                'marketing_email_delivery_id' => $attributes['marketing_email_delivery_id'] ?? null,
                'channel' => $attributes['channel'] ?? $conversation->channel,
                'direction' => $attributes['direction'] ?? 'inbound',
                'provider' => $attributes['provider'] ?? 'unknown',
                'provider_message_id' => $this->nullableString($attributes['provider_message_id'] ?? null),
                'dedupe_hash' => $dedupeHash,
                'body' => trim((string) ($attributes['body'] ?? '')),
                'normalized_body' => $this->nullableString($attributes['normalized_body'] ?? null),
                'subject' => $this->nullableString($attributes['subject'] ?? null),
                'from_identity' => $this->nullableString($attributes['from_identity'] ?? null),
                'to_identity' => $this->nullableString($attributes['to_identity'] ?? null),
                'received_at' => $attributes['direction'] === 'inbound' ? $timestamp : null,
                'sent_at' => $attributes['direction'] !== 'inbound' ? $timestamp : null,
                'delivery_status' => $this->nullableString($attributes['delivery_status'] ?? null),
                'message_type' => $attributes['message_type'] ?? 'normal',
                'operator_read_at' => $attributes['operator_read_at'] ?? null,
                'created_by' => $attributes['created_by'] ?? null,
                'raw_payload' => is_array($attributes['raw_payload'] ?? null) ? $attributes['raw_payload'] : [],
                'metadata' => is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [],
            ]);
        }

        $this->syncConversationSummary($conversation->fresh() ?? $conversation);

        return $message;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function appendSystemNote(
        MessagingConversation $conversation,
        string $body,
        array $metadata = [],
        ?string $providerMessageId = null
    ): MessagingConversationMessage {
        return $this->appendMessage($conversation, [
            'direction' => 'system',
            'provider' => 'app',
            'provider_message_id' => $providerMessageId,
            'body' => trim($body),
            'message_type' => 'system_note',
            'metadata' => $metadata,
            'operator_read_at' => now(),
        ]);
    }

    public function syncConversationSummary(MessagingConversation $conversation): MessagingConversation
    {
        return DB::transaction(function () use ($conversation): MessagingConversation {
            $conversation = MessagingConversation::query()->findOrFail($conversation->id);
            $messages = $conversation->messages()
                ->orderByRaw('COALESCE(received_at, sent_at, created_at) asc')
                ->get();

            /** @var MessagingConversationMessage|null $lastMessage */
            $lastMessage = $messages->last();
            $lastInbound = $messages
                ->filter(fn (MessagingConversationMessage $message): bool => $message->direction === 'inbound')
                ->last();
            $lastOutbound = $messages
                ->filter(fn (MessagingConversationMessage $message): bool => $message->direction === 'outbound')
                ->last();

            $unreadCount = $messages
                ->filter(fn (MessagingConversationMessage $message): bool => $message->direction === 'inbound' && $message->operator_read_at === null)
                ->count();

            $conversation->forceFill([
                'last_message_at' => $this->messageTimestampFromModel($lastMessage),
                'last_inbound_at' => $this->messageTimestampFromModel($lastInbound),
                'last_outbound_at' => $this->messageTimestampFromModel($lastOutbound),
                'unread_count' => $unreadCount,
                'last_message_preview' => $this->previewForMessage($lastMessage),
            ])->save();

            return $conversation;
        });
    }

    public function markConversationRead(MessagingConversation $conversation): MessagingConversation
    {
        $conversation->messages()
            ->where('direction', 'inbound')
            ->whereNull('operator_read_at')
            ->update(['operator_read_at' => now()]);

        return $this->syncConversationSummary($conversation);
    }

    public function markConversationUnread(MessagingConversation $conversation): MessagingConversation
    {
        $latestInbound = $conversation->messages()
            ->where('direction', 'inbound')
            ->latest(DB::raw('COALESCE(received_at, created_at)'))
            ->first();

        if ($latestInbound instanceof MessagingConversationMessage) {
            $latestInbound->forceFill([
                'operator_read_at' => null,
            ])->save();
        }

        return $this->syncConversationSummary($conversation);
    }

    public function ensureSmsDeliverySeed(MessagingConversation $conversation, MarketingMessageDelivery $delivery): void
    {
        $providerMessageId = $this->nullableString($delivery->provider_message_id);
        if ($providerMessageId === null && trim((string) $delivery->rendered_message) === '') {
            return;
        }

        $this->appendMessage($conversation, [
            'marketing_profile_id' => $delivery->marketing_profile_id,
            'marketing_message_delivery_id' => (int) $delivery->id,
            'channel' => 'sms',
            'direction' => 'outbound',
            'provider' => $delivery->provider ?: 'twilio',
            'provider_message_id' => $providerMessageId,
            'body' => (string) ($delivery->rendered_message ?? ''),
            'normalized_body' => (string) ($delivery->rendered_message ?? ''),
            'subject' => $this->nullableString($delivery->message_subject),
            'from_identity' => $this->nullableString($delivery->from_identifier),
            'to_identity' => $this->identityNormalizer->toE164($delivery->to_phone) ?? $this->nullableString($delivery->to_phone),
            'delivery_status' => $delivery->send_status,
            'message_type' => 'normal',
            'sent_at' => $delivery->sent_at ?? $delivery->created_at,
            'raw_payload' => is_array($delivery->provider_payload) ? $delivery->provider_payload : [],
            'metadata' => [
                'seeded_from_delivery' => true,
                'source_label' => $delivery->source_label,
            ],
        ]);
    }

    public function ensureEmailDeliverySeed(MessagingConversation $conversation, MarketingEmailDelivery $delivery): void
    {
        $providerMessageId = $this->nullableString($delivery->provider_message_id ?: $delivery->sendgrid_message_id);
        if ($providerMessageId === null && trim((string) $delivery->message_subject) === '') {
            return;
        }

        $body = trim((string) data_get($delivery->raw_payload, 'body_text', ''));
        $this->appendMessage($conversation, [
            'marketing_profile_id' => $delivery->marketing_profile_id,
            'marketing_email_delivery_id' => (int) $delivery->id,
            'channel' => 'email',
            'direction' => 'outbound',
            'provider' => $delivery->provider ?: 'sendgrid',
            'provider_message_id' => $providerMessageId,
            'body' => $body,
            'normalized_body' => $body !== '' ? $body : null,
            'subject' => $this->nullableString($delivery->message_subject),
            'from_identity' => $this->nullableString(data_get($delivery->metadata, 'from_email')),
            'to_identity' => $this->identityNormalizer->normalizeEmail($delivery->email) ?? $this->nullableString($delivery->email),
            'delivery_status' => $delivery->status,
            'message_type' => 'normal',
            'sent_at' => $delivery->sent_at ?? $delivery->created_at,
            'raw_payload' => is_array($delivery->raw_payload) ? $delivery->raw_payload : [],
            'metadata' => [
                'seeded_from_delivery' => true,
                'source_label' => $delivery->source_label,
            ],
        ]);
    }

    protected function previewForMessage(?MessagingConversationMessage $message): ?string
    {
        if (! $message instanceof MessagingConversationMessage) {
            return null;
        }

        $subject = $this->nullableString($message->subject);
        if ($message->channel === 'email' && $subject !== null) {
            return Str::limit($subject, 120);
        }

        $body = $this->nullableString($message->body);

        return $body !== null ? Str::limit(preg_replace('/\s+/', ' ', $body) ?? $body, 140) : null;
    }

    protected function mergedContext(mixed $existing, mixed $incoming): array
    {
        return [
            ...(is_array($existing) ? $existing : []),
            ...(is_array($incoming) ? $incoming : []),
        ];
    }

    /**
     * @param array<string,mixed> $attributes
     */
    protected function messageTimestamp(array $attributes): ?\DateTimeInterface
    {
        $received = $attributes['received_at'] ?? null;
        if ($received instanceof \DateTimeInterface) {
            return $received;
        }

        $sent = $attributes['sent_at'] ?? null;
        if ($sent instanceof \DateTimeInterface) {
            return $sent;
        }

        return now();
    }

    protected function messageTimestampFromModel(?MessagingConversationMessage $message): ?\DateTimeInterface
    {
        return $message?->received_at ?? $message?->sent_at ?? $message?->created_at;
    }

    protected function positiveInt(mixed $value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
