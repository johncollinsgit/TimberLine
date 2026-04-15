<?php

namespace App\Services\Marketing;

use App\Models\CandleCashBalance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Services\Tenancy\TenantMarketingSettingsResolver;
use App\Support\Marketing\CandleCashMeasurement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CandleCashService
{
    public const DEFAULT_LEGACY_POINTS_PER_CANDLE_CASH = 30;
    public const CANONICAL_CANDLE_CASH_UNITS_PER_DOLLAR = 1;

    /**
     * @var array<string,array<string,mixed>>
     */
    protected array $programConfigCache = [];

    public function __construct(
        protected TenantMarketingSettingsResolver $marketingSettingsResolver
    ) {
    }

    public function programConfig(?int $tenantId = null): array
    {
        $cacheKey = $tenantId === null ? 'global' : 'tenant:' . $tenantId;
        if (array_key_exists($cacheKey, $this->programConfigCache)) {
            return $this->programConfigCache[$cacheKey];
        }

        $configured = $this->marketingSettingsResolver->array('candle_cash_program_config', $tenantId);
        $fallback = (array) data_get(config('marketing', []), 'candle_cash', []);
        $usesLegacyStorefrontConfig = ! array_key_exists('redeem_increment_dollars', $configured)
            || ! array_key_exists('max_redeemable_per_order_dollars', $configured)
            || ! array_key_exists('max_open_codes', $configured);

        if ($usesLegacyStorefrontConfig && array_key_exists('points_per_dollar', $fallback)) {
            app(CandleCashLegacyCompatibilityService::class)->record(
                'config.marketing.candle_cash.points_per_dollar',
                'config_fallback',
                __METHOD__
            );
        }

        if (! $usesLegacyStorefrontConfig && array_key_exists('points_per_dollar', $configured)) {
            app(CandleCashLegacyCompatibilityService::class)->record(
                'marketing_settings.candle_cash_program_config.points_per_dollar',
                'config_fallback',
                __METHOD__
            );
        }

        $legacyPointsPerCandleCash = $usesLegacyStorefrontConfig
            ? (int) data_get($fallback, 'legacy_points_per_candle_cash', data_get($fallback, 'points_per_dollar', self::DEFAULT_LEGACY_POINTS_PER_CANDLE_CASH))
            : (int) data_get(
                $configured,
                'legacy_points_per_candle_cash',
                data_get(
                    $configured,
                    'points_per_dollar',
                    data_get($fallback, 'legacy_points_per_candle_cash', data_get($fallback, 'points_per_dollar', self::DEFAULT_LEGACY_POINTS_PER_CANDLE_CASH))
                )
            );

        $redeemIncrement = $usesLegacyStorefrontConfig
            ? (float) data_get($fallback, 'redeem_increment_dollars', 10)
            : (float) data_get($configured, 'redeem_increment_dollars', data_get($fallback, 'redeem_increment_dollars', 10));

        $maxRedeemablePerOrder = $usesLegacyStorefrontConfig
            ? (float) data_get($fallback, 'max_redeemable_per_order_dollars', 10)
            : (float) data_get($configured, 'max_redeemable_per_order_dollars', data_get($fallback, 'max_redeemable_per_order_dollars', 10));

        $maxOpenCodes = $usesLegacyStorefrontConfig
            ? (int) data_get($fallback, 'max_open_codes', 1)
            : (int) data_get($configured, 'max_open_codes', data_get($fallback, 'max_open_codes', 1));

        $storefrontRewardType = $usesLegacyStorefrontConfig
            ? (string) data_get($fallback, 'storefront_reward_type', 'coupon')
            : (string) data_get($configured, 'storefront_reward_type', data_get($fallback, 'storefront_reward_type', 'coupon'));

        $storefrontRewardValue = $usesLegacyStorefrontConfig
            ? (string) data_get($fallback, 'storefront_reward_value', '10USD')
            : (string) data_get($configured, 'storefront_reward_value', data_get($fallback, 'storefront_reward_value', '10USD'));

        $candleClubMultiplierEnabled = $usesLegacyStorefrontConfig
            ? (bool) data_get($fallback, 'candle_club_multiplier_enabled', true)
            : (bool) data_get($configured, 'candle_club_multiplier_enabled', data_get($fallback, 'candle_club_multiplier_enabled', true));

        $candleClubMultiplierValue = $usesLegacyStorefrontConfig
            ? (float) data_get($fallback, 'candle_club_multiplier_value', 2)
            : (float) data_get($configured, 'candle_club_multiplier_value', data_get($fallback, 'candle_club_multiplier_value', 2));

        $candleClubFreeShippingEnabled = $usesLegacyStorefrontConfig
            ? (bool) data_get($fallback, 'candle_club_free_shipping_enabled', false)
            : (bool) data_get($configured, 'candle_club_free_shipping_enabled', data_get($fallback, 'candle_club_free_shipping_enabled', false));

        return $this->programConfigCache[$cacheKey] = [
            'candle_cash_units_per_dollar' => self::CANONICAL_CANDLE_CASH_UNITS_PER_DOLLAR,
            'legacy_points_per_candle_cash' => max(1, $legacyPointsPerCandleCash),
            'canonical_candle_cash_ratio' => self::CANONICAL_CANDLE_CASH_UNITS_PER_DOLLAR,
            'redeem_increment_dollars' => round(max(0.01, $redeemIncrement), 2),
            'max_redeemable_per_order_dollars' => round(max(0.01, $maxRedeemablePerOrder), 2),
            'max_open_codes' => max(1, $maxOpenCodes),
            'storefront_reward_type' => strtolower(trim($storefrontRewardType)) ?: 'coupon',
            'storefront_reward_value' => trim($storefrontRewardValue) ?: '10USD',
            'candle_club_multiplier_enabled' => $candleClubMultiplierEnabled,
            'candle_club_multiplier_value' => round(max(1, $candleClubMultiplierValue), 2),
            'candle_club_free_shipping_enabled' => $candleClubFreeShippingEnabled,
        ];
    }

    public function pointsPerDollar(): int
    {
        return self::CANONICAL_CANDLE_CASH_UNITS_PER_DOLLAR;
    }

    public function legacyPointsPerCandleCash(?int $tenantId = null): int
    {
        return (int) data_get($this->programConfig($tenantId), 'legacy_points_per_candle_cash', self::DEFAULT_LEGACY_POINTS_PER_CANDLE_CASH);
    }

    public function pointsFromAmount(float|int|string $amount): int
    {
        return max(0, (int) round((float) $amount));
    }

    public function normalizeStoredCandleCash(float|int|string|null $amount): float
    {
        return CandleCashMeasurement::normalizeStoredAmount($amount);
    }

    public function legacyPointsToStartingCandleCash(float|int|string|null $points): float
    {
        return CandleCashMeasurement::legacyPointsToStartingCandleCash($points);
    }

    public function amountFromPoints(float|int|string $points): float
    {
        return CandleCashMeasurement::displayAmount($points);
    }

    public function legacyPointsFromCandleCash(float|int|string $amount, ?int $tenantId = null): int
    {
        return max(0, (int) round(((float) $amount) * $this->legacyPointsPerCandleCash($tenantId)));
    }

    public function candleCashFromLegacyPoints(int|float $points, ?int $tenantId = null): float
    {
        return round(((float) $points) / $this->legacyPointsPerCandleCash($tenantId), 2);
    }

    public function fixedRedemptionAmount(?int $tenantId = null): float
    {
        return (float) data_get($this->programConfig($tenantId), 'redeem_increment_dollars', 10);
    }

    public function fixedRedemptionFormatted(?int $tenantId = null): string
    {
        return $this->formatRewardCurrency($this->fixedRedemptionAmount($tenantId));
    }

    public function fixedRedemptionPoints(?int $tenantId = null): int
    {
        return $this->pointsFromAmount($this->fixedRedemptionAmount($tenantId));
    }

    public function maxRedeemablePerOrderAmount(?int $tenantId = null): float
    {
        return (float) data_get($this->programConfig($tenantId), 'max_redeemable_per_order_dollars', 10);
    }

    public function maxOpenStorefrontCodes(?int $tenantId = null): int
    {
        return (int) data_get($this->programConfig($tenantId), 'max_open_codes', 1);
    }

    public function candleClubMultiplierEnabled(?int $tenantId = null): bool
    {
        return (bool) data_get($this->programConfig($tenantId), 'candle_club_multiplier_enabled', true);
    }

    public function candleClubMultiplierValue(?int $tenantId = null): float
    {
        return round(max(1, (float) data_get($this->programConfig($tenantId), 'candle_club_multiplier_value', 2)), 2);
    }

    public function candleClubFreeShippingEnabled(?int $tenantId = null): bool
    {
        return (bool) data_get($this->programConfig($tenantId), 'candle_club_free_shipping_enabled', false);
    }

    public function formatCurrency(float|int|string $amount): string
    {
        return '$' . number_format(round((float) $amount, 2), 2);
    }

    public function formatRewardCurrency(float|int|string $amount): string
    {
        $numeric = round((float) $amount, 2);
        $precision = fmod(abs($numeric), 1.0) === 0.0 ? 0 : 2;

        return '$' . number_format($numeric, $precision);
    }

    public function formatCandleCash(float|int|string $amount): string
    {
        return $this->formatRewardCurrency($amount) . ' reward credit';
    }

    public function signedCurrencyLabel(float|int|string $amount): string
    {
        $numeric = round((float) $amount, 2);

        return ($numeric >= 0 ? '+' : '-') . $this->formatCurrency(abs($numeric));
    }

    public function candleCashAmountLabelFromPoints(float|int|string $points, bool $signed = false): string
    {
        $numeric = (float) $points;
        $amount = $this->amountFromPoints(abs($numeric));

        if ($signed) {
            return ($numeric >= 0 ? '+' : '-') . $this->formatCurrency($amount);
        }

        return $this->formatCurrency($amount);
    }

    /**
     * @return array{
     *   candle_cash:float,
     *   candle_cash_formatted:string,
     *   candle_cash_amount:float,
     *   candle_cash_amount_formatted:string,
     *   amount:float,
     *   amount_formatted:string
     * }
     */
    public function balancePayloadFromPoints(float|int|string $points): array
    {
        $amount = $this->amountFromPoints($points);

        return [
            'candle_cash' => $amount,
            'candle_cash_formatted' => $this->formatCandleCash($amount),
            'candle_cash_amount' => $amount,
            'candle_cash_amount_formatted' => $this->formatCurrency($amount),
            'amount' => $amount,
            'amount_formatted' => $this->formatCurrency($amount),
        ];
    }

    /**
     * @return array{
     *   canonical_measurement_label:string,
     *   redeem_increment_dollars:float,
     *   redeem_increment_formatted:string,
     *   redeem_increment_candle_cash:float,
     *   redeem_increment_candle_cash_formatted:string,
     *   max_redeemable_per_order_dollars:float,
     *   max_redeemable_per_order_formatted:string,
     *   max_redemptions_per_order:int
     * }
     */
    public function redemptionRulesPayload(?int $tenantId = null): array
    {
        return [
            'canonical_measurement_label' => '1 reward credit = $1.00',
            'redeem_increment_dollars' => $this->fixedRedemptionAmount($tenantId),
            'redeem_increment_formatted' => $this->formatRewardCurrency($this->fixedRedemptionAmount($tenantId)),
            'redeem_increment_candle_cash' => $this->fixedRedemptionAmount($tenantId),
            'redeem_increment_candle_cash_formatted' => $this->formatCandleCash($this->fixedRedemptionAmount($tenantId)),
            'max_redeemable_per_order_dollars' => $this->maxRedeemablePerOrderAmount($tenantId),
            'max_redeemable_per_order_formatted' => $this->formatRewardCurrency($this->maxRedeemablePerOrderAmount($tenantId)),
            'max_redemptions_per_order' => 1,
        ];
    }

    public function rewardValueAmount(CandleCashReward|array|null $reward): float
    {
        if (! $reward) {
            return 0.0;
        }

        $value = is_array($reward)
            ? (string) data_get($reward, 'reward_value', data_get($reward, 'amount', '0'))
            : (string) ($reward->reward_value ?? '0');

        if (preg_match('/-?\d+(?:\.\d+)?/', $value, $matches) === 1) {
            return round((float) $matches[0], 2);
        }

        return 0.0;
    }

    public function isStorefrontReward(?CandleCashReward $reward, ?int $tenantId = null): bool
    {
        if (! $reward) {
            return false;
        }

        $type = strtolower(trim((string) $reward->reward_type));
        $expectedType = (string) data_get($this->programConfig($tenantId), 'storefront_reward_type', 'coupon');
        $amount = $this->rewardValueAmount($reward);

        if ($type === $expectedType && abs($amount - $this->fixedRedemptionAmount($tenantId)) < 0.01) {
            return true;
        }

        return $type === 'coupon' && str_contains(strtolower((string) $reward->name), '$10');
    }

    public function storefrontReward(?int $tenantId = null): ?CandleCashReward
    {
        /** @var CandleCashReward|null $reward */
        $reward = CandleCashReward::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'description', 'candle_cash_cost', 'reward_type', 'reward_value', 'is_active'])
            ->sortBy(function (CandleCashReward $row) use ($tenantId): array {
                $isExact = $this->isStorefrontReward($row, $tenantId) ? 0 : 1;
                $amountDelta = abs($this->rewardValueAmount($row) - $this->fixedRedemptionAmount($tenantId));
                $typePriority = strtolower(trim((string) $row->reward_type)) === 'coupon' ? 0 : 1;

                return [$isExact, $typePriority, $amountDelta, (int) $row->id];
            })
            ->first();

        return $reward instanceof CandleCashReward ? $reward : null;
    }

    public function storefrontRewardPointsCost(CandleCashReward $reward, ?int $tenantId = null): int
    {
        return $this->isStorefrontReward($reward, $tenantId)
            ? $this->fixedRedemptionPoints($tenantId)
            : (int) $reward->candle_cash_cost;
    }

    public function storefrontRedemptionMatchesCurrentRules(
        CandleCashRedemption $redemption,
        ?CandleCashReward $reward = null,
        ?int $tenantId = null
    ): bool {
        $reward = $reward ?: $redemption->reward;
        $tenantId = $this->resolvedTenantId($tenantId, null, $redemption);

        if (! $this->isStorefrontReward($reward, $tenantId)) {
            return true;
        }

        return (int) $redemption->candle_cash_spent === $this->fixedRedemptionPoints($tenantId);
    }

    public function redemptionAmountForIssuedCode(
        CandleCashRedemption $redemption,
        ?CandleCashReward $reward = null,
        ?int $tenantId = null
    ): float {
        $reward = $reward ?: $redemption->reward;
        $tenantId = $this->resolvedTenantId($tenantId, null, $redemption);

        if ($this->isStorefrontReward($reward, $tenantId)) {
            return $this->fixedRedemptionAmount($tenantId);
        }

        return $this->amountFromPoints($redemption->candle_cash_spent);
    }

    public function cancelStaleStorefrontRedemptions(
        MarketingProfile $profile,
        CandleCashReward $reward,
        string $platform = 'shopify',
        ?int $tenantId = null
    ): int {
        $tenantId = $this->resolvedTenantId($tenantId, $profile);

        if (! $this->isStorefrontReward($reward, $tenantId)) {
            return 0;
        }

        return CandleCashRedemption::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('reward_id', $reward->id)
            ->where(function ($query) use ($platform): void {
                $query->whereNull('platform')->orWhere('platform', $platform);
            })
            ->where('status', 'issued')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where('candle_cash_spent', '!=', $this->fixedRedemptionPoints($tenantId))
            ->update([
                'status' => 'canceled',
                'canceled_at' => now(),
                'reconciliation_notes' => 'Canceled automatically after reward storefront standardization.',
            ]);
    }

    public function cancelIssuedRedemptionAndRestoreBalance(
        CandleCashRedemption $redemption,
        string $reason = 'Canceled automatically because Shopify could not prepare the reward discount.'
    ): array {
        return DB::transaction(function () use ($redemption, $reason): array {
            /** @var CandleCashRedemption|null $lockedRedemption */
            $lockedRedemption = CandleCashRedemption::query()
                ->lockForUpdate()
                ->with('profile')
                ->find($redemption->id);

            if (! $lockedRedemption) {
                return [
                    'restored' => false,
                    'balance' => 0,
                ];
            }

            $profileId = (int) $lockedRedemption->marketing_profile_id;
            $balance = CandleCashBalance::query()->lockForUpdate()->firstOrCreate(
                ['marketing_profile_id' => $profileId],
                ['balance' => 0]
            );

            if ((string) $lockedRedemption->status !== 'issued') {
                return [
                    'restored' => false,
                    'balance' => CandleCashMeasurement::normalizeStoredAmount($balance->balance),
                ];
            }

            $restorePoints = max(0, (int) $lockedRedemption->candle_cash_spent);
            $nextBalance = CandleCashMeasurement::normalizeStoredAmount($balance->balance + $restorePoints);

            $balance->forceFill(['balance' => $nextBalance])->save();

            $restoredAt = now();
            $lockedRedemption->forceFill([
                'status' => 'canceled',
                'canceled_at' => $restoredAt,
                'reconciliation_notes' => $reason,
            ]);

            $restoreTransaction = CandleCashTransaction::query()->create([
                'marketing_profile_id' => $profileId,
                'type' => 'adjustment',
                'candle_cash_delta' => $restorePoints,
                'source' => 'reward',
                'source_id' => (string) $lockedRedemption->id,
                'description' => $reason,
            ]);

            $lockedRedemption->forceFill([
                'redemption_context' => $this->mergeRedemptionContext((array) $lockedRedemption->redemption_context, [
                    'cancellation_reason_code' => 'shopify_discount_sync_failed',
                    'restoration_applied' => true,
                    'restoration_transaction_id' => (int) $restoreTransaction->id,
                    'restored_candle_cash_points' => $restorePoints,
                    'restored_at' => $restoredAt->toIso8601String(),
                ]),
            ])->save();

            return [
                'restored' => true,
                'balance' => $nextBalance,
            ];
        });
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{
     *   ok:bool,
     *   already_redeemed:bool,
     *   restoration_reversed:bool,
     *   balance:float,
     *   error:?string
     * }
     */
    public function finalizeRedemptionFromVerifiedOrder(
        CandleCashRedemption $redemption,
        string $externalOrderSource,
        string $externalOrderId,
        string $redeemedChannel = 'shopify_ingest',
        array $context = []
    ): array {
        $externalOrderSource = strtolower(trim($externalOrderSource));
        $externalOrderId = trim($externalOrderId);
        $redeemedChannel = trim($redeemedChannel) !== '' ? trim($redeemedChannel) : 'shopify_ingest';

        if ($externalOrderSource === '' || $externalOrderId === '') {
            return [
                'ok' => false,
                'already_redeemed' => false,
                'restoration_reversed' => false,
                'balance' => 0.0,
                'error' => 'missing_external_order_reference',
            ];
        }

        return DB::transaction(function () use ($redemption, $externalOrderSource, $externalOrderId, $redeemedChannel, $context): array {
            /** @var CandleCashRedemption|null $lockedRedemption */
            $lockedRedemption = CandleCashRedemption::query()
                ->lockForUpdate()
                ->find($redemption->id);

            if (! $lockedRedemption) {
                return [
                    'ok' => false,
                    'already_redeemed' => false,
                    'restoration_reversed' => false,
                    'balance' => 0.0,
                    'error' => 'redemption_not_found',
                ];
            }

            $profileId = (int) $lockedRedemption->marketing_profile_id;
            $balance = CandleCashBalance::query()->lockForUpdate()->firstOrCreate(
                ['marketing_profile_id' => $profileId],
                ['balance' => 0]
            );
            $currentBalance = CandleCashMeasurement::normalizeStoredAmount($balance->balance);

            if ((string) $lockedRedemption->status === 'redeemed') {
                $matchesExternalOrder = (string) ($lockedRedemption->external_order_source ?? '') === $externalOrderSource
                    && (string) ($lockedRedemption->external_order_id ?? '') === $externalOrderId;

                return [
                    'ok' => $matchesExternalOrder,
                    'already_redeemed' => $matchesExternalOrder,
                    'restoration_reversed' => false,
                    'balance' => $currentBalance,
                    'error' => $matchesExternalOrder ? null : 'already_redeemed_for_different_order',
                ];
            }

            if (! in_array((string) $lockedRedemption->status, ['issued', 'canceled'], true)) {
                return [
                    'ok' => false,
                    'already_redeemed' => false,
                    'restoration_reversed' => false,
                    'balance' => $currentBalance,
                    'error' => 'unsupported_redemption_status',
                ];
            }

            $pointsSpent = max(0, (int) $lockedRedemption->candle_cash_spent);
            $restorationReversed = false;
            if ($pointsSpent > 0 && $this->restorationShouldBeReversed($lockedRedemption)) {
                $alreadyReversed = CandleCashTransaction::query()
                    ->where('marketing_profile_id', $profileId)
                    ->where('source', 'reward_reconciliation')
                    ->where('source_id', (string) $lockedRedemption->id)
                    ->where('type', 'adjustment')
                    ->where('candle_cash_delta', -$pointsSpent)
                    ->exists();

                if (! $alreadyReversed) {
                    $nextBalance = CandleCashMeasurement::normalizeStoredAmount($currentBalance - $pointsSpent);
                    $balance->forceFill(['balance' => $nextBalance])->save();
                    $currentBalance = $nextBalance;

                    CandleCashTransaction::query()->create([
                        'marketing_profile_id' => $profileId,
                        'type' => 'adjustment',
                        'candle_cash_delta' => -$pointsSpent,
                        'source' => 'reward_reconciliation',
                        'source_id' => (string) $lockedRedemption->id,
                        'description' => 'Re-applied reward redemption after verified order reconciliation.',
                    ]);
                    $restorationReversed = true;
                }
            }

            $existingContext = (array) $lockedRedemption->redemption_context;
            $incomingContext = [
                ...$context,
                'finalized_via_order_reconciliation' => true,
                'finalized_external_order_source' => $externalOrderSource,
                'finalized_external_order_id' => $externalOrderId,
                'finalized_from_status' => (string) $lockedRedemption->status,
                'restoration_reversed' => $restorationReversed,
                'attribution_meta' => $this->attributionSourceMetaForContext($existingContext, $context),
            ];

            $lockedRedemption->forceFill([
                'status' => 'redeemed',
                'platform' => $this->platformForExternalOrderSource($externalOrderSource, (string) ($lockedRedemption->platform ?? '')),
                'redeemed_channel' => $redeemedChannel,
                'external_order_source' => $externalOrderSource,
                'external_order_id' => $externalOrderId,
                'redeemed_at' => $lockedRedemption->redeemed_at ?: now(),
                'canceled_at' => null,
                'redemption_context' => $this->mergeRedemptionContext($existingContext, $incomingContext),
            ])->save();

            return [
                'ok' => true,
                'already_redeemed' => false,
                'restoration_reversed' => $restorationReversed,
                'balance' => $currentBalance,
                'error' => null,
            ];
        });
    }

    /**
     * @return array<string,mixed>|null
     */
    public function storefrontRewardPayload(?CandleCashReward $reward, float|int|string|null $balancePoints = null, ?int $tenantId = null): ?array
    {
        if (! $reward) {
            return null;
        }

        $pointsCost = $this->storefrontRewardPointsCost($reward, $tenantId);
        $amount = $this->fixedRedemptionAmount($tenantId);
        $normalizedBalance = $balancePoints !== null ? CandleCashMeasurement::normalizeStoredAmount($balancePoints) : null;

        return [
            'id' => (int) $reward->id,
            'name' => 'Redeem ' . $this->formatRewardCurrency($amount) . ' Reward Credit',
            'description' => 'Apply ' . $this->formatRewardCurrency($amount) . ' off this order. Reward credit is redeemed in $10 increments, with a limit of $10 per order.',
            'reward_type' => (string) $reward->reward_type,
            'reward_value' => $reward->reward_value !== null ? (string) $reward->reward_value : null,
            'candle_cash_cost' => $amount,
            'candle_cash_cost_formatted' => $this->formatCandleCash($amount),
            'candle_cash_amount' => $amount,
            'candle_cash_amount_formatted' => $this->formatRewardCurrency($amount),
            'is_redeemable_now' => $normalizedBalance !== null ? $normalizedBalance >= $pointsCost : null,
            'redeem_increment_dollars' => $amount,
            'redeem_increment_formatted' => $this->formatRewardCurrency($amount),
            'limit_per_order_dollars' => $this->maxRedeemablePerOrderAmount($tenantId),
            'limit_per_order_formatted' => $this->formatRewardCurrency($this->maxRedeemablePerOrderAmount($tenantId)),
            'max_redemptions_per_order' => 1,
        ];
    }

    public function ensureBalance(MarketingProfile $profile): CandleCashBalance
    {
        return CandleCashBalance::query()->firstOrCreate(
            ['marketing_profile_id' => $profile->id],
            ['balance' => 0]
        );
    }

    public function currentBalance(MarketingProfile $profile): float
    {
        return CandleCashMeasurement::normalizeStoredAmount($this->ensureBalance($profile)->balance);
    }

    /**
     * @param  array<string,mixed>  $extraAttributes
     * @return array{balance:float,transaction_id:int}
     */
    public function addPoints(
        MarketingProfile $profile,
        int $points,
        string $type = 'earn',
        string $source = 'admin',
        ?string $sourceId = null,
        ?string $description = null,
        array $extraAttributes = []
    ): array {
        $points = (int) $points;

        return DB::transaction(function () use ($profile, $points, $type, $source, $sourceId, $description, $extraAttributes): array {
            $balance = CandleCashBalance::query()->lockForUpdate()->firstOrCreate(
                ['marketing_profile_id' => $profile->id],
                ['balance' => 0]
            );

            $next = CandleCashMeasurement::normalizeStoredAmount($balance->balance + $points);
            $balance->forceFill(['balance' => $next])->save();

            $transaction = CandleCashTransaction::query()->create(array_merge([
                'marketing_profile_id' => $profile->id,
                'type' => $type,
                'candle_cash_delta' => $points,
                'source' => $source,
                'source_id' => $sourceId,
                'description' => $description,
            ], $extraAttributes));

            return [
                'balance' => $next,
                'transaction_id' => (int) $transaction->id,
            ];
        });
    }

    /**
     * @param  array<string,mixed>  $extraAttributes
     * @return array{balance:float,transaction_id:int,already_awarded:bool}
     */
    public function addPointsIdempotent(
        MarketingProfile $profile,
        int $points,
        string $source,
        string $sourceId,
        string $type = 'gift',
        ?string $description = null,
        array $extraAttributes = []
    ): array {
        $points = (int) $points;
        $source = trim($source);
        $sourceId = trim($sourceId);

        if ($source === '' || $sourceId === '') {
            $result = $this->addPoints(
                profile: $profile,
                points: $points,
                type: $type,
                source: $source !== '' ? $source : 'admin',
                sourceId: $sourceId !== '' ? $sourceId : null,
                description: $description,
                extraAttributes: $extraAttributes
            );

            return [
                'balance' => round((float) ($result['balance'] ?? 0), 3),
                'transaction_id' => (int) ($result['transaction_id'] ?? 0),
                'already_awarded' => false,
            ];
        }

        return DB::transaction(function () use ($profile, $points, $source, $sourceId, $type, $description, $extraAttributes): array {
            MarketingProfile::query()
                ->whereKey($profile->id)
                ->lockForUpdate()
                ->first();

            $balance = CandleCashBalance::query()->lockForUpdate()->firstOrCreate(
                ['marketing_profile_id' => $profile->id],
                ['balance' => 0]
            );

            $existing = CandleCashTransaction::query()
                ->where('marketing_profile_id', $profile->id)
                ->where('source', $source)
                ->where('source_id', $sourceId)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($existing instanceof CandleCashTransaction) {
                return [
                    'balance' => CandleCashMeasurement::normalizeStoredAmount($balance->balance),
                    'transaction_id' => (int) $existing->id,
                    'already_awarded' => true,
                ];
            }

            $next = CandleCashMeasurement::normalizeStoredAmount($balance->balance + $points);
            $balance->forceFill(['balance' => $next])->save();

            $transaction = CandleCashTransaction::query()->create(array_merge([
                'marketing_profile_id' => $profile->id,
                'type' => $type,
                'candle_cash_delta' => $points,
                'source' => $source,
                'source_id' => $sourceId,
                'description' => $description,
            ], $extraAttributes));

            return [
                'balance' => $next,
                'transaction_id' => (int) $transaction->id,
                'already_awarded' => false,
            ];
        });
    }

    /**
     * @return array{ok:bool,balance:float,redemption_id:?int,code:?string,error:?string}
     */
    public function redeemReward(MarketingProfile $profile, CandleCashReward $reward, ?string $platform = null): array
    {
        if (! $reward->is_active) {
            return [
                'ok' => false,
                'balance' => $this->currentBalance($profile),
                'redemption_id' => null,
                'code' => null,
                'error' => 'inactive_reward',
            ];
        }

        return DB::transaction(function () use ($profile, $reward, $platform): array {
            $balance = CandleCashBalance::query()->lockForUpdate()->firstOrCreate(
                ['marketing_profile_id' => $profile->id],
                ['balance' => 0]
            );

            $normalizedPlatform = strtolower(trim((string) $platform));
            $cost = in_array($normalizedPlatform, ['shopify', 'public_lookup'], true)
                ? $this->storefrontRewardPointsCost($reward)
                : (int) $reward->candle_cash_cost;
            $current = CandleCashMeasurement::normalizeStoredAmount($balance->balance);
            if ($current < $cost) {
                return [
                    'ok' => false,
                    'balance' => $current,
                    'redemption_id' => null,
                    'code' => null,
                    'error' => 'insufficient_balance',
                ];
            }

            $next = CandleCashMeasurement::normalizeStoredAmount($current - $cost);
            $balance->forceFill(['balance' => $next])->save();

            $code = $this->generateRedemptionCode();
            $expiryDays = max(1, (int) data_get(config('marketing', []), 'candle_cash.code_expiry_days', 30));
            $redemption = CandleCashRedemption::query()->create([
                'marketing_profile_id' => $profile->id,
                'reward_id' => $reward->id,
                'candle_cash_spent' => $cost,
                'platform' => $platform ? strtolower(trim($platform)) : null,
                'status' => 'issued',
                'redemption_code' => $code,
                'issued_at' => now(),
                'expires_at' => now()->addDays($expiryDays),
                'redeemed_at' => null,
            ]);

            CandleCashTransaction::query()->create([
                'marketing_profile_id' => $profile->id,
                'type' => 'redeem',
                'candle_cash_delta' => -$cost,
                'source' => 'reward',
                'source_id' => (string) $redemption->id,
                'description' => 'Redeemed reward: ' . $reward->name,
            ]);

            return [
                'ok' => true,
                'balance' => $next,
                'redemption_id' => (int) $redemption->id,
                'code' => $code,
                'error' => null,
            ];
        });
    }

    /**
     * @return array{
     *  ok:bool,
     *  balance:float,
     *  redemption_id:?int,
     *  code:?string,
     *  error:?string,
     *  state:string
     * }
     */
    public function requestStorefrontRedemption(
        MarketingProfile $profile,
        CandleCashReward $reward,
        string $platform = 'shopify',
        bool $reuseActiveCode = true,
        ?int $tenantId = null
    ): array {
        $platform = strtolower(trim($platform)) ?: 'shopify';
        $tenantId = $this->resolvedTenantId($tenantId, $profile);

        if (! $reward->is_active) {
            return [
                'ok' => false,
                'balance' => $this->currentBalance($profile),
                'redemption_id' => null,
                'code' => null,
                'error' => 'reward_unavailable',
                'state' => 'reward_unavailable',
            ];
        }

        if (! $this->isStorefrontReward($reward, $tenantId)) {
            return [
                'ok' => false,
                'balance' => $this->currentBalance($profile),
                'redemption_id' => null,
                'code' => null,
                'error' => 'reward_unavailable',
                'state' => 'reward_unavailable',
            ];
        }

        $this->cancelStaleStorefrontRedemptions($profile, $reward, $platform, $tenantId);

        if ($reuseActiveCode) {
            $active = $this->activeRedemptionForReward($profile, $reward, $platform, $tenantId);
            if ($active) {
                return [
                    'ok' => true,
                    'balance' => $this->currentBalance($profile),
                    'redemption_id' => (int) $active->id,
                    'code' => (string) $active->redemption_code,
                    'error' => null,
                    'state' => 'already_has_active_code',
                ];
            }
        }

        $openIssuedCount = CandleCashRedemption::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('status', 'issued')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();
        if ($openIssuedCount >= $this->maxOpenStorefrontCodes($tenantId)) {
            return [
                'ok' => false,
                'balance' => $this->currentBalance($profile),
                'redemption_id' => null,
                'code' => null,
                'error' => 'redemption_blocked',
                'state' => 'redemption_blocked',
            ];
        }

        $result = $this->redeemReward($profile, $reward, $platform);

        if (! (bool) ($result['ok'] ?? false)) {
            $error = (string) ($result['error'] ?? 'redemption_failed');

            return [
                'ok' => false,
                'balance' => CandleCashMeasurement::normalizeStoredAmount($result['balance'] ?? $this->currentBalance($profile)),
                'redemption_id' => null,
                'code' => null,
                'error' => match ($error) {
                    'insufficient_balance' => 'insufficient_candle_cash',
                    'inactive_reward' => 'reward_unavailable',
                    default => 'redemption_failed',
                },
                'state' => match ($error) {
                    'insufficient_balance' => 'insufficient_candle_cash',
                    'inactive_reward' => 'reward_unavailable',
                    default => 'try_again_later',
                },
            ];
        }

        return [
            'ok' => true,
            'balance' => CandleCashMeasurement::normalizeStoredAmount($result['balance'] ?? $this->currentBalance($profile)),
            'redemption_id' => (int) ($result['redemption_id'] ?? 0),
            'code' => (string) ($result['code'] ?? ''),
            'error' => null,
            'state' => 'code_issued',
        ];
    }

    protected function activeRedemptionForReward(
        MarketingProfile $profile,
        CandleCashReward $reward,
        string $platform,
        ?int $tenantId = null
    ): ?CandleCashRedemption {
        $redemption = CandleCashRedemption::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('reward_id', $reward->id)
            ->where(function ($query) use ($platform): void {
                $query->whereNull('platform')->orWhere('platform', $platform);
            })
            ->where('status', 'issued')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('id')
            ->first();

        if (! $redemption) {
            return null;
        }

        return $this->storefrontRedemptionMatchesCurrentRules($redemption, $reward, $tenantId)
            ? $redemption
            : null;
    }

    protected function resolvedTenantId(
        ?int $tenantId = null,
        ?MarketingProfile $profile = null,
        ?CandleCashRedemption $redemption = null
    ): ?int {
        if ($tenantId !== null && $tenantId > 0) {
            return $tenantId;
        }

        if ($profile && is_numeric($profile->tenant_id) && (int) $profile->tenant_id > 0) {
            return (int) $profile->tenant_id;
        }

        if (! $redemption) {
            return null;
        }

        $contextTenantId = data_get($redemption->redemption_context, 'tenant_id');
        if (is_numeric($contextTenantId) && (int) $contextTenantId > 0) {
            return (int) $contextTenantId;
        }

        $loadedProfile = $redemption->relationLoaded('profile')
            ? $redemption->profile
            : $redemption->profile()->first(['id', 'tenant_id']);

        return $loadedProfile && is_numeric($loadedProfile->tenant_id) && (int) $loadedProfile->tenant_id > 0
            ? (int) $loadedProfile->tenant_id
            : null;
    }

    protected function generateRedemptionCode(): string
    {
        do {
            $code = 'CC-' . Str::upper(Str::random(10));
        } while (CandleCashRedemption::query()->where('redemption_code', $code)->exists());

        return $code;
    }

    /**
     * @param  array<string,mixed>  $existing
     * @param  array<string,mixed>  $incoming
     * @return array<string,mixed>
     */
    protected function mergeRedemptionContext(array $existing, array $incoming): array
    {
        return array_filter([
            ...$existing,
            ...$incoming,
            'updated_at' => now()->toIso8601String(),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    protected function restorationShouldBeReversed(CandleCashRedemption $redemption): bool
    {
        $context = (array) $redemption->redemption_context;
        if ((bool) ($context['restoration_applied'] ?? false)) {
            return true;
        }

        $reasonCode = strtolower(trim((string) ($context['cancellation_reason_code'] ?? '')));
        if ($reasonCode === 'shopify_discount_sync_failed') {
            return true;
        }

        $notes = strtolower(trim((string) ($redemption->reconciliation_notes ?? '')));
        if ($notes !== '' && str_contains($notes, 'could not prepare the reward discount')) {
            return true;
        }

        return CandleCashTransaction::query()
            ->where('marketing_profile_id', (int) $redemption->marketing_profile_id)
            ->where('source', 'reward')
            ->where('source_id', (string) $redemption->id)
            ->where('type', 'adjustment')
            ->where('candle_cash_delta', '>', 0)
            ->exists();
    }

    protected function platformForExternalOrderSource(string $externalOrderSource, string $currentPlatform = ''): string
    {
        if (str_starts_with($externalOrderSource, 'square')) {
            return 'square';
        }

        if (trim($currentPlatform) !== '') {
            return trim($currentPlatform);
        }

        return 'shopify';
    }

    /**
     * @param  array<string,mixed>  $existing
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    protected function attributionSourceMetaForContext(array $existing, array $context): array
    {
        $existingMeta = is_array($existing['attribution_meta'] ?? null) ? $existing['attribution_meta'] : [];
        $incomingMeta = is_array($context['attribution_meta'] ?? null) ? $context['attribution_meta'] : [];

        return array_filter([
            ...$existingMeta,
            ...$incomingMeta,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }
}
