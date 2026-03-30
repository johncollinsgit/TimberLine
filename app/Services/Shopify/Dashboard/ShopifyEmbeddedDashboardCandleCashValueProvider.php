<?php

namespace App\Services\Shopify\Dashboard;

use App\Models\BirthdayRewardIssuance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashTransaction;
use App\Services\Marketing\CandleCashService;
use App\Support\Marketing\CandleCashMeasurement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class ShopifyEmbeddedDashboardCandleCashValueProvider
{
    public function __construct(
        protected CandleCashService $candleCashService
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(CarbonImmutable $from, CarbonImmutable $to, ?int $tenantId): array
    {
        $redeemedRows = CandleCashRedemption::query()
            ->whereHas('profile', function (EloquentBuilder $query) use ($tenantId): void {
                if ($tenantId === null) {
                    $query->whereNull('marketing_profiles.tenant_id');

                    return;
                }

                $query->where('marketing_profiles.tenant_id', $tenantId);
            })
            ->where('status', 'redeemed')
            ->whereBetween('redeemed_at', [$from, $to])
            ->get(['id', 'candle_cash_spent', 'redeemed_at', 'external_order_source', 'external_order_id']);

        $redeemedCandleCash = CandleCashMeasurement::normalizeStoredAmount($redeemedRows->sum('candle_cash_spent'));
        $redeemedAmount = round($this->candleCashService->amountFromPoints($redeemedCandleCash), 2);

        $giftRows = CandleCashTransaction::query()
            ->whereHas('profile', function (EloquentBuilder $query) use ($tenantId): void {
                if ($tenantId === null) {
                    $query->whereNull('marketing_profiles.tenant_id');

                    return;
                }

                $query->where('marketing_profiles.tenant_id', $tenantId);
            })
            ->where('type', 'gift')
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'candle_cash_delta', 'created_at']);

        $giftCandleCash = CandleCashMeasurement::normalizeStoredAmount($giftRows->sum('candle_cash_delta'));
        $giftAmount = round($this->candleCashService->amountFromPoints($giftCandleCash), 2);

        $birthdayRows = BirthdayRewardIssuance::query()
            ->whereHas('marketingProfile', function (EloquentBuilder $query) use ($tenantId): void {
                if ($tenantId === null) {
                    $query->whereNull('marketing_profiles.tenant_id');

                    return;
                }

                $query->where('marketing_profiles.tenant_id', $tenantId);
            })
            ->whereBetween('issued_at', [$from, $to])
            ->get(['id', 'reward_type', 'reward_value', 'candle_cash_awarded', 'issued_at']);

        $birthdayCost = round($birthdayRows->sum(function (BirthdayRewardIssuance $issuance): float {
            if ($issuance->reward_type === 'candle_cash') {
                return $this->candleCashService->amountFromPoints($issuance->candle_cash_awarded ?? 0);
            }

            return (float) ($issuance->reward_value ?? 0);
        }), 2);

        $realizedRewardCost = $redeemedAmount;

        return [
            'provider' => [
                'key' => 'discount_redemptions',
                'label' => 'Discount-based rewards',
                'supportedModels' => [
                    'discount_based',
                    'gift_card_style',
                    'store_credit_style',
                ],
                'activeModel' => 'discount_based',
            ],
            'used' => [
                'amount' => $redeemedAmount,
                'count' => $redeemedRows->count(),
            ],
            'redeemedAmount' => $redeemedAmount,
            'issued' => [
                'giftAmount' => $giftAmount,
                'giftCount' => $giftRows->count(),
                'birthdayCost' => $birthdayCost,
            ],
            'issuedBirthdayValue' => $birthdayCost,
            'realizedRewardCost' => $realizedRewardCost,
            'rewardCostAmount' => round($redeemedAmount + $birthdayCost, 2),
        ];
    }
}
