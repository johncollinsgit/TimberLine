<?php

namespace App\Services\Mobile;

use App\Models\CandleCashRedemption;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\MessagingConversation;
use App\Models\MessagingConversationMessage;
use App\Models\MobilePushDevice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Services\Marketing\MessagingConversationService;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\MarketingWishlistService;
use App\Services\Mobile\ModernForestryMobileProductCatalogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ModernForestryMobileAccountService
{
    protected const MOBILE_SUPPORT_SOURCE_TYPE = 'modern_forestry_app';
    protected const MOBILE_SUPPORT_STORE_KEY = 'retail';
    protected const MOBILE_SUPPORT_SUBJECT = 'Modern Forestry app support';

    /**
     * @var array<int,array<string,mixed>>|null
     */
    protected ?array $catalogProducts = null;

    public function __construct(
        protected CandleCashService $candleCashService,
        protected MarketingWishlistService $wishlistService,
        protected ModernForestryMobileProductCatalogService $catalog,
        protected MessagingConversationService $conversationService
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function account(ModernForestryMobileCustomerSession $session): array
    {
        $profile = $session->profile;
        $tenantId = $this->tenantId($profile);

        return [
            'customer' => $this->customerPayload($profile),
            'orders' => $this->orders($profile, $tenantId)->take(10)->values()->all(),
            'support' => $this->supportPayload($profile),
            'rewards' => $this->rewardsSummary($profile, $tenantId),
            'wishlist' => $this->wishlistPayload($profile, $tenantId),
            'notifications' => $this->notificationsPayload($profile),
            'insights' => $this->insightsPayload($profile, $tenantId),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function wishlistPayload(MarketingProfile $profile, int $tenantId): array
    {
        return $this->wishlistService->storefrontPayload($profile, [
            'store_key' => 'retail',
            'tenant_id' => $tenantId,
            'limit' => 12,
            'identity_status' => 'authenticated',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function wishlistStatus(MarketingProfile $profile, array $context = []): array
    {
        return $this->wishlistService->storefrontPayload($profile, [
            'store_key' => 'retail',
            ...$context,
        ]);
    }

    /**
     * @param array<string,mixed> $product
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function addWishlistItem(MarketingProfile $profile, array $product, array $options = []): array
    {
        $result = $this->wishlistService->addItem($profile, $product, $options);

        return [
            ...$result,
            'payload' => $this->wishlistPayload($profile, $this->tenantId($profile)),
        ];
    }

    /**
     * @param array<string,mixed> $product
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function removeWishlistItem(MarketingProfile $profile, array $product, array $options = []): array
    {
        $result = $this->wishlistService->removeItem($profile, $product, $options);

        return [
            ...$result,
            'payload' => $this->wishlistPayload($profile, $this->tenantId($profile)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function rewards(ModernForestryMobileCustomerSession $session): array
    {
        $profile = $session->profile;
        $tenantId = $this->tenantId($profile);
        $balancePoints = $this->candleCashService->currentBalance($profile);
        $reward = $this->candleCashService->storefrontReward($tenantId);
        $rewardPayload = $this->candleCashService->storefrontRewardPayload($reward, $balancePoints, $tenantId);

        return [
            'customer' => $this->customerPayload($profile),
            'balance' => $this->candleCashService->balancePayloadFromPoints($balancePoints),
            'earn' => [
                [
                    'id' => 'purchase',
                    'title' => 'Earn on purchases',
                    'body' => 'Candle Cash is added from eligible Modern Forestry orders.',
                    'icon' => 'bag',
                ],
                [
                    'id' => 'reviews',
                    'title' => 'Review your favorites',
                    'body' => 'Verified reviews can unlock bonus Candle Cash when campaigns are active.',
                    'icon' => 'star',
                ],
                [
                    'id' => 'referrals',
                    'title' => 'Share Modern Forestry',
                    'body' => 'Referral and seasonal earning paths appear here as they become available.',
                    'icon' => 'person.2',
                ],
            ],
            'rewards' => array_values(array_filter([$rewardPayload])),
            'history' => $this->rewardHistory($profile, $tenantId),
            'redemptions' => $this->redemptions($profile, $tenantId),
            'rules' => $this->candleCashService->redemptionRulesPayload($tenantId),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function redeem(ModernForestryMobileCustomerSession $session, ?int $rewardId = null): array
    {
        $profile = $session->profile;
        $tenantId = $this->tenantId($profile);
        $reward = $rewardId !== null && $rewardId > 0
            ? $this->candleCashService->storefrontReward($tenantId)
            : $this->candleCashService->storefrontReward($tenantId);

        if (! $reward) {
            return [
                'ok' => false,
                'state' => 'reward_unavailable',
                'message' => 'This reward is not available right now.',
                'redemption' => null,
                'balance' => $this->candleCashService->balancePayloadFromPoints($this->candleCashService->currentBalance($profile)),
            ];
        }

        if ($rewardId !== null && $rewardId > 0 && (int) $reward->id !== $rewardId) {
            return [
                'ok' => false,
                'state' => 'reward_unavailable',
                'message' => 'This reward is not available right now.',
                'redemption' => null,
                'balance' => $this->candleCashService->balancePayloadFromPoints($this->candleCashService->currentBalance($profile)),
            ];
        }

        $result = $this->candleCashService->requestStorefrontRedemption(
            profile: $profile,
            reward: $reward,
            platform: 'modern_forestry_ios',
            reuseActiveCode: true,
            tenantId: $tenantId
        );

        $redemption = isset($result['redemption_id']) && (int) $result['redemption_id'] > 0
            ? CandleCashRedemption::query()->with('reward')->find((int) $result['redemption_id'])
            : null;

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'state' => (string) ($result['state'] ?? 'try_again_later'),
            'message' => $this->redemptionMessage((string) ($result['state'] ?? 'try_again_later')),
            'redemption' => $redemption instanceof CandleCashRedemption ? $this->redemptionPayload($redemption, $tenantId, true) : null,
            'balance' => $this->candleCashService->balancePayloadFromPoints($result['balance'] ?? $this->candleCashService->currentBalance($profile)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function message(ModernForestryMobileCustomerSession $session, string $body): array
    {
        $body = trim($body);

        if ($body === '') {
            return [
                'ok' => false,
                'state' => 'empty_message',
                'support' => $this->supportPayload($session->profile),
                'message' => 'Add a short message before sending.',
            ];
        }

        $conversation = $this->ensureMobileSupportConversation($session->profile);
        if (! $conversation instanceof MessagingConversation) {
            return [
                'ok' => false,
                'state' => 'support_unavailable',
                'support' => $this->supportPayload($session->profile),
                'message' => 'Support messaging is not available for this account right now.',
            ];
        }

        $identity = $conversation->channel === 'sms'
            ? $this->nullableString($session->profile->normalized_phone ?: $session->profile->phone)
            : $this->nullableString($session->profile->normalized_email ?: $session->profile->email);

        $this->conversationService->appendMessage($conversation, [
            'marketing_profile_id' => $session->profile->id,
            'channel' => (string) $conversation->channel,
            'direction' => 'inbound',
            'provider' => self::MOBILE_SUPPORT_SOURCE_TYPE,
            'body' => $body,
            'normalized_body' => $body,
            'subject' => $conversation->channel === 'email' ? ($conversation->subject ?: self::MOBILE_SUPPORT_SUBJECT) : null,
            'from_identity' => $identity,
            'to_identity' => 'Modern Forestry support',
            'received_at' => now(),
            'delivery_status' => 'received',
            'message_type' => 'app_message',
            'customer_read_at' => now(),
            'raw_payload' => [
                'surface' => 'ios_account',
                'thread_kind' => 'support',
            ],
            'metadata' => [
                'source_label' => 'modern_forestry_mobile_app',
                'thread_kind' => 'support',
            ],
        ]);

        return [
            'ok' => true,
            'state' => 'received',
            'support' => $this->supportPayload($session->profile),
            'message' => 'Your message is in the Modern Forestry support thread.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function markSupportMessagesRead(ModernForestryMobileCustomerSession $session): array
    {
        $conversation = $this->mobileSupportConversation($session->profile);
        if (! $conversation instanceof MessagingConversation) {
            return $this->supportPayload($session->profile);
        }

        $conversation->messages()
            ->where('direction', 'outbound')
            ->whereNull('customer_read_at')
            ->update(['customer_read_at' => now()]);

        return $this->supportPayload($session->profile);
    }

    /**
     * @return array<string,mixed>
     */
    protected function customerPayload(MarketingProfile $profile): array
    {
        return [
            'id' => (int) $profile->id,
            'firstName' => $this->nullableString($profile->first_name),
            'lastName' => $this->nullableString($profile->last_name),
            'displayName' => trim(implode(' ', array_filter([
                $this->nullableString($profile->first_name),
                $this->nullableString($profile->last_name),
            ]))) ?: ($this->nullableString($profile->email) ?? 'Modern Forestry customer'),
            'email' => $this->nullableString($profile->email),
            'phone' => $this->nullableString($profile->phone),
            'acceptsEmailMarketing' => (bool) $profile->accepts_email_marketing,
            'acceptsSmsMarketing' => (bool) $profile->accepts_sms_marketing,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function rewardsSummary(MarketingProfile $profile, int $tenantId): array
    {
        $balancePoints = $this->candleCashService->currentBalance($profile);

        return [
            'balance' => $this->candleCashService->balancePayloadFromPoints($balancePoints),
            'availableCount' => count(array_filter([
                $this->candleCashService->storefrontRewardPayload(
                    $this->candleCashService->storefrontReward($tenantId),
                    $balancePoints,
                    $tenantId
                ),
            ])),
            'recentRedemptions' => $this->redemptions($profile, $tenantId)->take(3)->values()->all(),
        ];
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    protected function orders(MarketingProfile $profile, int $tenantId): Collection
    {
        $linkedOrderIds = $profile->links()
            ->where('source_type', 'order')
            ->pluck('source_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        $shopifyCustomerIds = $profile->links()
            ->where('source_type', 'shopify_customer')
            ->pluck('source_id')
            ->map(fn ($value): ?string => $this->numericTail($value))
            ->filter()
            ->unique()
            ->values();

        if ($linkedOrderIds->isEmpty() && $shopifyCustomerIds->isEmpty()) {
            return collect();
        }

        return Order::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($linkedOrderIds, $shopifyCustomerIds): void {
                if ($linkedOrderIds->isNotEmpty()) {
                    $query->orWhereIn('id', $linkedOrderIds->all());
                }

                if ($shopifyCustomerIds->isNotEmpty()) {
                    $query->orWhereIn('shopify_customer_id', $shopifyCustomerIds->all());
                }
            })
            ->with('lines')
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (Order $order): array => $this->orderPayload($order, $tenantId))
            ->values();
    }

    /**
     * @return array<string,mixed>
     */
    protected function orderPayload(Order $order, int $tenantId): array
    {
        $lines = $order->lines->map(fn (OrderLine $line): array => $this->orderLinePayload($line, $tenantId))->values();

        return [
            'id' => (int) $order->id,
            'orderNumber' => (string) ($order->order_number ?: $order->order_label ?: '#'.$order->id),
            'title' => (string) ($order->display_name ?: $order->order_number ?: 'Order #'.$order->id),
            'orderedAt' => optional($order->ordered_at)->toIso8601String(),
            'status' => (string) ($order->status ?: 'open'),
            'currencyCode' => (string) ($order->currency_code ?: 'USD'),
            'total' => number_format((float) ($order->total_price ?? 0), 2, '.', ''),
            'totalFormatted' => '$'.number_format((float) ($order->total_price ?? 0), 2),
            'linePreview' => $lines->take(3)->pluck('title')->implode(' · '),
            'lineCount' => $lines->count(),
            'lines' => $lines->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function orderLinePayload(OrderLine $line, int $tenantId): array
    {
        $rawTitle = trim((string) ($line->raw_title ?? ''));
        $rawVariant = trim((string) ($line->raw_variant ?? ''));
        $matchedProduct = $this->matchProductForLine($rawTitle);
        $matchedVariant = null;

        if ($matchedProduct !== null) {
            try {
                $detail = $this->catalog->productDetail((string) ($matchedProduct['handle'] ?? ''));
                if (is_array($detail)) {
                    $matchedVariant = $this->matchVariantForLine((array) ($detail['variants'] ?? []), $rawVariant);
                }
            } catch (Throwable $exception) {
                Log::warning('Modern Forestry mobile account variant enrichment failed.', [
                    'order_line_id' => (int) $line->id,
                    'product_handle' => $matchedProduct['handle'] ?? null,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'id' => (int) $line->id,
            'title' => $rawTitle !== '' ? $rawTitle : ($rawVariant !== '' ? $rawVariant : 'Item'),
            'quantity' => max(1, (int) ($line->quantity ?: $line->ordered_qty ?: 1)),
            'productHandle' => $matchedProduct['handle'] ?? null,
            'productTitle' => $matchedProduct['title'] ?? ($rawTitle !== '' ? $rawTitle : null),
            'variantId' => $matchedVariant['id'] ?? ($line->shopify_variant_id ? (string) $line->shopify_variant_id : null),
            'variantTitle' => $matchedVariant['title'] ?? ($rawVariant !== '' ? $rawVariant : null),
            'imageUrl' => $this->nullableString($line->image_url),
            'canReorder' => $matchedProduct !== null,
        ];
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    protected function rewardHistory(MarketingProfile $profile, int $tenantId): Collection
    {
        return $profile->candleCashTransactions()
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'type', 'candle_cash_delta', 'source', 'source_id', 'description', 'created_at'])
            ->map(fn (CandleCashTransaction $transaction): array => [
                'id' => (int) $transaction->id,
                'type' => (string) $transaction->type,
                'amount' => $this->candleCashService->amountFromPoints($transaction->candle_cash_delta),
                'amountFormatted' => $this->candleCashService->candleCashAmountLabelFromPoints($transaction->candle_cash_delta, true),
                'description' => $this->nullableString($transaction->description) ?? Str::title((string) $transaction->type),
                'createdAt' => optional($transaction->created_at)->toIso8601String(),
            ])
            ->values();
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    protected function redemptions(MarketingProfile $profile, int $tenantId): Collection
    {
        return $profile->candleCashRedemptions()
            ->with('reward')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (CandleCashRedemption $redemption): array => $this->redemptionPayload($redemption, $tenantId, true))
            ->values();
    }

    /**
     * @return array<string,mixed>
     */
    protected function notificationsPayload(MarketingProfile $profile): array
    {
        $pushEnabled = MobilePushDevice::query()
            ->where('tenant_id', $this->tenantId($profile))
            ->where('marketing_profile_id', $profile->id)
            ->where('push_enabled', true)
            ->exists();

        return [
            'channels' => [
                [
                    'id' => 'email',
                    'title' => 'Email updates',
                    'enabled' => (bool) $profile->accepts_email_marketing,
                ],
                [
                    'id' => 'sms',
                    'title' => 'Text alerts',
                    'enabled' => (bool) $profile->accepts_sms_marketing,
                ],
                [
                    'id' => 'push',
                    'title' => 'App-only alerts',
                    'enabled' => $pushEnabled,
                    'state' => $pushEnabled ? 'enabled' : 'available_in_app',
                ],
            ],
            'summary' => [
                'email' => (bool) $profile->accepts_email_marketing,
                'sms' => (bool) $profile->accepts_sms_marketing,
                'push' => $pushEnabled,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function insightsPayload(MarketingProfile $profile, int $tenantId): array
    {
        $orders = $this->orders($profile, $tenantId);
        $wishlist = $this->wishlistPayload($profile, $tenantId);

        return [
            'orderCount' => $orders->count(),
            'wishlistCount' => (int) data_get($wishlist, 'summary.active_count', 0),
            'wishlistListCount' => count((array) data_get($wishlist, 'lists', [])),
            'rewardBalance' => data_get($this->rewardsSummary($profile, $tenantId), 'balance'),
            'topOrderTitles' => $orders->pluck('linePreview')->filter()->take(3)->values()->all(),
            'topWishlistProducts' => collect(data_get($wishlist, 'items', []))
                ->pluck('product_title')
                ->filter()
                ->unique()
                ->take(3)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function redemptionPayload(CandleCashRedemption $redemption, int $tenantId, bool $revealCode): array
    {
        return [
            'id' => (int) $redemption->id,
            'name' => $redemption->reward && $this->candleCashService->isStorefrontReward($redemption->reward, $tenantId)
                ? 'Redeem '.$this->candleCashService->fixedRedemptionFormatted($tenantId).' Reward Credit'
                : (string) ($redemption->reward?->name ?: 'Reward'),
            'status' => (string) ($redemption->status ?: 'issued'),
            'code' => $revealCode ? $this->nullableString($redemption->redemption_code) : null,
            'amount' => $this->candleCashService->amountFromPoints($redemption->candle_cash_spent),
            'amountFormatted' => $this->candleCashService->formatCurrency(
                $this->candleCashService->amountFromPoints($redemption->candle_cash_spent)
            ),
            'issuedAt' => optional($redemption->issued_at)->toIso8601String(),
            'expiresAt' => optional($redemption->expires_at)->toIso8601String(),
            'redeemedAt' => optional($redemption->redeemed_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function supportPayload(MarketingProfile $profile): array
    {
        $conversation = $this->mobileSupportConversation($profile);
        $messages = [];
        $unreadCount = 0;

        if ($conversation instanceof MessagingConversation) {
            $messages = $conversation->messages()
                ->orderByRaw('COALESCE(received_at, sent_at, created_at) asc')
                ->get()
                ->map(fn (MessagingConversationMessage $message): array => [
                    'id' => (int) $message->id,
                    'direction' => (string) $message->direction,
                    'body' => (string) $message->body,
                    'subject' => $this->nullableString($message->subject),
                    'messageType' => (string) ($message->message_type ?: 'normal'),
                    'fromIdentity' => $this->nullableString($message->from_identity),
                    'toIdentity' => $this->nullableString($message->to_identity),
                    'createdAt' => optional($message->received_at ?? $message->sent_at ?? $message->created_at)->toIso8601String(),
                    'isUnread' => $message->direction === 'outbound' && $message->customer_read_at === null,
                ])
                ->values()
                ->all();

            $unreadCount = $conversation->messages()
                ->where('direction', 'outbound')
                ->whereNull('customer_read_at')
                ->count();
        }

        return [
            'canMessage' => $this->nullableString($profile->email) !== null || $this->nullableString($profile->phone) !== null,
            'preferredChannel' => $this->nullableString($profile->phone) !== null ? 'sms' : 'email',
            'prompt' => 'Send a note here and Modern Forestry can reply inside the app.',
            'conversationId' => $conversation?->id,
            'unreadCount' => $unreadCount,
            'messages' => $messages,
        ];
    }

    protected function mobileSupportConversation(MarketingProfile $profile): ?MessagingConversation
    {
        return MessagingConversation::query()
            ->where('tenant_id', $this->tenantId($profile))
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', self::MOBILE_SUPPORT_SOURCE_TYPE)
            ->where('store_key', self::MOBILE_SUPPORT_STORE_KEY)
            ->with('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();
    }

    protected function ensureMobileSupportConversation(MarketingProfile $profile): ?MessagingConversation
    {
        $tenantId = $this->tenantId($profile);
        $context = [
            'source_type' => self::MOBILE_SUPPORT_SOURCE_TYPE,
            'source_context' => [
                'thread_kind' => 'support',
                'reply_via' => 'app',
                'surface' => 'ios_account',
                'app' => 'modern_forestry',
            ],
        ];

        $phone = $this->nullableString($profile->normalized_phone ?: $profile->phone);
        if ($phone !== null) {
            return $this->conversationService->findOrCreateSmsConversation(
                tenantId: $tenantId,
                storeKey: self::MOBILE_SUPPORT_STORE_KEY,
                profile: $profile,
                phone: $phone,
                context: $context
            );
        }

        $email = $this->nullableString($profile->normalized_email ?: $profile->email);
        if ($email !== null) {
            return $this->conversationService->findOrCreateEmailConversation(
                tenantId: $tenantId,
                storeKey: self::MOBILE_SUPPORT_STORE_KEY,
                profile: $profile,
                email: $email,
                subject: self::MOBILE_SUPPORT_SUBJECT,
                context: $context
            );
        }

        return null;
    }

    protected function tenantId(MarketingProfile $profile): int
    {
        return (int) ($profile->tenant_id ?: ModernForestryMobileCheckoutService::TENANT_ID);
    }

    protected function numericTail(mixed $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        return preg_match('/(\d+)(?!.*\d)/', $normalized, $matches) === 1 ? (string) $matches[1] : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function matchProductForLine(string $rawTitle): ?array
    {
        $candidate = Str::of($rawTitle)->trim()->lower()->toString();
        if ($candidate === '') {
            return null;
        }

        foreach ($this->catalogProducts() as $product) {
            $title = Str::of((string) ($product['title'] ?? ''))->trim()->lower()->toString();
            $handle = Str::of((string) ($product['handle'] ?? ''))->trim()->lower()->toString();

            if ($title === $candidate || $handle === $candidate || str_contains($title, $candidate) || str_contains($candidate, $title)) {
                return $product;
            }
        }

        return null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function catalogProducts(): array
    {
        if (is_array($this->catalogProducts)) {
            return $this->catalogProducts;
        }

        try {
            $this->catalogProducts = $this->catalog->products(40);
        } catch (Throwable $exception) {
            Log::warning('Modern Forestry mobile account catalog enrichment failed.', [
                'message' => $exception->getMessage(),
            ]);

            $this->catalogProducts = [];
        }

        return $this->catalogProducts;
    }

    /**
     * @param array<int,array<string,mixed>> $variants
     * @return array<string,mixed>|null
     */
    protected function matchVariantForLine(array $variants, string $rawVariant): ?array
    {
        $candidate = Str::of($rawVariant)->trim()->lower()->toString();
        if ($candidate === '') {
            return null;
        }

        foreach ($variants as $variant) {
            $title = Str::of((string) ($variant['title'] ?? ''))->trim()->lower()->toString();
            $displayTitle = Str::of((string) ($variant['displayTitle'] ?? ''))->trim()->lower()->toString();

            if ($title === $candidate || $displayTitle === $candidate || str_contains($title, $candidate) || str_contains($candidate, $title)) {
                return $variant;
            }
        }

        return null;
    }

    protected function redemptionMessage(string $state): string
    {
        return match ($state) {
            'code_issued' => 'Your reward code is ready.',
            'already_has_active_code' => 'Your active reward code is ready.',
            'insufficient_candle_cash' => 'You need a little more Candle Cash before this reward is ready.',
            'reward_unavailable' => 'This reward is not available right now.',
            'redemption_blocked' => 'Reward redemption is temporarily blocked.',
            default => 'Reward redemption is temporarily unavailable.',
        };
    }
}
