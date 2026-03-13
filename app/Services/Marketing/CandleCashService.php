<?php

namespace App\Services\Marketing;

use App\Models\CandleCashBalance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CandleCashService
{
    public function ensureBalance(MarketingProfile $profile): CandleCashBalance
    {
        return CandleCashBalance::query()->firstOrCreate(
            ['marketing_profile_id' => $profile->id],
            ['balance' => 0]
        );
    }

    public function currentBalance(MarketingProfile $profile): int
    {
        return (int) $this->ensureBalance($profile)->balance;
    }

    /**
     * @return array{balance:int,transaction_id:int}
     */
    public function addPoints(
        MarketingProfile $profile,
        int $points,
        string $type = 'earn',
        string $source = 'admin',
        ?string $sourceId = null,
        ?string $description = null
    ): array {
        $points = (int) $points;

        return DB::transaction(function () use ($profile, $points, $type, $source, $sourceId, $description): array {
            $balance = CandleCashBalance::query()->lockForUpdate()->firstOrCreate(
                ['marketing_profile_id' => $profile->id],
                ['balance' => 0]
            );

            $next = (int) $balance->balance + $points;
            $balance->forceFill(['balance' => $next])->save();

            $transaction = CandleCashTransaction::query()->create([
                'marketing_profile_id' => $profile->id,
                'type' => $type,
                'points' => $points,
                'source' => $source,
                'source_id' => $sourceId,
                'description' => $description,
            ]);

            return [
                'balance' => $next,
                'transaction_id' => (int) $transaction->id,
            ];
        });
    }

    /**
     * @return array{ok:bool,balance:int,redemption_id:?int,code:?string,error:?string}
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

            $cost = (int) $reward->points_cost;
            $current = (int) $balance->balance;
            if ($current < $cost) {
                return [
                    'ok' => false,
                    'balance' => $current,
                    'redemption_id' => null,
                    'code' => null,
                    'error' => 'insufficient_balance',
                ];
            }

            $next = $current - $cost;
            $balance->forceFill(['balance' => $next])->save();

            $code = $this->generateRedemptionCode();
            $expiryDays = max(1, (int) data_get(config('marketing', []), 'candle_cash.code_expiry_days', 30));
            $redemption = CandleCashRedemption::query()->create([
                'marketing_profile_id' => $profile->id,
                'reward_id' => $reward->id,
                'points_spent' => $cost,
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
                'points' => -$cost,
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
     *  balance:int,
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
        if ($openIssuedCount >= 3) {
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
                'balance' => (int) ($result['balance'] ?? $this->currentBalance($profile)),
                'redemption_id' => null,
                'code' => null,
                'error' => match ($error) {
                    'insufficient_balance' => 'insufficient_points',
                    'inactive_reward' => 'reward_unavailable',
                    default => 'redemption_failed',
                },
                'state' => match ($error) {
                    'insufficient_balance' => 'insufficient_points',
                    'inactive_reward' => 'reward_unavailable',
                    default => 'try_again_later',
                },
            ];
        }

        return [
            'ok' => true,
            'balance' => (int) ($result['balance'] ?? $this->currentBalance($profile)),
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
            ->orderByDesc('id')
            ->first();
    }

    protected function generateRedemptionCode(): string
    {
        do {
            $code = 'CC-' . Str::upper(Str::random(10));
        } while (CandleCashRedemption::query()->where('redemption_code', $code)->exists());

        return $code;
    }
}
