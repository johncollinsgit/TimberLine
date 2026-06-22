<?php

namespace App\Services\Mobile;

use App\Models\CandleCashRedemption;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\OrderLine;
use App\Services\Marketing\CandleCashService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ModernForestryMobileAccountService
{
    public function __construct(
        protected CandleCashService $candleCashService
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

        return [
            'ok' => $body !== '',
            'state' => $body !== '' ? 'received' : 'empty_message',
            'support' => $this->supportPayload($session->profile),
            'message' => $body !== ''
                ? 'Your message is ready for the Modern Forestry support queue.'
                : 'Add a short message before sending.',
        ];
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
            ->map(fn (Order $order): array => $this->orderPayload($order))
            ->values();
    }

    /**
     * @return array<string,mixed>
     */
    protected function orderPayload(Order $order): array
    {
        $lines = $order->lines->map(fn (OrderLine $line): array => [
            'id' => (int) $line->id,
            'title' => trim((string) ($line->raw_title ?? '')) ?: trim((string) ($line->raw_variant ?? '')) ?: 'Item',
            'quantity' => max(1, (int) ($line->quantity ?: $line->ordered_qty ?: 1)),
        ])->values();

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
        return [
            'canMessage' => $this->nullableString($profile->email) !== null || $this->nullableString($profile->phone) !== null,
            'preferredChannel' => $this->nullableString($profile->phone) !== null ? 'sms' : 'email',
            'prompt' => 'Send a note and Modern Forestry support can follow up from your account details.',
        ];
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
