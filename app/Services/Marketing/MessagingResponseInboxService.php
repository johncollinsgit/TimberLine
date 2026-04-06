<?php

namespace App\Services\Marketing;

use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MessagingConversation;
use App\Models\MessagingConversationMessage;
use App\Models\User;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MessagingResponseInboxService
{
    public function __construct(
        protected MessagingConversationService $conversationService,
        protected MessagingContactChannelStateService $channelStateService,
        protected MessagingEmailReplyAddressService $replyAddressService,
        protected TwilioSmsService $twilioSmsService,
        protected SendGridEmailService $sendGridEmailService,
        protected MarketingIdentityNormalizer $identityNormalizer
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{summary:array<string,int>,conversations:array<int,array<string,mixed>>}
     */
    public function index(int $tenantId, ?string $storeKey, array $filters = []): array
    {
        $channel = in_array(($filters['channel'] ?? null), ['sms', 'email'], true)
            ? (string) $filters['channel']
            : 'sms';
        $search = trim((string) ($filters['search'] ?? ''));
        $filter = strtolower(trim((string) ($filters['filter'] ?? 'open')));
        $perPage = max(10, min(50, (int) ($filters['per_page'] ?? 25)));

        $query = MessagingConversation::query()
            ->forTenantId($tenantId)
            ->with(['profile', 'assignee'])
            ->where('channel', $channel)
            ->when(
                $storeKey !== null,
                fn (Builder $builder) => $builder->where('store_key', $storeKey),
                fn (Builder $builder) => $builder->whereNull('store_key')
            );

        $query = $this->applyConversationFilter($query, $filter, auth()->user());

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('phone', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . strtolower($search) . '%')
                    ->orWhere('subject', 'like', '%' . $search . '%')
                    ->orWhere('last_message_preview', 'like', '%' . $search . '%')
                    ->orWhereHas('profile', function (Builder $profileQuery) use ($search): void {
                        $profileQuery->where('first_name', 'like', '%' . $search . '%')
                            ->orWhere('last_name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . strtolower($search) . '%')
                            ->orWhere('phone', 'like', '%' . $search . '%');
                    });
            });
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return [
            'summary' => $this->summary($tenantId, $storeKey),
            'conversations' => $paginator->getCollection()
                ->map(fn (MessagingConversation $conversation): array => $this->serializeConversation($conversation))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function show(int $tenantId, ?string $storeKey, int $conversationId): array
    {
        $conversation = $this->conversationQuery($tenantId, $storeKey)
            ->with(['profile', 'assignee', 'messages.creator'])
            ->findOrFail($conversationId);

        return [
            'summary' => $this->summary($tenantId, $storeKey),
            'conversation' => $this->serializeConversation($conversation, true),
            'messages' => $conversation->messages()
                ->orderByRaw('COALESCE(received_at, sent_at, created_at) asc')
                ->get()
                ->map(fn (MessagingConversationMessage $message): array => $this->serializeMessage($message))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function updateConversation(int $tenantId, ?string $storeKey, int $conversationId, array $payload, ?User $actor = null): array
    {
        $conversation = $this->conversationQuery($tenantId, $storeKey)->findOrFail($conversationId);
        $action = strtolower(trim((string) ($payload['action'] ?? '')));

        switch ($action) {
            case 'mark_read':
                $this->conversationService->markConversationRead($conversation);
                break;
            case 'mark_unread':
                $this->conversationService->markConversationUnread($conversation);
                break;
            case 'close':
                $conversation->forceFill(['status' => 'closed'])->save();
                break;
            case 'reopen':
                $conversation->forceFill(['status' => 'open'])->save();
                break;
            case 'archive':
                $conversation->forceFill(['status' => 'archived'])->save();
                break;
            case 'assign_to_me':
                $conversation->forceFill(['assigned_to' => $actor?->id])->save();
                break;
            case 'unassign':
                $conversation->forceFill(['assigned_to' => null])->save();
                break;
            default:
                throw ValidationException::withMessages([
                    'action' => ['Choose a valid conversation action.'],
                ]);
        }

        return $this->show($tenantId, $storeKey, $conversationId);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function reply(int $tenantId, ?string $storeKey, int $conversationId, array $payload, ?User $actor = null): array
    {
        $conversation = $this->conversationQuery($tenantId, $storeKey)
            ->with(['profile', 'messages'])
            ->findOrFail($conversationId);

        $validated = Validator::make($payload, [
            'body' => ['required', 'string', 'max:10000'],
            'subject' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $body = trim((string) $validated['body']);
        $subject = $this->nullableString($validated['subject'] ?? null);

        if ($conversation->channel === 'sms') {
            $this->sendSmsReply($conversation, $body, $actor);
        } else {
            $this->sendEmailReply($conversation, $body, $subject, $actor);
        }

        return $this->show($tenantId, $storeKey, $conversationId);
    }

    /**
     * @return array<string,int>
     */
    public function summary(int $tenantId, ?string $storeKey): array
    {
        $baseQuery = MessagingConversation::query()
            ->forTenantId($tenantId)
            ->when(
                $storeKey !== null,
                fn (Builder $builder) => $builder->where('store_key', $storeKey),
                fn (Builder $builder) => $builder->whereNull('store_key')
            );

        $optedOutToday = MessagingConversation::query()
            ->forTenantId($tenantId)
            ->when(
                $storeKey !== null,
                fn (Builder $builder) => $builder->where('store_key', $storeKey),
                fn (Builder $builder) => $builder->whereNull('store_key')
            )
            ->where('status', 'opted_out')
            ->whereDate('updated_at', now()->toDateString())
            ->count();

        return [
            'sms_unread' => (clone $baseQuery)->where('channel', 'sms')->sum('unread_count'),
            'email_unread' => (clone $baseQuery)->where('channel', 'email')->sum('unread_count'),
            'open' => (clone $baseQuery)->where('status', 'open')->count(),
            'needs_follow_up' => (clone $baseQuery)->where('status', 'needs_follow_up')->count(),
            'opted_out_today' => $optedOutToday,
        ];
    }

    protected function sendSmsReply(MessagingConversation $conversation, string $body, ?User $actor = null): void
    {
        $status = $this->channelStateService->resolveSmsStatus(
            tenantId: (int) $conversation->tenant_id,
            profile: $conversation->profile,
            phone: $conversation->phone
        );

        if (in_array($status, ['unsubscribed', 'suppressed'], true) || $conversation->status === 'opted_out') {
            throw ValidationException::withMessages([
                'body' => ['SMS reply is blocked because this contact is opted out or suppressed.'],
            ]);
        }

        $delivery = MarketingMessageDelivery::query()->create([
            'campaign_id' => null,
            'campaign_recipient_id' => null,
            'marketing_profile_id' => $conversation->marketing_profile_id,
            'tenant_id' => (int) $conversation->tenant_id,
            'store_key' => $conversation->store_key,
            'batch_id' => (string) Str::uuid(),
            'source_label' => 'responses_inbox_reply',
            'message_subject' => Str::limit($body, 160),
            'channel' => 'sms',
            'provider' => 'twilio',
            'to_phone' => $conversation->phone,
            'attempt_number' => 1,
            'rendered_message' => $body,
            'send_status' => 'sending',
            'created_by' => $actor?->id,
            'provider_payload' => [
                'source_label' => 'responses_inbox_reply',
                'conversation_id' => (int) $conversation->id,
                'sender_key' => data_get($conversation->source_context, 'sender_key'),
            ],
        ]);

        $result = $this->twilioSmsService->sendSms((string) $conversation->phone, $body, [
            'sender_key' => $this->nullableString(data_get($conversation->source_context, 'sender_key')),
            'status_callback_url' => (string) config('marketing.twilio.status_callback_url'),
        ]);

        $success = (bool) ($result['success'] ?? false);
        $delivery->forceFill([
            'provider_message_id' => $result['provider_message_id'] ?? null,
            'from_identifier' => $result['from_identifier'] ?? null,
            'send_status' => $success ? (string) ($result['status'] ?? 'sent') : 'failed',
            'error_code' => $result['error_code'] ?? null,
            'error_message' => $result['error_message'] ?? null,
            'provider_payload' => [
                ...((array) $delivery->provider_payload),
                'twilio_response' => is_array($result['payload'] ?? null) ? $result['payload'] : [],
            ],
            'sent_at' => $success ? now() : null,
            'failed_at' => $success ? null : now(),
        ])->save();

        $this->conversationService->appendMessage($conversation, [
            'marketing_profile_id' => $conversation->marketing_profile_id,
            'marketing_message_delivery_id' => (int) $delivery->id,
            'channel' => 'sms',
            'direction' => 'outbound',
            'provider' => 'twilio',
            'provider_message_id' => $delivery->provider_message_id,
            'body' => $body,
            'normalized_body' => $body,
            'from_identity' => $delivery->from_identifier,
            'to_identity' => $conversation->phone,
            'sent_at' => $delivery->sent_at ?? now(),
            'delivery_status' => (string) $delivery->send_status,
            'message_type' => 'normal',
            'created_by' => $actor?->id,
            'raw_payload' => is_array($delivery->provider_payload) ? $delivery->provider_payload : [],
            'metadata' => [
                'source_label' => 'responses_inbox_reply',
                'error_code' => $delivery->error_code,
            ],
        ]);
    }

    protected function sendEmailReply(MessagingConversation $conversation, string $body, ?string $subject, ?User $actor = null): void
    {
        $status = $this->channelStateService->resolveEmailStatus(
            tenantId: (int) $conversation->tenant_id,
            profile: $conversation->profile,
            email: $conversation->email
        );

        if (in_array($status, ['unsubscribed', 'bounced', 'suppressed'], true)) {
            throw ValidationException::withMessages([
                'body' => ['Email reply is blocked because this contact is unsubscribed, bounced, or suppressed.'],
            ]);
        }

        $resolvedSubject = $this->replySubject($subject ?? $conversation->subject ?? 'Backstage reply');
        $delivery = MarketingEmailDelivery::query()->create([
            'marketing_campaign_recipient_id' => null,
            'marketing_profile_id' => $conversation->marketing_profile_id,
            'tenant_id' => (int) $conversation->tenant_id,
            'store_key' => $conversation->store_key,
            'batch_id' => (string) Str::uuid(),
            'source_label' => 'responses_inbox_reply',
            'message_subject' => $resolvedSubject,
            'provider' => 'sendgrid',
            'campaign_type' => 'responses_inbox_reply',
            'template_key' => 'responses_inbox_reply',
            'email' => $conversation->email,
            'status' => 'sending',
            'raw_payload' => [
                'body_text' => $body,
                'conversation_id' => (int) $conversation->id,
            ],
            'metadata' => [
                'conversation_id' => (int) $conversation->id,
                'source_label' => 'responses_inbox_reply',
            ],
        ]);

        $replyToEmail = $this->replyAddressService->replyAddressForDelivery((int) $conversation->tenant_id, (int) $delivery->id);
        $messageIds = $conversation->messages()
            ->whereNotNull('provider_message_id')
            ->where('channel', 'email')
            ->orderByDesc('id')
            ->limit(8)
            ->pluck('provider_message_id')
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->values()
            ->all();
        $headers = [];
        if ($messageIds !== []) {
            $headers['In-Reply-To'] = $messageIds[0];
            $headers['References'] = implode(' ', array_reverse($messageIds));
        }

        $result = $this->sendGridEmailService->sendEmail((string) $conversation->email, $resolvedSubject, $body, [
            'tenant_id' => (int) $conversation->tenant_id,
            'campaign_type' => 'responses_inbox_reply',
            'template_key' => 'responses_inbox_reply',
            'customer_id' => (int) ($conversation->marketing_profile_id ?? 0),
            'reply_to_email' => $replyToEmail,
            'headers' => $headers,
            'metadata' => [
                'conversation_id' => (int) $conversation->id,
                'source_label' => 'responses_inbox_reply',
            ],
            'custom_args' => [
                'marketing_email_delivery_id' => (string) $delivery->id,
                'messaging_conversation_id' => (string) $conversation->id,
            ],
            'categories' => ['responses-inbox', 'shopify-embedded'],
        ]);

        $success = (bool) ($result['success'] ?? false);
        $delivery->forceFill([
            'provider' => (string) ($result['provider'] ?? 'sendgrid'),
            'provider_message_id' => $result['message_id'] ?? null,
            'sendgrid_message_id' => $result['message_id'] ?? null,
            'status' => $success ? (string) ($result['status'] ?? 'sent') : 'failed',
            'sent_at' => $success ? now() : null,
            'failed_at' => $success ? null : now(),
            'raw_payload' => [
                ...((array) $delivery->raw_payload),
                'provider_payload' => is_array($result['payload'] ?? null) ? $result['payload'] : [],
                'headers' => $headers,
                'reply_to_email' => $replyToEmail,
            ],
        ])->save();

        $this->conversationService->appendMessage($conversation, [
            'marketing_profile_id' => $conversation->marketing_profile_id,
            'marketing_email_delivery_id' => (int) $delivery->id,
            'channel' => 'email',
            'direction' => 'outbound',
            'provider' => (string) $delivery->provider,
            'provider_message_id' => $delivery->provider_message_id ?: $delivery->sendgrid_message_id,
            'body' => $body,
            'normalized_body' => $body,
            'subject' => $resolvedSubject,
            'from_identity' => $replyToEmail,
            'to_identity' => $conversation->email,
            'sent_at' => $delivery->sent_at ?? now(),
            'delivery_status' => (string) $delivery->status,
            'message_type' => 'normal',
            'created_by' => $actor?->id,
            'raw_payload' => is_array($delivery->raw_payload) ? $delivery->raw_payload : [],
            'metadata' => [
                'source_label' => 'responses_inbox_reply',
            ],
        ]);
    }

    protected function conversationQuery(int $tenantId, ?string $storeKey): Builder
    {
        return MessagingConversation::query()
            ->forTenantId($tenantId)
            ->when(
                $storeKey !== null,
                fn (Builder $builder) => $builder->where('store_key', $storeKey),
                fn (Builder $builder) => $builder->whereNull('store_key')
            );
    }

    protected function applyConversationFilter(Builder $query, string $filter, ?User $actor = null): Builder
    {
        return match ($filter) {
            'all' => $query,
            'unread' => $query->where('unread_count', '>', 0),
            'opted_out' => $query->where('status', 'opted_out'),
            'assigned_to_me' => $actor instanceof User
                ? $query->where('assigned_to', (int) $actor->id)
                : $query->whereRaw('1 = 0'),
            default => $query->whereIn('status', ['open', 'needs_follow_up']),
        };
    }

    /**
     * @return array<string,mixed>
     */
    protected function serializeConversation(MessagingConversation $conversation, bool $includeProfile = false): array
    {
        $profile = $conversation->profile;
        $identity = $conversation->channel === 'sms'
            ? ($conversation->phone ?: 'Unknown phone')
            : ($conversation->email ?: 'Unknown email');
        $displayName = trim(implode(' ', array_filter([
            $profile?->first_name,
            $profile?->last_name,
        ])));
        $smsStatus = $conversation->channel === 'sms'
            ? $this->channelStateService->resolveSmsStatus((int) $conversation->tenant_id, $profile, $conversation->phone)
            : null;
        $emailStatus = $conversation->channel === 'email'
            ? $this->channelStateService->resolveEmailStatus((int) $conversation->tenant_id, $profile, $conversation->email)
            : null;

        return [
            'id' => (int) $conversation->id,
            'channel' => (string) $conversation->channel,
            'status' => (string) $conversation->status,
            'identity' => $identity,
            'display_name' => $displayName !== '' ? $displayName : null,
            'subject' => $this->nullableString($conversation->subject),
            'preview' => $this->nullableString($conversation->last_message_preview),
            'unread_count' => (int) $conversation->unread_count,
            'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
            'last_inbound_at' => optional($conversation->last_inbound_at)->toIso8601String(),
            'last_outbound_at' => optional($conversation->last_outbound_at)->toIso8601String(),
            'assigned_to' => $conversation->assignee
                ? [
                    'id' => (int) $conversation->assignee->id,
                    'name' => (string) $conversation->assignee->name,
                ]
                : null,
            'subscription_state' => $conversation->channel === 'sms' ? $smsStatus : $emailStatus,
            'opted_out' => $conversation->status === 'opted_out'
                || in_array((string) ($smsStatus ?? $emailStatus), ['unsubscribed', 'suppressed', 'bounced'], true),
            'source_context' => is_array($conversation->source_context) ? $conversation->source_context : [],
            'profile' => $includeProfile && $profile
                ? [
                    'id' => (int) $profile->id,
                    'name' => $displayName !== '' ? $displayName : null,
                    'email' => $this->nullableString($profile->email),
                    'phone' => $this->nullableString($profile->phone),
                ]
                : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function serializeMessage(MessagingConversationMessage $message): array
    {
        return [
            'id' => (int) $message->id,
            'channel' => (string) $message->channel,
            'direction' => (string) $message->direction,
            'provider' => (string) $message->provider,
            'provider_message_id' => $this->nullableString($message->provider_message_id),
            'body' => (string) $message->body,
            'subject' => $this->nullableString($message->subject),
            'from_identity' => $this->nullableString($message->from_identity),
            'to_identity' => $this->nullableString($message->to_identity),
            'delivery_status' => $this->nullableString($message->delivery_status),
            'message_type' => (string) $message->message_type,
            'received_at' => optional($message->received_at)->toIso8601String(),
            'sent_at' => optional($message->sent_at)->toIso8601String(),
            'created_at' => optional($message->created_at)->toIso8601String(),
            'operator_read_at' => optional($message->operator_read_at)->toIso8601String(),
            'metadata' => is_array($message->metadata) ? $message->metadata : [],
            'creator' => $message->creator
                ? [
                    'id' => (int) $message->creator->id,
                    'name' => (string) $message->creator->name,
                ]
                : null,
        ];
    }

    protected function replySubject(string $value): string
    {
        $subject = trim($value);
        if ($subject === '') {
            return 'Re: Backstage conversation';
        }

        if (Str::startsWith(strtolower($subject), 're:')) {
            return $subject;
        }

        return 'Re: ' . $subject;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
