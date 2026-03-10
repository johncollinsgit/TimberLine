<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileScore;

class MarketingProfileScoreService
{
    public function __construct(
        protected MarketingProfileAnalyticsService $analyticsService
    ) {
    }

    /**
     * @return array{score:int,reasons:array<string,mixed>}
     */
    public function calculate(MarketingProfile $profile): array
    {
        $metrics = $this->analyticsService->metricsForProfile($profile);
        $components = [];

        $days = $metrics['days_since_last_order'];
        $components['recency'] = match (true) {
            $days === null => 2,
            $days <= 30 => 25,
            $days <= 60 => 18,
            $days <= 120 => 10,
            default => 4,
        };

        $orders = (int) ($metrics['total_orders'] ?? 0);
        $components['frequency'] = match (true) {
            $orders >= 10 => 20,
            $orders >= 5 => 14,
            $orders >= 2 => 8,
            default => 2,
        };

        $spent = (float) ($metrics['total_spent'] ?? 0);
        $components['spend'] = match (true) {
            $spent >= 500 => 15,
            $spent >= 200 => 10,
            $spent >= 50 => 5,
            default => 1,
        };

        $hasEmail = (bool) ($metrics['has_email_consent'] ?? false);
        $hasSms = (bool) ($metrics['has_sms_consent'] ?? false);
        $components['consent'] = match (true) {
            $hasEmail && $hasSms => 15,
            $hasEmail || $hasSms => 10,
            default => 0,
        };

        $components['source_diversity'] = min(10, ((int) ($metrics['source_diversity'] ?? 0)) * 3);
        $components['event_signal'] = (bool) ($metrics['purchased_at_event'] ?? false) ? 8 : 0;

        $engagementSignals = ((int) ($metrics['external_opens'] ?? 0)) + ((int) ($metrics['external_clicks'] ?? 0) * 2);
        $components['engagement'] = min(7, (int) floor($engagementSignals / 3));

        $rawScore = array_sum($components);
        $score = max(0, min(100, (int) round($rawScore)));

        return [
            'score' => $score,
            'reasons' => [
                'components' => $components,
                'metrics' => [
                    'days_since_last_order' => $metrics['days_since_last_order'],
                    'total_orders' => $orders,
                    'total_spent' => $spent,
                    'has_email_consent' => $hasEmail,
                    'has_sms_consent' => $hasSms,
                    'purchased_at_event' => (bool) ($metrics['purchased_at_event'] ?? false),
                    'source_diversity' => (int) ($metrics['source_diversity'] ?? 0),
                    'external_opens' => (int) ($metrics['external_opens'] ?? 0),
                    'external_clicks' => (int) ($metrics['external_clicks'] ?? 0),
                ],
            ],
        ];
    }

    /**
     * @return array{score:int,reasons:array<string,mixed>}
     */
    public function refreshForProfile(MarketingProfile $profile): array
    {
        $result = $this->calculate($profile);

        $profile->forceFill([
            'marketing_score' => $result['score'],
            'last_marketing_score_at' => now(),
        ])->save();

        $todayStart = now()->copy()->startOfDay();
        $todayEnd = now()->copy()->endOfDay();
        $existing = MarketingProfileScore::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('score_type', 'likelihood')
            ->whereBetween('calculated_at', [$todayStart, $todayEnd])
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            $existing->forceFill([
                'score' => $result['score'],
                'reasons_json' => $result['reasons'],
                'calculated_at' => now(),
            ])->save();
        } else {
            MarketingProfileScore::query()->create([
                'marketing_profile_id' => $profile->id,
                'score_type' => 'likelihood',
                'score' => $result['score'],
                'reasons_json' => $result['reasons'],
                'calculated_at' => now(),
            ]);
        }

        return $result;
    }

    /**
     * @return array{processed:int}
     */
    public function refreshAll(int $limit = 200): array
    {
        $processed = 0;
        $limit = max(1, $limit);

        foreach (MarketingProfile::query()->orderBy('id')->limit($limit)->get() as $profile) {
            $this->refreshForProfile($profile);
            $processed++;
        }

        return ['processed' => $processed];
    }

    public function latestScoreForProfile(MarketingProfile $profile): ?MarketingProfileScore
    {
        return $profile->scoreHistory()
            ->where('score_type', 'likelihood')
            ->orderByDesc('calculated_at')
            ->first();
    }
}
