<?php

namespace App\Services\Marketing;

use App\Models\CandleCashBalance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\MarketingSetting;
use App\Support\Marketing\CandleCashMeasurement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CandleCashService
{
    public const DEFAULT_LEGACY_POINTS_PER_CANDLE_CASH = 30;
    public const CANONICAL_CANDLE_CASH_UNITS_PER_DOLLAR = 1;

    protected ?array $programConfigCache = null;

    public function programConfig(): array
    {
        if ($this->programConfigCache !== null) {
            return $this->programConfigCache;
        }

        $setting = MarketingSetting::query()->where('key', 'candle_cash_program_config')->first();
        $configured = (array) ($setting?->value ?? []);
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

        return $this->programConfigCache = [
            'candle_cash_units_per_dollar' => self::CANONICAL_CANDLE_CASH_UNITS_PER_DOLLAR,
            'legacy_points_per_candle_cash' => max(1, $legacyPointsPerCandleCash),
            'canonical_candle_cash_ratio' => self::CANONICAL_CANDLE_CASH_UNITS_PER_DOLLAR,
            'redeem_increment_dollars' => round(max(0.01, $redeemIncrement), 2),
            'max_redeemable_per_order_dollars' => round(max(0.01, $maxRedeemablePerOrder), 2),
            'max_open_codes' => max(1, $maxOpenCodes),
            'storefront_reward_type' => strtolower(trim($storefrontRewardType)) ?: 'coupon',
            'storefront_reward_value' => trim($storefrontRewardValue) ?: '10USD',
        ];
    }

    public function pointsPerDollar(): int
    {
        return self::CANONICAL_CANDLE_CASH_UNITS_PER_DOLLAR;
    }

    public function legacyPointsPerCandleCash(): int
    {
        return (int) data_get($this->programConfig(), 'legacy_points_per_candle_cash', self::DEFAULT_LEGACY_POINTS_PER_CANDLE_CASH);
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

    public function legacyPointsFromCandleCash(float|int|string $amount): int
    {
        return max(0, (int) round(((float) $amount) * $this->legacyPointsPerCandleCash()));
    }

    public function candleCashFromLegacyPoints(int|float $points): float
    {
        return round(((float) $points) / $this->legacyPointsPerCandleCash(), 2);
    }

    public function fixedRedemptionAmount(): float
    {
        return (float) data_get($this->programConfig(), 'redeem_increment_dollars', 10);
    }

    public function fixedRedemptionFormatted(): string
    {
        return $this->formatRewardCurrency($this->fixedRedemptionAmount());
    }

    public function fixedRedemptionPoints(): int
    {
        return $this->pointsFromAmount($this->fixedRedemptionAmount());
    }

    public function maxRedeemablePerOrderAmount(): float
    {
        return (float) data_get($this->programConfig(), 'max_redeemable_per_order_dollars', 10);
    }

    public function maxOpenStorefrontCodes(): int
    {
        return (int) data_get($this->programConfig(), 'max_open_codes', 1);
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
        return $this->formatRewardCurrency($amount) . ' Candle Cash';
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
    public function redemptionRulesPayload(): array
    {
        return [
            'canonical_measurement_label' => '1 Candle Cash = 1 Candle Cash',
            'redeem_increment_dollars' => $this->fixedRedemptionAmount(),
            'redeem_increment_formatted' => $this->formatRewardCurrency($this->fixedRedemptionAmount()),
            'redeem_increment_candle_cash' => $this->fixedRedemptionAmount(),
            'redeem_increment_candle_cash_formatted' => $this->formatCandleCash($this->fixedRedemptionAmount()),
            'max_redeemable_per_order_dollars' => $this->maxRedeemablePerOrderAmount(),
            'max_redeemable_per_order_formatted' => $this->formatRewardCurrency($this->maxRedeemablePerOrderAmount()),
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

    public function isStorefrontReward(?CandleCashReward $reward): bool
    {
        if (! $reward) {
            return false;
        }

        $type = strtolower(trim((string) $reward->reward_type));
        $expectedType = (string) data_get($this->programConfig(), 'storefront_reward_type', 'coupon');
        $amount = $this->rewardValueAmount($reward);

        if ($type === $expectedType && abs($amount - $this->fixedRedemptionAmount()) < 0.01) {
            return true;
        }

        return $type === 'coupon' && str_contains(strtolower((string) $reward->name), '$10');
    }

    public function storefrontReward(): ?CandleCashReward
    {
        /** @var CandleCashReward|null $reward */
        $reward = CandleCashReward::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'description', 'candle_cash_cost', 'reward_type', 'reward_value', 'is_active'])
            ->sortBy(function (CandleCashReward $row): array {
                $isExact = $this->isStorefrontReward($row) ? 0 : 1;
                $amountDelta = abs($this->rewardValueAmount($row) - $this->fixedRedemptionAmount());
                $typePriority = strtolower(trim((string) $row->reward_type)) === 'coupon' ? 0 : 1;

                return [$isExact, $typePriority, $amountDelta, (int) $row->id];
            })
            ->first();

        return $reward instanceof CandleCashReward ? $reward : null;
    }

    public function storefrontRewardPointsCost(CandleCashReward $reward): int
    {
        return $this->isStorefrontReward($reward)
            ? $this->fixedRedemptionPoints()
            : (int) $reward->candle_cash_cost;
    }

    public function storefrontRedemptionMatchesCurrentRules(
        CandleCashRedemption $redemption,
        ?CandleCashReward $reward = null
    ): bool {
        $reward = $reward ?: $redemption->reward;

        if (! $this->isStorefrontReward($reward)) {
            return true;
        }

        return (int) $redemption->candle_cash_spent === $this->fixedRedemptionPoints();
    }

    public function redemptionAmountForIssuedCode(
        CandleCashRedemption $redemption,
        ?CandleCashReward $reward = null
    ): float {
        $reward = $reward ?: $redemption->reward;

        if ($this->isStorefrontReward($reward)) {
            return $this->fixedRedemptionAmount();
        }

        return $this->amountFromPoints($redemption->candle_cash_spent);
    }

    public function cancelStaleStorefrontRedemptions(
        MarketingProfile $profile,
        CandleCashReward $reward,
        string $platform = 'shopify'
    ): int {
        if (! $this->isStorefrontReward($reward)) {
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
            ->where('candle_cash_spent', '!=', $this->fixedRedemptionPoints())
            ->update([
                'status' => 'canceled',
                'canceled_at' => now(),
                'reconciliation_notes' => 'Canceled automatically after Candle Cash storefront standardization.',
            ]);
    }

    public function cancelIssuedRedemptionAndRestoreBalance(
        CandleCashRedemption $redemption,
        string $reason = 'Canceled automatically because Shopify could not prepare the Candle Cash discount.'
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

            $lockedRedemption->forceFill([
                'status' => 'canceled',
                'canceled_at' => now(),
                'reconciliation_notes' => $reason,
            ])->save();

            CandleCashTransaction::query()->create([
                'marketing_profile_id' => $profileId,
                'type' => 'adjustment',
                'candle_cash_delta' => $restorePoints,
                'source' => 'reward',
                'source_id' => (string) $lockedRedemption->id,
                'description' => $reason,
            ]);

            return [
                'restored' => true,
                'balance' => $nextBalance,
            ];
        });
    }

    /**
     * @return array<string,mixed>|null
     */
    public function storefrontRewardPayload(?CandleCashReward $reward, ?int $balancePoints = null): ?array
    {
        if (! $reward) {
            return null;
        }

        $pointsCost = $this->storefrontRewardPointsCost($reward);
        $amount = $this->fixedRedemptionAmount();

        return [
            'id' => (int) $reward->id,
            'name' => 'Redeem ' . $this->formatRewardCurrency($amount) . ' Candle Cash',
            'description' => 'Apply ' . $this->formatRewardCurrency($amount) . ' off this order. Candle Cash is redeemed in $10 increments, with a limit of $10 per order.',
            'reward_type' => (string) $reward->reward_type,
            'reward_value' => $reward->reward_value !== null ? (string) $reward->reward_value : null,
            'candle_cash_cost' => $amount,
            'candle_cash_cost_formatted' => $this->formatCandleCash($amount),
            'candle_cash_amount' => $amount,
            'candle_cash_amount_formatted' => $this->formatRewardCurrency($amount),
            'is_redeemable_now' => $balancePoints !== null ? $balancePoints >= $pointsCost : null,
            'redeem_increment_dollars' => $amount,
            'redeem_increment_formatted' => $this->formatRewardCurrency($amount),
            'limit_per_order_dollars' => $this->maxRedeemablePerOrderAmount(),
            'limit_per_order_formatted' => $this->formatRewardCurrency($this->maxRedeemablePerOrderAmount()),
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
        bool $reuseActiveCode = true
    ): array {
        $platform = strtolower(trim($platform)) ?: 'shopify';

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

        if (! $this->isStorefrontReward($reward)) {
            return [
                'ok' => false,
                'balance' => $this->currentBalance($profile),
                'redemption_id' => null,
                'code' => null,
                'error' => 'reward_unavailable',
                'state' => 'reward_unavailable',
            ];
        }

        $this->cancelStaleStorefrontRedemptions($profile, $reward, $platform);

        if ($reuseActiveCode) {
            $active = $this->activeRedemptionForReward($profile, $reward, $platform);
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
        if ($openIssuedCount >= $this->maxOpenStorefrontCodes()) {
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
        string $platform
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

        return $this->storefrontRedemptionMatchesCurrentRules($redemption, $reward)
            ? $redemption
            : null;
    }

    protected function generateRedemptionCode(): string
    {
        do {
            $code = 'CC-' . Str::upper(Str::random(10));
        } while (CandleCashRedemption::query()->where('redemption_code', $code)->exists());

        return $code;
    }
}
