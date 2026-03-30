<?php

namespace App\Services\Marketing;

use App\Models\CandleCashBalance;
use App\Models\MarketingCampaign;
use App\Models\MarketingProfile;
use App\Models\MarketingRecommendation;
use App\Models\MarketingRecommendationRun;

class MarketingRecommendationEngine
{
    public function __construct(
        protected MarketingProfileAnalyticsService $analyticsService,
        protected MarketingProfileScoreService $scoreService,
        protected MarketingPerformanceAnalyticsService $performanceAnalyticsService,
        protected MarketingTimingRecommendationService $timingRecommendationService,
        protected MarketingSegmentOpportunityService $segmentOpportunityService,
        protected MarketingEventOpportunityService $eventOpportunityService,
        protected MarketingTenantOwnershipService $ownershipService
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array{created:int,potential:int,run_id:?int}
     */
    public function generateForCampaign(MarketingCampaign $campaign, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $tenantId = isset($options['tenant_id']) && is_numeric($options['tenant_id'])
            ? (int) $options['tenant_id']
            : null;
        if ($tenantId !== null && ! $this->ownershipService->campaignOwnedByTenant((int) $campaign->id, $tenantId)) {
            throw new \RuntimeException('Campaign recommendation generation requires tenant-owned campaign context.');
        }
        $campaignTenantId = $tenantId ?? $this->ownershipService->campaignOwnerTenantId((int) $campaign->id);
        $run = $this->startRun('campaign:' . $campaign->id, $dryRun);

        $created = 0;
        $potential = 0;

        $activeVariants = $campaign->variants()->where('status', 'active')->count();
        if ($activeVariants < 2) {
            $result = $this->createRecommendation([
                'type' => 'copy_improvement',
                'campaign_id' => $campaign->id,
                'title' => 'Add a second active variant',
                'summary' => 'Campaign has fewer than two active variants; add another variant for A/B comparison.',
                'details_json' => [
                    'active_variants' => $activeVariants,
                    'suggestion' => 'Create short and long copy variants to compare response.',
                ],
                'confidence' => 0.85,
            ], $dryRun);
            $created += $result['created'];
            $potential += $result['potential'];
        }

        if (empty($campaign->send_window_json)) {
            $result = $this->createRecommendation([
                'type' => 'timing_suggestion',
                'campaign_id' => $campaign->id,
                'title' => 'Set campaign send window',
                'summary' => 'Campaign has no send window configured; set one before send execution is enabled.',
                'details_json' => [
                    'objective' => $campaign->objective,
                    'suggested_window' => ['start' => '13:00', 'end' => '17:00'],
                ],
                'confidence' => 0.74,
            ], $dryRun);
            $created += $result['created'];
            $potential += $result['potential'];
        }

        if ($campaign->objective === 'event_followup' && ! $this->campaignMentionsVariable($campaign, 'event_name')) {
            $result = $this->createRecommendation([
                'type' => 'copy_improvement',
                'campaign_id' => $campaign->id,
                'title' => 'Include event context variable',
                'summary' => 'Event follow-up campaign does not reference {{event_name}} in active copy.',
                'details_json' => [
                    'required_variable' => 'event_name',
                ],
                'confidence' => 0.80,
            ], $dryRun);
            $created += $result['created'];
            $potential += $result['potential'];
        }

        if (! $campaign->segment_id) {
            $eventBuyerCount = MarketingProfile::query()
                ->when($campaignTenantId !== null, fn ($query) => $query->forTenantId($campaignTenantId))
                ->whereJsonContains('source_channels', 'event')
                ->count();
            if ($eventBuyerCount >= 10) {
                $result = $this->createRecommendation([
                    'type' => 'segment_opportunity',
                    'campaign_id' => $campaign->id,
                    'title' => 'Attach an event-buyer segment',
                    'summary' => 'There are enough event-buyer profiles to support a dedicated event follow-up segment.',
                    'details_json' => [
                        'candidate_segment' => 'Event Buyers',
                        'estimated_profiles' => $eventBuyerCount,
                    ],
                    'confidence' => 0.70,
                ], $dryRun);
                $created += $result['created'];
                $potential += $result['potential'];
            }
        }

        $performance = $this->performanceAnalyticsService->campaignSummary($campaign, 120);
        $variantRows = collect((array) ($performance['variant_rows'] ?? []))
            ->filter(fn (array $row): bool => ((int) ($row['sent_count'] ?? 0)) >= 3)
            ->sortByDesc('conversion_rate')
            ->values();
        if ($variantRows->count() >= 2) {
            $best = (array) $variantRows->first();
            $second = (array) $variantRows->get(1, []);
            $gap = (float) ($best['conversion_rate'] ?? 0) - (float) ($second['conversion_rate'] ?? 0);
            if ($gap >= 0.05) {
                $result = $this->createRecommendation([
                    'type' => 'copy_improvement',
                    'campaign_id' => $campaign->id,
                    'related_variant_id' => $best['variant_id'] ?? null,
                    'title' => 'Favor stronger-performing variant copy',
                    'summary' => 'One active variant is outperforming other copy in conversion rate.',
                    'details_json' => [
                        'best_variant_id' => $best['variant_id'] ?? null,
                        'best_conversion_rate' => $best['conversion_rate'] ?? 0,
                        'comparison_variant_id' => $second['variant_id'] ?? null,
                        'comparison_conversion_rate' => $second['conversion_rate'] ?? 0,
                        'rate_gap' => round($gap, 4),
                    ],
                    'confidence' => 0.82,
                ], $dryRun);
                $created += $result['created'];
                $potential += $result['potential'];
            }
        }

        $averageMessageLength = (float) $campaign->variants()
            ->where('status', 'active')
            ->get(['message_text'])
            ->avg(fn ($variant) => strlen((string) $variant->message_text));
        $deliveryRate = (float) (($performance['delivered'] ?? 0) / max(1, (int) ($performance['sent'] ?? 0)));
        if ($deliveryRate < 0.55 && $averageMessageLength > 145) {
            $result = $this->createRecommendation([
                'type' => 'copy_improvement',
                'campaign_id' => $campaign->id,
                'title' => 'Add a shorter copy variant',
                'summary' => 'Low delivery/engagement and long active copy suggest testing a shorter message.',
                'details_json' => [
                    'average_message_length' => (int) round($averageMessageLength),
                    'delivery_rate' => round($deliveryRate, 4),
                    'suggestion' => 'Create a concise version under 120 characters with event/incentive context.',
                ],
                'confidence' => 0.68,
            ], $dryRun);
            $created += $result['created'];
            $potential += $result['potential'];
        }

        $this->timingRecommendationService->generateInsights([
            'campaign_id' => (int) $campaign->id,
            'dry_run' => $dryRun,
        ]);
        $timingInsight = $this->timingRecommendationService->bestInsightForCampaign($campaign);
        if ($timingInsight && (float) ($timingInsight->confidence ?? 0) >= 0.40) {
            $result = $this->createRecommendation([
                'type' => 'timing_suggestion',
                'campaign_id' => $campaign->id,
                'title' => 'Use performance-backed send window',
                'summary' => 'Historical outcomes suggest a better send hour/daypart for this campaign context.',
                'details_json' => [
                    'recommended_hour' => (int) ($timingInsight->recommended_hour ?? 13),
                    'recommended_daypart' => (string) ($timingInsight->recommended_daypart ?? 'afternoon'),
                    'confidence' => (float) ($timingInsight->confidence ?? 0),
                    'reasons' => (array) ($timingInsight->reasons_json ?? []),
                ],
                'confidence' => $timingInsight->confidence,
            ], $dryRun);
            $created += $result['created'];
            $potential += $result['potential'];
        }

        if ($campaign->objective === 'consent_capture') {
            $profiles = MarketingProfile::query()
                ->when($campaignTenantId !== null, fn ($query) => $query->forTenantId($campaignTenantId))
                ->where('accepts_email_marketing', true)
                ->where('accepts_sms_marketing', false)
                ->orderByDesc('updated_at')
                ->limit(100)
                ->get();

            foreach ($profiles as $profile) {
                $result = $this->generateConsentCaptureSuggestionForProfile($profile, $campaign, [
                    'dry_run' => $dryRun,
                    'tenant_id' => $campaignTenantId,
                ]);
                $created += (int) ($result['created'] ?? 0);
                $potential += (int) ($result['potential'] ?? 0);
            }
        }

        $this->finishRun($run, [
            'created' => $created,
            'potential' => $potential,
            'campaign_id' => (int) $campaign->id,
        ], 'completed');

        return [
            'created' => $created,
            'potential' => $potential,
            'run_id' => $run?->id,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{created:int,potential:int,run_id:?int}
     */
    public function generateGlobal(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $tenantId = isset($options['tenant_id']) && is_numeric($options['tenant_id'])
            ? (int) $options['tenant_id']
            : null;
        $runType = $tenantId !== null ? 'tenant:' . $tenantId . ':global' : 'global';
        $run = $this->startRun($runType, $dryRun);

        $created = 0;
        $potential = 0;

        $squareOnlyBuyers = MarketingProfile::query()
            ->when($tenantId !== null, fn ($query) => $query->forTenantId($tenantId))
            ->whereJsonContains('source_channels', 'square')
            ->whereJsonDoesntContain('source_channels', 'shopify')
            ->count();

        if ($squareOnlyBuyers >= 10) {
            $result = $this->createRecommendation([
                'type' => 'segment_opportunity',
                'title' => 'Create a Square-only winback segment',
                'summary' => 'Profiles with only Square channel signals can support a dedicated online winback segment.',
                'details_json' => [
                    'estimated_profiles' => $squareOnlyBuyers,
                    'segment_name' => 'Square-only Buyers',
                ],
                'confidence' => 0.66,
            ], $dryRun);
            $created += $result['created'];
            $potential += $result['potential'];
        }

        $consentCaptureProfiles = MarketingProfile::query()
            ->when($tenantId !== null, fn ($query) => $query->forTenantId($tenantId))
            ->where('accepts_email_marketing', true)
            ->where('accepts_sms_marketing', false)
            ->whereNotNull('normalized_email')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        foreach ($consentCaptureProfiles as $profile) {
            $result = $this->generateConsentCaptureSuggestionForProfile($profile, null, [
                'dry_run' => $dryRun,
                'tenant_id' => $tenantId,
            ]);
            $created += (int) ($result['created'] ?? 0);
            $potential += (int) ($result['potential'] ?? 0);
        }

        $channelSuggestionCount = MarketingProfile::query()
            ->when($tenantId !== null, fn ($query) => $query->forTenantId($tenantId))
            ->where('accepts_email_marketing', true)
            ->where('accepts_sms_marketing', false)
            ->whereNotNull('normalized_email')
            ->count();
        if ($channelSuggestionCount >= 10) {
            $result = $this->createRecommendation([
                'type' => 'channel_suggestion',
                'title' => 'Expand email-first reactivation for non-SMS profiles',
                'summary' => 'A sizable set of lapsed profiles can be reached through email while SMS consent is still missing.',
                'details_json' => [
                    'eligible_profiles' => $channelSuggestionCount,
                    'suggested_channel' => 'email',
                    'segment_hint' => 'Email Consented / No SMS Consent',
                ],
                'confidence' => 0.69,
            ], $dryRun);
            $created += $result['created'];
            $potential += $result['potential'];
        }

        $nearThresholdCount = (int) CandleCashBalance::query()
            ->when($tenantId !== null, function ($query) use ($tenantId): void {
                $query->whereHas('profile', fn ($profileQuery) => $profileQuery->forTenantId($tenantId));
            })
            ->where('balance', '>=', 50)
            ->count();
        if ($nearThresholdCount >= 8) {
            $result = $this->createRecommendation([
                'type' => 'reward_opportunity',
                'title' => 'Reward-balance nudges can improve repeat purchases',
                'summary' => 'Customers with existing Rewards balance are strong candidates for reward reminder campaigns.',
                'details_json' => [
                    'profiles_with_balance' => $nearThresholdCount,
                    'suggestion' => 'Launch a balance reminder campaign for event and lapsed buyers.',
                ],
                'confidence' => 0.67,
            ], $dryRun);
            $created += $result['created'];
            $potential += $result['potential'];
        }

        $timing = ['processed' => 0, 'created' => 0, 'updated' => 0];
        if ($tenantId === null || ! $this->ownershipService->strictModeEnabled()) {
            $timing = $this->timingRecommendationService->generateInsights(['dry_run' => $dryRun]);
        }
        if ((int) ($timing['processed'] ?? 0) > 0) {
            $result = $this->createRecommendation([
                'type' => 'timing_suggestion',
                'title' => 'Review new timing insight snapshots',
                'summary' => 'Fresh timing insights were generated from actual send/open/click/conversion outcomes.',
                'details_json' => [
                    'processed' => (int) ($timing['processed'] ?? 0),
                    'created' => (int) ($timing['created'] ?? 0),
                    'updated' => (int) ($timing['updated'] ?? 0),
                ],
                'confidence' => 0.62,
            ], $dryRun);
            $created += $result['created'];
            $potential += $result['potential'];
        }

        $segmentOpportunities = ['created' => 0, 'potential' => 0];
        $eventOpportunities = ['created' => 0, 'potential' => 0];
        if ($tenantId === null || ! $this->ownershipService->strictModeEnabled()) {
            $segmentOpportunities = $this->segmentOpportunityService->generate(['dry_run' => $dryRun]);
            $eventOpportunities = $this->eventOpportunityService->generate(['dry_run' => $dryRun]);
        }
        $created += (int) ($segmentOpportunities['created'] ?? 0) + (int) ($eventOpportunities['created'] ?? 0);
        $potential += (int) ($segmentOpportunities['potential'] ?? 0) + (int) ($eventOpportunities['potential'] ?? 0);

        $this->finishRun($run, [
            'created' => $created,
            'potential' => $potential,
            'timing' => $timing,
            'segment_opportunities' => $segmentOpportunities,
            'event_opportunities' => $eventOpportunities,
        ], 'completed');

        return [
            'created' => $created,
            'potential' => $potential,
            'run_id' => $run?->id,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{created:int,recommendation_id:?int}
     */
    public function generateSendSuggestionForProfile(
        MarketingProfile $profile,
        ?MarketingCampaign $campaign = null,
        array $options = []
    ): array {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $tenantId = isset($options['tenant_id']) && is_numeric($options['tenant_id'])
            ? (int) $options['tenant_id']
            : null;
        if ($tenantId !== null && (int) ($profile->tenant_id ?? 0) !== $tenantId) {
            return ['created' => 0, 'recommendation_id' => null];
        }
        $scoreResult = $this->scoreService->refreshForProfile($profile);
        $metrics = $this->analyticsService->metricsForProfile($profile);

        $days = (int) ($metrics['days_since_last_order'] ?? 999);
        $isLapsed = $days >= 60;
        $isEligible = (bool) ($metrics['has_sms_consent'] ?? false) && !empty($profile->normalized_phone);

        if (!($isLapsed && $isEligible)) {
            return ['created' => 0, 'recommendation_id' => null];
        }

        $result = $this->createRecommendation([
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
        ], $dryRun);

        return [
            'created' => (int) $result['created'],
            'recommendation_id' => $result['recommendation_id'],
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{created:int,recommendation_id:?int,potential?:int}
     */
    public function generateConsentCaptureSuggestionForProfile(
        MarketingProfile $profile,
        ?MarketingCampaign $campaign = null,
        array $options = []
    ): array {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $tenantId = isset($options['tenant_id']) && is_numeric($options['tenant_id'])
            ? (int) $options['tenant_id']
            : null;
        if ($tenantId !== null && (int) ($profile->tenant_id ?? 0) !== $tenantId) {
            return ['created' => 0, 'recommendation_id' => null];
        }
        if (! (bool) $profile->accepts_email_marketing || (bool) $profile->accepts_sms_marketing) {
            return ['created' => 0, 'recommendation_id' => null];
        }

        $metrics = $this->analyticsService->metricsForProfile($profile);
        $daysSinceOrder = (int) ($metrics['days_since_last_order'] ?? 9999);
        $totalOrders = (int) ($metrics['total_orders'] ?? 0);
        if ($totalOrders < 1 || $daysSinceOrder > 180) {
            return ['created' => 0, 'recommendation_id' => null];
        }

        if (! $profile->normalized_email && ! $profile->normalized_phone) {
            return ['created' => 0, 'recommendation_id' => null];
        }

        $existing = MarketingRecommendation::query()
            ->where('type', 'send_suggestion')
            ->where('marketing_profile_id', $profile->id)
            ->where('status', 'pending')
            ->where('summary', 'Consent-capture outreach: profile has email consent but no SMS consent.')
            ->first();

        if ($existing) {
            return ['created' => 0, 'recommendation_id' => (int) $existing->id];
        }

        $result = $this->createRecommendation([
            'type' => 'send_suggestion',
            'campaign_id' => $campaign?->id,
            'marketing_profile_id' => $profile->id,
            'title' => 'Invite profile to SMS consent flow',
            'summary' => 'Consent-capture outreach: profile has email consent but no SMS consent.',
            'details_json' => [
                'objective' => 'consent_capture',
                'days_since_last_order' => $metrics['days_since_last_order'],
                'total_orders' => $metrics['total_orders'],
                'suggested_channel' => $profile->normalized_email ? 'email' : 'sms',
                'suggested_message' => 'Want early access alerts and future rewards perks? Reply YES to opt into SMS updates.',
            ],
            'confidence' => 0.78,
        ], $dryRun);

        return [
            'created' => (int) $result['created'],
            'recommendation_id' => $result['recommendation_id'],
            'potential' => (int) $result['potential'],
        ];
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
     * @return array{created:int,potential:int,recommendation_id:?int}
     */
    protected function createRecommendation(array $data, bool $dryRun = false): array
    {
        $duplicate = MarketingRecommendation::query()
            ->where('type', (string) $data['type'])
            ->where('title', (string) $data['title'])
            ->where('status', 'pending')
            ->where('campaign_id', $data['campaign_id'] ?? null)
            ->where('marketing_profile_id', $data['marketing_profile_id'] ?? null)
            ->where('related_variant_id', $data['related_variant_id'] ?? null)
            ->first();

        if ($duplicate) {
            return [
                'created' => 0,
                'potential' => 0,
                'recommendation_id' => (int) $duplicate->id,
            ];
        }

        if ($dryRun) {
            return [
                'created' => 0,
                'potential' => 1,
                'recommendation_id' => null,
            ];
        }

        $recommendation = MarketingRecommendation::query()->create([
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

        return [
            'created' => 1,
            'potential' => 1,
            'recommendation_id' => (int) $recommendation->id,
        ];
    }

    protected function startRun(string $type, bool $dryRun): ?MarketingRecommendationRun
    {
        if ($dryRun) {
            return null;
        }

        return MarketingRecommendationRun::query()->create([
            'type' => $type,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * @param array<string,mixed> $summary
     */
    protected function finishRun(?MarketingRecommendationRun $run, array $summary, string $status): void
    {
        if (! $run) {
            return;
        }

        $run->forceFill([
            'status' => $status,
            'summary' => $summary,
            'finished_at' => now(),
        ])->save();
    }
}
