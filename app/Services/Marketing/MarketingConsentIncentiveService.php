<?php

namespace App\Services\Marketing;

use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;

class MarketingConsentIncentiveService
{
    public function __construct(
        protected CandleCashService $candleCashService,
        protected CandleCashTaskService $taskService
    ) {
    }

    public function bonusCandleCash(): int
    {
        return max(
            0,
            (int) data_get(config('marketing.candle_cash_consent_bonus', []), 'sms', 0)
        );
    }

    /**
     * @return array{awarded:bool,candle_cash:int,balance:?int,state?:string}
     */
    public function awardSmsConsentBonusOnce(MarketingProfile $profile, string $sourceId, string $description): array
    {
        $task = $this->taskService->taskByHandle('sms-signup');
        if ($task && $task->enabled && ! $task->archived_at) {
            $result = $this->taskService->awardSystemTask($profile, $task, [
                'source_type' => 'subscription_event',
                'source_id' => $sourceId,
                'source_event_key' => 'sms-signup:' . $sourceId,
                'metadata' => [
                    'description' => $description,
                ],
            ]);

            $completion = $result['completion'] ?? null;
            $awarded = (bool) ($result['ok'] ?? false)
                && $completion
                && in_array((string) $completion->status, ['awarded', 'approved'], true);

            return [
                'awarded' => $awarded,
                'candle_cash' => $awarded ? (int) ($completion?->reward_candle_cash ?? 0) : 0,
                'balance' => $awarded ? $this->candleCashService->currentBalance($profile) : null,
                'state' => (string) ($result['state'] ?? ''),
            ];
        }

        $candleCash = $this->bonusCandleCash();
        if ($candleCash <= 0) {
            return ['awarded' => false, 'candle_cash' => 0, 'balance' => null];
        }

        $alreadyAwarded = CandleCashTransaction::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source', 'consent')
            ->where('source_id', $sourceId)
            ->where('candle_cash_delta', '>', 0)
            ->exists();

        if ($alreadyAwarded) {
            return ['awarded' => false, 'candle_cash' => 0, 'balance' => null];
        }

        $result = $this->candleCashService->addPoints(
            profile: $profile,
            points: $candleCash,
            type: 'earn',
            source: 'consent',
            sourceId: $sourceId,
            description: $description
        );

        return [
            'awarded' => true,
            'candle_cash' => $candleCash,
            'balance' => round((float) ($result['balance'] ?? 0), 3),
        ];
    }
}
