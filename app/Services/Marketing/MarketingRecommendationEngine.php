<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingProfile;
use App\Models\MarketingRecommendation;
use App\Models\MarketingSegment;

class MarketingRecommendationEngine
{
    public function __construct(
        protected MarketingProfileAnalyticsService $analyticsService,
        protected MarketingProfileScoreService $scoreService
    ) {
    }

    /**
     * @return array{created:int}
     */
    public function generateForCampaign(MarketingCampaign $campaign): array
    {
        $created = 0;

        $activeVariants = $campaign->variants()->where('status', 'active')->count();
        if ($activeVariants < 2) {
            $this->createRecommendation([
                'type' => 'copy_improvement',
                'campaign_id' => $campaign->id,
                'title' => 'Add a second active variant',
                'summary' => 'Campaign has fewer than two active variants; add another variant for A/B comparison.',
                'details_json' => [
                    'active_variants' => $activeVariants,
                    'suggestion' => 'Create short and long copy variants to compare response.',
                ],
                'confidence' => 0.85,
            ]);
            $created++;
        }

        if (empty($campaign->send_window_json)) {
            $this->createRecommendation([
                'type' => 'timing_suggestion',
                'campaign_id' => $campaign->id,
                'title' => 'Set campaign send window',
                'summary' => 'Campaign has no send window configured; set one before send execution is enabled.',
                'details_json' => [
                    'objective' => $campaign->objective,
                    'suggested_window' => ['start' => '13:00', 'end' => '17:00'],
                ],
                'confidence' => 0.74,
            ]);
            $created++;
        }

        if ($campaign->objective === 'event_followup' && !$this->campaignMentionsVariable($campaign, 'event_name')) {
            $this->createRecommendation([
                'type' => 'copy_improvement',
                'campaign_id' => $campaign->id,
                'title' => 'Include event context variable',
                'summary' => 'Event follow-up campaign does not reference {{event_name}} in active copy.',
                'details_json' => [
                    'required_variable' => 'event_name',
                ],
                'confidence' => 0.80,
            ]);
            $created++;
        }

        if (! $campaign->segment_id) {
            $eventBuyerCount = MarketingProfile::query()
                ->whereJsonContains('source_channels', 'event')
                ->count();
            if ($eventBuyerCount >= 10) {
                $this->createRecommendation([
                    'type' => 'segment_opportunity',
                    'campaign_id' => $campaign->id,
                    'title' => 'Attach an event-buyer segment',
                    'summary' => 'There are enough event-buyer profiles to support a dedicated event follow-up segment.',
                    'details_json' => [
                        'candidate_segment' => 'Event Buyers',
                        'estimated_profiles' => $eventBuyerCount,
                    ],
                    'confidence' => 0.70,
                ]);
                $created++;
            }
        }

        return ['created' => $created];
    }

    /**
     * @return array{created:int}
     */
    public function generateGlobal(): array
    {
        $created = 0;
        $squareOnlyBuyers = MarketingProfile::query()
            ->whereJsonContains('source_channels', 'square')
            ->whereJsonDoesntContain('source_channels', 'shopify')
            ->count();

        if ($squareOnlyBuyers >= 10) {
            $this->createRecommendation([
                'type' => 'segment_opportunity',
                'title' => 'Create a Square-only winback segment',
                'summary' => 'Profiles with only Square channel signals can support a dedicated online winback segment.',
                'details_json' => [
                    'estimated_profiles' => $squareOnlyBuyers,
                    'segment_name' => 'Square-only Buyers',
                ],
                'confidence' => 0.66,
            ]);
            $created++;
        }

        return ['created' => $created];
    }

    /**
     * @return array{created:int,recommendation_id:?int}
     */
    public function generateSendSuggestionForProfile(MarketingProfile $profile, ?MarketingCampaign $campaign = null): array
    {
        $scoreResult = $this->scoreService->refreshForProfile($profile);
        $metrics = $this->analyticsService->metricsForProfile($profile);

        $days = (int) ($metrics['days_since_last_order'] ?? 999);
        $isLapsed = $days >= 60;
        $isEligible = (bool) ($metrics['has_sms_consent'] ?? false) && !empty($profile->normalized_phone);

        if (!($isLapsed && $isEligible)) {
            return ['created' => 0, 'recommendation_id' => null];
        }

        $recommendation = $this->createRecommendation([
            'type' => 'send_suggestion',
            'campaign_id' => $campaign?->id,
            'marketing_profile_id' => $profile->id,
            'title' => 'Lapsed customer SMS follow-up',
            'summary' => 'Profile is lapsed, consented for SMS, and has sufficient likelihood score for a follow-up message.',
            'details_json' => [
                'days_since_last_order' => $metrics['days_since_last_order'],
                'likelihood_score' => $scoreResult['score'],
                'suggested_channel' => 'sms',
                'suggested_message' => 'Hi {{first_name}}, we miss you. Ready for your next pour?',
            ],
            'confidence' => 0.81,
        ]);

        return ['created' => 1, 'recommendation_id' => $recommendation->id];
    }

    protected function campaignMentionsVariable(MarketingCampaign $campaign, string $variable): bool
    {
        $needle = '{{' . strtolower(trim($variable)) . '}}';
        $variants = $campaign->variants()->where('status', 'active')->get(['message_text']);
        foreach ($variants as $variant) {
            if (str_contains(strtolower((string) $variant->message_text), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $data
     */
    protected function createRecommendation(array $data): MarketingRecommendation
    {
        return MarketingRecommendation::query()->create([
            'type' => (string) $data['type'],
            'campaign_id' => $data['campaign_id'] ?? null,
            'marketing_profile_id' => $data['marketing_profile_id'] ?? null,
            'related_variant_id' => $data['related_variant_id'] ?? null,
            'title' => (string) $data['title'],
            'summary' => (string) $data['summary'],
            'details_json' => is_array($data['details_json'] ?? null) ? $data['details_json'] : null,
            'status' => 'pending',
            'confidence' => $data['confidence'] ?? null,
            'created_by_system' => true,
        ]);
    }
}
