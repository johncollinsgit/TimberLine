<?php

namespace App\Services\Mobile;

use App\Models\MarketingProfile;
use App\Models\MessagingConversation;
use App\Models\MobilePushDevice;
use App\Models\User;
use App\Services\Marketing\MessagingContactChannelStateService;
use App\Services\Marketing\MessagingConversationService;
use App\Services\Marketing\MessagingResponseInboxService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TenantMobileMessagingService
{
    public function __construct(
        protected MessagingConversationService $conversations,
        protected MessagingResponseInboxService $inbox,
        protected MessagingContactChannelStateService $channelState,
    ) {}

    /** @return array<string,mixed> */
    public function index(int $tenantId, array $filters = []): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $filter = strtolower(trim((string) ($filters['filter'] ?? 'open')));
        $channel = strtolower(trim((string) ($filters['channel'] ?? 'all')));
        $limit = max(10, min(50, (int) ($filters['limit'] ?? 30)));

        $query = MessagingConversation::query()
            ->forTenantId($tenantId)
            ->with(['profile:id,first_name,last_name,email,phone', 'assignee:id,name']);

        if ($filter === 'open') {
            $query->whereIn('status', ['open', 'needs_follow_up']);
        } elseif ($filter === 'unread') {
            $query->where('unread_count', '>', 0);
        } elseif ($filter !== 'all') {
            $query->where('status', $filter);
        }

        if (in_array($channel, ['text', 'email', 'app'], true)) {
            match ($channel) {
                'text' => $query->where('channel', 'sms')->where(function ($builder): void {
                    $builder->whereNull('source_type')->orWhere('source_type', '!=', 'modern_forestry_app');
                }),
                'email' => $query->where('channel', 'email')->where(function ($builder): void {
                    $builder->whereNull('source_type')->orWhere('source_type', '!=', 'modern_forestry_app');
                }),
                'app' => $query->where('source_type', 'modern_forestry_app'),
            };
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $like = '%'.$search.'%';
                $builder->where('phone', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('subject', 'like', $like)
                    ->orWhere('last_message_preview', 'like', $like)
                    ->orWhereHas('profile', function ($profile) use ($like): void {
                        $profile->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like);
                    });
            });
        }

        $rows = $query->orderByDesc('last_message_at')->orderByDesc('id')->limit($limit)->get();

        return [
            'summary' => [
                'unread' => MessagingConversation::query()->forTenantId($tenantId)->sum('unread_count'),
                'open' => MessagingConversation::query()->forTenantId($tenantId)->whereIn('status', ['open', 'needs_follow_up'])->count(),
            ],
            'conversations' => $rows->map(fn (MessagingConversation $conversation): array => $this->conversationPayload($conversation))->values(),
        ];
    }

    /** @return array<string,mixed> */
    public function show(int $tenantId, int $conversationId): array
    {
        $conversation = $this->conversation($tenantId, $conversationId);
        $payload = $this->inbox->show($tenantId, $conversation->store_key, $conversationId);

        return [
            'conversation' => $this->conversationPayload($conversation->fresh(['profile', 'assignee']) ?? $conversation),
            'messages' => collect((array) ($payload['messages'] ?? []))->map(function (array $message): array {
                return [
                    ...$message,
                    'display_channel' => $message['provider'] === 'modern_forestry_app' ? 'app' : ($message['channel'] === 'sms' ? 'text' : 'email'),
                    'timestamp' => $message['received_at'] ?? $message['sent_at'] ?? $message['created_at'] ?? null,
                ];
            })->values(),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function searchCustomers(int $tenantId, string $search, int $limit = 20): array
    {
        $search = trim($search);
        $rows = MarketingProfile::query()->forTenantId($tenantId)
            ->select(['id', 'first_name', 'last_name', 'email', 'phone', 'accepts_sms_marketing', 'accepts_email_marketing'])
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($builder) use ($like): void {
                    $builder->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like);
                });
            })
            ->latest('updated_at')->limit(max(1, min(30, $limit)))->get();

        $appProfiles = MobilePushDevice::query()->forTenantId($tenantId)
            ->whereIn('marketing_profile_id', $rows->pluck('id'))
            ->where('push_enabled', true)->where('authorization_status', 'authorized')
            ->pluck('marketing_profile_id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();

        return $rows->map(function (MarketingProfile $profile) use ($tenantId, $appProfiles): array {
            $name = trim($profile->first_name.' '.$profile->last_name) ?: ($profile->email ?: $profile->phone ?: 'Customer');
            $smsState = $profile->phone ? $this->channelState->resolveSmsStatus($tenantId, $profile, $profile->phone) : 'unavailable';
            $emailState = $profile->email ? $this->channelState->resolveEmailStatus($tenantId, $profile, $profile->email) : 'unavailable';

            return [
                'id' => (int) $profile->id,
                'name' => $name,
                'initials' => Str::of($name)->explode(' ')->filter()->take(2)->map(fn ($part) => Str::upper(Str::substr($part, 0, 1)))->implode(''),
                'email' => $profile->email,
                'phone' => $profile->phone,
                'channels' => [
                    'text' => ['available' => $profile->phone !== null && ! in_array($smsState, ['unsubscribed', 'suppressed', 'unavailable'], true), 'state' => $smsState],
                    'email' => ['available' => $profile->email !== null && ! in_array($emailState, ['unsubscribed', 'bounced', 'suppressed', 'unavailable'], true), 'state' => $emailState],
                    'app' => ['available' => $tenantId === ModernForestryMobileCheckoutService::TENANT_ID && isset($appProfiles[(int) $profile->id]), 'state' => isset($appProfiles[(int) $profile->id]) ? 'available' : 'unavailable'],
                ],
            ];
        })->values()->all();
    }

    /** @return array<string,mixed> */
    public function compose(int $tenantId, User $actor, int $profileId, string $channel, string $body, ?string $subject, string $idempotencyKey): array
    {
        return $this->idempotent($tenantId, $actor, $idempotencyKey, function () use ($tenantId, $actor, $profileId, $channel, $body, $subject): array {
            $profile = MarketingProfile::query()->forTenantId($tenantId)->findOrFail($profileId);
            $eligibility = collect($this->searchCustomers($tenantId, (string) ($profile->email ?: $profile->phone ?: $profile->first_name), 30))->firstWhere('id', $profileId);
            abort_unless((bool) data_get($eligibility, 'channels.'.$channel.'.available', false), 422, 'That customer cannot receive this message channel.');

            $context = $channel === 'app' ? [
                'source_type' => 'modern_forestry_app',
                'source_context' => ['thread_kind' => 'support', 'reply_via' => 'app', 'app' => 'modern_forestry'],
            ] : ['source_context' => ['surface' => 'everbranch_mobile']];
            $storeKey = $channel === 'app' ? 'retail' : null;

            if ($channel === 'email' || ($channel === 'app' && ! $profile->phone)) {
                $conversation = $this->conversations->findOrCreateEmailConversation($tenantId, $storeKey, $profile, (string) $profile->email, $subject, $context);
            } else {
                $conversation = $this->conversations->findOrCreateSmsConversation($tenantId, $storeKey, $profile, (string) $profile->phone, $context);
            }

            $this->inbox->reply($tenantId, $conversation->store_key, (int) $conversation->id, ['body' => $body, 'subject' => $subject], $actor);

            return ['ok' => true, 'conversation_id' => (int) $conversation->id, 'thread' => $this->show($tenantId, (int) $conversation->id)];
        });
    }

    /** @return array<string,mixed> */
    public function reply(int $tenantId, User $actor, int $conversationId, string $body, ?string $subject, string $idempotencyKey): array
    {
        return $this->idempotent($tenantId, $actor, $idempotencyKey, function () use ($tenantId, $actor, $conversationId, $body, $subject): array {
            $conversation = $this->conversation($tenantId, $conversationId);
            $this->inbox->reply($tenantId, $conversation->store_key, $conversationId, ['body' => $body, 'subject' => $subject], $actor);

            return ['ok' => true, 'thread' => $this->show($tenantId, $conversationId)];
        });
    }

    /** @return array<string,mixed> */
    public function action(int $tenantId, User $actor, int $conversationId, string $action): array
    {
        $conversation = $this->conversation($tenantId, $conversationId);
        $this->inbox->updateConversation($tenantId, $conversation->store_key, $conversationId, ['action' => $action], $actor);

        return ['ok' => true, 'thread' => $this->show($tenantId, $conversationId)];
    }

    protected function conversation(int $tenantId, int $conversationId): MessagingConversation
    {
        return MessagingConversation::query()->forTenantId($tenantId)->with(['profile', 'assignee'])->findOrFail($conversationId);
    }

    /** @return array<string,mixed> */
    protected function conversationPayload(MessagingConversation $conversation): array
    {
        $name = trim(($conversation->profile?->first_name ?? '').' '.($conversation->profile?->last_name ?? ''))
            ?: ($conversation->email ?: $conversation->phone ?: 'Conversation');
        $channel = $conversation->source_type === 'modern_forestry_app' ? 'app' : ($conversation->channel === 'sms' ? 'text' : 'email');

        return [
            'id' => (int) $conversation->id,
            'customer_id' => $conversation->marketing_profile_id ? (int) $conversation->marketing_profile_id : null,
            'display_name' => $name,
            'initials' => Str::of($name)->explode(' ')->filter()->take(2)->map(fn ($part) => Str::upper(Str::substr($part, 0, 1)))->implode(''),
            'channel' => $channel,
            'status' => (string) $conversation->status,
            'preview' => $conversation->last_message_preview,
            'subject' => $conversation->subject,
            'identity' => $channel === 'email' ? $conversation->email : $conversation->phone,
            'unread_count' => (int) $conversation->unread_count,
            'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
            'assigned_to' => $conversation->assignee ? ['id' => (int) $conversation->assignee->id, 'name' => (string) $conversation->assignee->name] : null,
        ];
    }

    /** @return array<string,mixed> */
    protected function idempotent(int $tenantId, User $actor, string $key, callable $callback): array
    {
        $cacheKey = 'everbranch:mobile:message:'.hash('sha256', $tenantId.'|'.$actor->id.'|'.$key);
        if (is_array($cached = Cache::get($cacheKey))) {
            return [...$cached, 'idempotent_replay' => true];
        }

        return Cache::lock($cacheKey.':lock', 15)->block(5, function () use ($cacheKey, $callback): array {
            if (is_array($cached = Cache::get($cacheKey))) {
                return [...$cached, 'idempotent_replay' => true];
            }
            $result = $callback();
            Cache::put($cacheKey, $result, now()->addDay());

            return $result;
        });
    }
}
