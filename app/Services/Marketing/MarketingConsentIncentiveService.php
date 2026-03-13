<?php

namespace App\Services\Marketing;

use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;

class MarketingConsentIncentiveService
{
    public function __construct(
        protected CandleCashService $candleCashService
    ) {
    }

    public function bonusPoints(): int
    {
        return max(0, (int) data_get(config('marketing.consent_bonus_points', []), 'sms', 0));
    }

    /**
     * @return array{awarded:bool,points:int,balance:?int}
     */
    public function awardSmsConsentBonusOnce(MarketingProfile $profile, string $sourceId, string $description): array
    {
        $points = $this->bonusPoints();
        if ($points <= 0) {
            return ['awarded' => false, 'points' => 0, 'balance' => null];
        }

        $alreadyAwarded = CandleCashTransaction::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source', 'consent')
            ->where('source_id', $sourceId)
            ->where('points', '>', 0)
            ->exists();

        if ($alreadyAwarded) {
            return ['awarded' => false, 'points' => 0, 'balance' => null];
        }

        $result = $this->candleCashService->addPoints(
            profile: $profile,
            points: $points,
            type: 'earn',
            source: 'consent',
            sourceId: $sourceId,
            description: $description
        );

        return [
            'awarded' => true,
            'points' => $points,
            'balance' => (int) ($result['balance'] ?? 0),
        ];
    }
}

