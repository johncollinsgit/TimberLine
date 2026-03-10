<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingProfile;

class MarketingCampaignAudienceBuilder
{
    public function __construct(
        protected MarketingSegmentEvaluator $segmentEvaluator,
        protected MarketingRecommendationEngine $recommendationEngine
    ) {
    }

    /**
     * @return array{
     *  processed:int,
     *  queued_for_approval:int,
     *  skipped:int,
     *  approved:int,
     *  updated:int
     * }
     */
    public function prepareRecipients(MarketingCampaign $campaign, ?int $limit = null): array
    {
        $summary = [
            'processed' => 0,
            'queued_for_approval' => 0,
            'skipped' => 0,
            'approved' => 0,
            'updated' => 0,
        ];

        if (! $campaign->segment) {
            return $summary;
        }

        $profilesQuery = MarketingProfile::query()->orderBy('id');
        if ($limit !== null) {
            $profilesQuery->limit(max(1, $limit));
        }

        $defaultVariant = $this->defaultVariantForCampaign($campaign);

        foreach ($profilesQuery->get() as $profile) {
            $evaluation = $this->segmentEvaluator->evaluateProfile($campaign->segment, $profile);
            if (! $evaluation['matched']) {
                continue;
            }

            $summary['processed']++;

            [$status, $reasons] = $this->eligibilityForChannel($profile, $campaign->channel);
            $existing = MarketingCampaignRecipient::query()
                ->where('campaign_id', $campaign->id)
                ->where('marketing_profile_id', $profile->id)
                ->first();

            if ($existing && in_array($existing->status, ['approved', 'rejected'], true)) {
                $status = $existing->status;
            }

            $recommendationSnapshot = [
                'score' => $profile->marketing_score,
                'segment_reasons' => $evaluation['reasons'],
            ];

            $recipient = MarketingCampaignRecipient::query()->updateOrCreate(
                [
                    'campaign_id' => $campaign->id,
                    'marketing_profile_id' => $profile->id,
                ],
                [
                    'segment_snapshot' => [
                        'segment_id' => $campaign->segment_id,
                        'segment_name' => $campaign->segment?->name,
                        'matched_at' => now()->toIso8601String(),
                        'reasons' => $evaluation['reasons'],
                    ],
                    'recommendation_snapshot' => $recommendationSnapshot,
                    'variant_id' => $defaultVariant?->id,
                    'channel' => $campaign->channel,
                    'status' => $status,
                    'reason_codes' => $reasons,
                    'last_status_note' => null,
                ]
            );

            if ($existing) {
                $summary['updated']++;
            }

            if ($recipient->status === 'queued_for_approval') {
                $summary['queued_for_approval']++;
            } elseif ($recipient->status === 'skipped') {
                $summary['skipped']++;
            } elseif ($recipient->status === 'approved') {
                $summary['approved']++;
            }

            if ($recipient->status === 'queued_for_approval') {
                $this->recommendationEngine->generateSendSuggestionForProfile($profile, $campaign);
            }
        }

        return $summary;
    }

    /**
     * @return array{0:string,1:array<int,string>}
     */
    protected function eligibilityForChannel(MarketingProfile $profile, string $channel): array
    {
        $channel = strtolower(trim($channel));
        $reasons = [];
        $eligible = true;

        if ($channel === 'sms') {
            if (! $profile->accepts_sms_marketing) {
                $eligible = false;
                $reasons[] = 'sms_not_consented';
            }
            if (! $profile->normalized_phone) {
                $eligible = false;
                $reasons[] = 'missing_phone';
            }
        } elseif ($channel === 'email') {
            if (! $profile->accepts_email_marketing) {
                $eligible = false;
                $reasons[] = 'email_not_consented';
            }
            if (! $profile->normalized_email) {
                $eligible = false;
                $reasons[] = 'missing_email';
            }
        }

        return [$eligible ? 'queued_for_approval' : 'skipped', $reasons];
    }

    protected function defaultVariantForCampaign(MarketingCampaign $campaign): ?\App\Models\MarketingCampaignVariant
    {
        return $campaign->variants()
            ->whereIn('status', ['active', 'draft'])
            ->orderByDesc('is_control')
            ->orderByDesc('weight')
            ->orderBy('id')
            ->first();
    }
}
