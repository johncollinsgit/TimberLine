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
use App\Services\Marketing\TwilioSmsService;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\CandleCashShopifyDiscountService;
use App\Services\Marketing\MarketingWishlistService;
use App\Services\Mobile\ModernForestryMobileProductCatalogService;
use App\Services\Shopify\ShopifyEmbeddedRewardsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ModernForestryMobileAccountService
{
    protected const MOBILE_SUPPORT_SOURCE_TYPE = 'modern_forestry_app';
    protected const MOBILE_SUPPORT_STORE_KEY = 'retail';
    protected const MOBILE_STOREFRONT_STORE_KEY = 'retail';
    protected const MOBILE_SUPPORT_SUBJECT = 'Modern Forestry app support';

    /**
     * @var array<int,array<string,mixed>>|null
     */
    protected ?array $catalogProducts = null;

    public function __construct(
        protected CandleCashService $candleCashService,
        protected CandleCashShopifyDiscountService $shopifyDiscounts,
        protected MarketingWishlistService $wishlistService,
        protected ModernForestryMobileProductCatalogService $catalog,
        protected MessagingConversationService $conversationService,
        protected TwilioSmsService $twilioSmsService,
        protected ModernForestryMobileSupportSettingsService $supportSettings,
        protected ModernForestryMobileScentQuizService $scentQuizService,
        protected ShopifyEmbeddedRewardsService $embeddedRewards
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
            'scentQuiz' => $this->scentQuizService->latestResultPayload($profile),
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
        $embeddedRewards = $this->embeddedRewards->payload($tenantId);
        $earnItems = collect((array) data_get($embeddedRewards, 'earn.items', []))
            ->filter(fn (mixed $item): bool => is_array($item)
                && (bool) ($item['enabled'] ?? false)
                && (bool) ($item['customer_visible'] ?? true))
            ->values()
            ->map(fn (array $item): array => [
                'id' => (string) ($item['code'] ?? $item['id'] ?? Str::slug((string) ($item['title'] ?? 'earn'))),
                'title' => (string) ($item['title'] ?? 'Earn Candle Cash'),
                'body' => $this->earnPathBody($item),
                'icon' => $this->earnPathIcon($item),
                'valueLabel' => $this->nullableString($item['reward_amount_formatted'] ?? $item['candle_cash_value_formatted'] ?? null),
            ])
            ->all();

        return [
            'customer' => $this->customerPayload($profile),
            'balance' => $this->candleCashService->balancePayloadFromPoints($balancePoints),
            'earn' => $earnItems,
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

        if (! (bool) ($result['ok'] ?? false) && (string) ($result['state'] ?? '') === 'redemption_blocked') {
            $existingRedemption = $this->latestReusableIssuedRedemption($profile);

            if ($existingRedemption instanceof CandleCashRedemption) {
                $syncFailure = $this->rewardSyncFailurePayload(
                    $profile,
                    $existingRedemption,
                    'already_has_active_code'
                );

                if ($syncFailure !== null) {
                    return $syncFailure;
                }

                return [
                    'ok' => true,
                    'state' => 'already_has_active_code',
                    'message' => $this->redemptionMessage('already_has_active_code'),
                    'redemption' => $this->redemptionPayload($existingRedemption, $tenantId, true),
                    'balance' => $this->candleCashService->balancePayloadFromPoints($this->candleCashService->currentBalance($profile)),
                ];
            }
        }

        $redemption = isset($result['redemption_id']) && (int) $result['redemption_id'] > 0
            ? CandleCashRedemption::query()->with('reward')->find((int) $result['redemption_id'])
            : null;

        if ((bool) ($result['ok'] ?? false) && $redemption instanceof CandleCashRedemption) {
            $syncFailure = $this->rewardSyncFailurePayload(
                $profile,
                $redemption,
                (string) ($result['state'] ?? 'try_again_later')
            );

            if ($syncFailure !== null) {
                return $syncFailure;
            }
        }

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'state' => (string) ($result['state'] ?? 'try_again_later'),
            'message' => $this->redemptionMessage((string) ($result['state'] ?? 'try_again_later')),
            'redemption' => $redemption instanceof CandleCashRedemption ? $this->redemptionPayload($redemption, $tenantId, true) : null,
            'balance' => $this->candleCashService->balancePayloadFromPoints($result['balance'] ?? $this->candleCashService->currentBalance($profile)),
        ];
    }

    protected function latestReusableIssuedRedemption(MarketingProfile $profile): ?CandleCashRedemption
    {
        return CandleCashRedemption::query()
            ->with('reward')
            ->where('marketing_profile_id', $profile->id)
            ->where('status', 'issued')
            ->whereNotNull('redemption_code')
            ->where('redemption_code', '!=', '')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    public function releaseRedemption(ModernForestryMobileCustomerSession $session, int $redemptionId): array
    {
        $profile = $session->profile;
        $tenantId = $this->tenantId($profile);

        /** @var CandleCashRedemption|null $redemption */
        $redemption = CandleCashRedemption::query()
            ->with('reward')
            ->where('marketing_profile_id', $profile->id)
            ->find($redemptionId);

        if (! $redemption) {
            return [
                'ok' => false,
                'state' => 'redemption_not_found',
                'message' => 'That Candle Cash code could not be found on this account anymore.',
                'redemption' => null,
                'balance' => $this->candleCashService->balancePayloadFromPoints($this->candleCashService->currentBalance($profile)),
            ];
        }

        $status = strtolower((string) ($redemption->status ?? ''));

        if ($status === 'redeemed') {
            return [
                'ok' => true,
                'state' => 'already_redeemed',
                'message' => 'That Candle Cash was already used in checkout.',
                'redemption' => $this->redemptionPayload($redemption, $tenantId, true),
                'balance' => $this->candleCashService->balancePayloadFromPoints($this->candleCashService->currentBalance($profile)),
            ];
        }

        if (in_array($status, ['canceled', 'cancelled', 'expired'], true)) {
            return [
                'ok' => true,
                'state' => 'already_released',
                'message' => 'That Candle Cash is already back off the cart.',
                'redemption' => $this->redemptionPayload($redemption, $tenantId, true),
                'balance' => $this->candleCashService->balancePayloadFromPoints($this->candleCashService->currentBalance($profile)),
            ];
        }

        if ($status !== 'issued') {
            return [
                'ok' => false,
                'state' => 'release_unavailable',
                'message' => 'That Candle Cash could not be cleared from checkout right now.',
                'redemption' => $this->redemptionPayload($redemption, $tenantId, true),
                'balance' => $this->candleCashService->balancePayloadFromPoints($this->candleCashService->currentBalance($profile)),
            ];
        }

        $restore = $this->candleCashService->cancelIssuedRedemptionAndRestoreBalance(
            $redemption,
            'Canceled automatically because the mobile checkout was dismissed before the Candle Cash code was used.'
        );

        $released = (bool) ($restore['restored'] ?? false);
        $freshRedemption = $redemption->fresh('reward') ?? $redemption;

        return [
            'ok' => $released,
            'state' => $released ? 'released' : 'release_unavailable',
            'message' => $released
                ? 'Candle Cash was removed from the pending checkout and is available again.'
                : 'That Candle Cash could not be cleared from checkout right now.',
            'redemption' => $this->redemptionPayload($freshRedemption, $tenantId, true),
            'balance' => $this->candleCashService->balancePayloadFromPoints($restore['balance'] ?? $this->candleCashService->currentBalance($profile)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function message(ModernForestryMobileCustomerSession $session, string $body, ?string $subject = null): array
    {
        $body = trim($body);
        $subject = $this->nullableString($subject);

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
        $resolvedSubject = $subject
            ?? ($conversation->channel === 'email' ? ($conversation->subject ?: self::MOBILE_SUPPORT_SUBJECT) : null);

        $this->conversationService->appendMessage($conversation, [
            'marketing_profile_id' => $session->profile->id,
            'channel' => (string) $conversation->channel,
            'direction' => 'inbound',
            'provider' => self::MOBILE_SUPPORT_SOURCE_TYPE,
            'body' => $body,
            'normalized_body' => $body,
            'subject' => $resolvedSubject,
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

        if ($subject !== null && $this->nullableString($conversation->subject) === null) {
            $conversation->forceFill([
                'subject' => $subject,
            ])->save();
        }

        $this->notifySupportSms($session->profile, $body, $subject);

        return [
            'ok' => true,
            'state' => 'received',
            'support' => $this->supportPayload($session->profile),
            'message' => 'We will get back to you as soon as we can.',
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
            'addressLine1' => $this->nullableString($profile->address_line_1),
            'addressLine2' => $this->nullableString($profile->address_line_2),
            'city' => $this->nullableString($profile->city),
            'state' => $this->nullableString($profile->state),
            'postalCode' => $this->nullableString($profile->postal_code),
            'country' => $this->nullableString($profile->country),
            'hasSavedAddress' => $this->hasSavedAddress($profile),
            'avatarUrl' => $this->profileAvatarUrl($profile),
            'acceptsEmailMarketing' => (bool) $profile->accepts_email_marketing,
            'acceptsSmsMarketing' => (bool) $profile->accepts_sms_marketing,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function rewardSyncFailurePayload(
        MarketingProfile $profile,
        CandleCashRedemption $redemption,
        string $state
    ): ?array {
        try {
            $this->shopifyDiscounts->ensureDiscountForRedemption($redemption, self::MOBILE_STOREFRONT_STORE_KEY);

            return null;
        } catch (Throwable $exception) {
            Log::warning('Modern Forestry mobile reward discount sync failed.', [
                'marketing_profile_id' => (int) $profile->id,
                'redemption_id' => (int) $redemption->id,
                'redemption_code' => (string) ($redemption->redemption_code ?? ''),
                'state' => $state,
                'message' => $exception->getMessage(),
            ]);

            $balance = $this->candleCashService->currentBalance($profile);
            $message = 'We could not prepare your reward code for checkout. Please try again in a moment.';

            if ($state === 'code_issued') {
                $restore = $this->candleCashService->cancelIssuedRedemptionAndRestoreBalance($redemption);
                $balance = $restore['balance'] ?? $balance;
                if ((bool) ($restore['restored'] ?? false)) {
                    $message = 'We could not prepare your reward code for checkout. Your Candle Cash is available again, so please try once more in a moment.';
                }
            }

            return [
                'ok' => false,
                'state' => 'discount_sync_failed',
                'message' => $message,
                'redemption' => null,
                'balance' => $this->candleCashService->balancePayloadFromPoints($balance),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function updateProfilePhoto(
        ModernForestryMobileCustomerSession $session,
        ?string $photoData,
        bool $clear = false
    ): array {
        $profile = $session->profile;

        if ($clear) {
            $this->deleteProfileAvatar($profile);
            $profile->forceFill([
                'mobile_avatar_path' => null,
                'mobile_avatar_uploaded_at' => null,
            ])->save();

            return $this->customerPayload($profile->fresh() ?? $profile);
        }

        $decoded = $this->decodeProfilePhoto($photoData);
        if ($decoded === null) {
            return $this->customerPayload($profile);
        }

        $this->deleteProfileAvatar($profile);

        $path = sprintf(
            'modern-forestry/customers/%d/profile-%d-%s.jpg',
            $this->tenantId($profile),
            (int) $profile->id,
            Str::lower(Str::random(20))
        );

        Storage::disk('public')->put($path, $decoded, [
            'visibility' => 'public',
            'ContentType' => 'image/jpeg',
            'CacheControl' => 'public, max-age=31536000',
        ]);

        $profile->forceFill([
            'mobile_avatar_path' => $path,
            'mobile_avatar_uploaded_at' => now(),
        ])->save();

        return $this->customerPayload($profile->fresh() ?? $profile);
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
     * @param  array<string,mixed>  $item
     */
    protected function earnPathBody(array $item): string
    {
        $description = $this->nullableString($item['description'] ?? null);
        if ($description !== null) {
            return $description;
        }

        $valueLabel = $this->nullableString($item['reward_amount_formatted'] ?? $item['candle_cash_value_formatted'] ?? null);
        $actionType = $this->nullableString($item['action_type_label'] ?? null);

        return collect([$valueLabel, $actionType])
            ->filter()
            ->implode(' · ') ?: 'Earn more Candle Cash through the live rewards tasks configured in Everbranch.';
    }

    /**
     * @param  array<string,mixed>  $item
     */
    protected function earnPathIcon(array $item): string
    {
        $code = strtolower(trim((string) ($item['code'] ?? '')));
        $title = strtolower(trim((string) ($item['title'] ?? '')));
        $descriptor = $code.' '.$title;

        return match (true) {
            str_contains($descriptor, 'birthday') => 'birthday.cake.fill',
            str_contains($descriptor, 'google') || str_contains($descriptor, 'review') => 'star.bubble.fill',
            str_contains($descriptor, 'instagram') => 'camera.circle.fill',
            str_contains($descriptor, 'refer') || str_contains($descriptor, 'friend') => 'person.2.fill',
            str_contains($descriptor, 'club') || str_contains($descriptor, 'member') => 'crown.fill',
            str_contains($descriptor, 'second order') || str_contains($descriptor, 'purchase') || str_contains($descriptor, 'order') => 'bag.fill',
            default => 'sparkles',
        };
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
            'prompt' => 'We will get back to you as soon as we can.',
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

    protected function hasSavedAddress(MarketingProfile $profile): bool
    {
        return array_filter([
            $this->nullableString($profile->address_line_1),
            $this->nullableString($profile->address_line_2),
            $this->nullableString($profile->city),
            $this->nullableString($profile->state),
            $this->nullableString($profile->postal_code),
            $this->nullableString($profile->country),
        ]) !== [];
    }

    protected function profileAvatarUrl(MarketingProfile $profile): ?string
    {
        $path = $this->nullableString($profile->mobile_avatar_path);
        if ($path === null) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    protected function deleteProfileAvatar(MarketingProfile $profile): void
    {
        $existingPath = $this->nullableString($profile->mobile_avatar_path);
        if ($existingPath === null) {
            return;
        }

        try {
            Storage::disk('public')->delete($existingPath);
        } catch (Throwable $exception) {
            Log::warning('Modern Forestry mobile avatar delete failed.', [
                'marketing_profile_id' => (int) $profile->id,
                'path' => $existingPath,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    protected function decodeProfilePhoto(?string $photoData): ?string
    {
        $photoData = trim((string) $photoData);
        if ($photoData === '') {
            return null;
        }

        if (preg_match('/^data:image\/[a-zA-Z0-9.+-]+;base64,/', $photoData) === 1) {
            $photoData = preg_replace('/^data:image\/[a-zA-Z0-9.+-]+;base64,/', '', $photoData) ?? '';
        }

        $decoded = base64_decode($photoData, true);
        if ($decoded === false || $decoded === '') {
            return null;
        }

        if (strlen($decoded) > 512000) {
            return null;
        }

        return $decoded;
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

    protected function notifySupportSms(MarketingProfile $profile, string $body, ?string $subject = null): void
    {
        $destination = $this->supportSettings->supportAlertPhone($this->tenantId($profile));
        if ($destination === null) {
            return;
        }

        $name = trim(implode(' ', array_filter([
            $this->nullableString($profile->first_name),
            $this->nullableString($profile->last_name),
        ])));

        $identity = $this->nullableString($profile->normalized_phone ?: $profile->phone)
            ?? $this->nullableString($profile->normalized_email ?: $profile->email)
            ?? 'unknown customer';

        $message = implode("\n", [
            'Modern Forestry app support message',
            'From: '.($name !== '' ? $name : 'Unknown customer'),
            'Reply contact: '.$identity,
            ...($subject !== null ? ['Subject: '.$subject] : []),
            'Message: '.Str::limit($body, 1200, '...'),
        ]);

        try {
            $this->twilioSmsService->sendSms($destination, $message, [
                'status_callback_url' => null,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Modern Forestry support SMS alert failed.', [
                'marketing_profile_id' => (int) $profile->id,
                'destination' => $destination,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    protected function redemptionMessage(string $state): string
    {
        return match ($state) {
            'code_issued' => 'Your reward code is ready.',
            'already_has_active_code' => 'Your active reward code is ready.',
            'insufficient_candle_cash' => 'You need a little more Candle Cash before this reward is ready.',
            'reward_unavailable' => 'This reward is not available right now.',
            'redemption_blocked' => 'You already have an unused Candle Cash code on your account. Use that code first or wait for it to expire before creating another one.',
            default => 'Reward redemption is temporarily unavailable.',
        };
    }
}
