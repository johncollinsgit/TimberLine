<?php

namespace App\Services\Shopify\Dashboard;

use App\Models\BirthdayRewardIssuance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashTransaction;
use App\Services\Marketing\CandleCashService;
use Carbon\CarbonImmutable;

class ShopifyEmbeddedDashboardCandleCashValueProvider
{
    public function __construct(
        protected CandleCashService $candleCashService
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $redeemedRows = CandleCashRedemption::query()
            ->where('status', 'redeemed')
            ->whereBetween('redeemed_at', [$from, $to])
            ->get(['id', 'points_spent', 'redeemed_at', 'external_order_source', 'external_order_id']);

        $redeemedPoints = (int) $redeemedRows->sum('points_spent');
        $redeemedAmount = round($this->candleCashService->amountFromPoints($redeemedPoints), 2);

        $giftRows = CandleCashTransaction::query()
            ->where('type', 'gift')
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'points', 'created_at']);

        $giftPoints = (int) $giftRows->sum('points');
        $giftAmount = round($this->candleCashService->amountFromPoints($giftPoints), 2);

        $birthdayRows = BirthdayRewardIssuance::query()
            ->whereBetween('issued_at', [$from, $to])
            ->get(['id', 'reward_type', 'reward_value', 'points_awarded', 'issued_at']);

        $birthdayCost = round($birthdayRows->sum(function (BirthdayRewardIssuance $issuance): float {
            if ($issuance->reward_type === 'points') {
                return $this->candleCashService->amountFromPoints((int) ($issuance->points_awarded ?? 0));
            }

            return (float) ($issuance->reward_value ?? 0);
        }), 2);

        return [
            'provider' => [
                'key' => 'discount_redemptions',
                'label' => 'Discount-based Candle Cash',
                'supportedModels' => [
                    'discount_based',
                    'gift_card_style',
                    'store_credit_style',
                ],
                'activeModel' => 'discount_based',
            ],
            'used' => [
                'points' => $redeemedPoints,
                'amount' => $redeemedAmount,
                'count' => $redeemedRows->count(),
            ],
            'issued' => [
                'giftPoints' => $giftPoints,
                'giftAmount' => $giftAmount,
                'giftCount' => $giftRows->count(),
                'birthdayCost' => $birthdayCost,
            ],
            'rewardCostAmount' => round($redeemedAmount + $birthdayCost, 2),
        ];
    }
}
