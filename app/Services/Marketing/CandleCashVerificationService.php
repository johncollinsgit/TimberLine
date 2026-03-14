<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;

class CandleCashVerificationService
{
    public function __construct(
        protected CandleCashTaskService $taskService
    ) {
    }

    public function awardGoogleReview(MarketingProfile $profile, string $reviewId, array $metadata = []): array
    {
        $reviewId = trim($reviewId);

        return $this->taskService->awardSystemTask($profile, 'google-review', [
            'source_type' => 'google_business_review',
            'source_id' => 'google-review:' . $reviewId,
            'source_event_key' => 'google-review:' . $reviewId,
            'metadata' => array_merge([
                'review_id' => $reviewId,
            ], $metadata),
        ]);
    }

    public function awardProductReview(MarketingProfile $profile, string $reviewId, array $metadata = []): array
    {
        $reviewId = trim($reviewId);

        return $this->taskService->awardSystemTask($profile, 'product-review', [
            'source_type' => 'product_review_platform_event',
            'source_id' => 'product-review:' . $reviewId,
            'source_event_key' => 'product-review:' . $reviewId,
            'metadata' => array_merge([
                'review_id' => $reviewId,
            ], $metadata),
        ]);
    }

    public function recordCandleClubVote(MarketingProfile $profile, string $campaignKey, array $metadata = []): array
    {
        $campaignKey = trim($campaignKey);

        return $this->taskService->submitCustomerTask($profile, 'candle-club-vote', [], [
            'source_type' => 'onsite_action',
            'source_id' => 'candle-club-vote:campaign:' . $campaignKey,
            'source_event_key' => 'candle-club-vote:profile:' . $profile->id . ':campaign:' . $campaignKey,
            'request_key' => 'candle-club-vote:' . $profile->id . ':' . $campaignKey,
            'metadata' => array_merge([
                'campaign_key' => $campaignKey,
            ], $metadata),
        ]);
    }
}
