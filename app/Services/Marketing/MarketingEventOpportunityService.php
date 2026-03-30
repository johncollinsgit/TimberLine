<?php

namespace App\Services\Marketing;

use App\Models\CandleCashBalance;
use App\Models\CandleCashRedemption;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\MarketingRecommendation;

class MarketingEventOpportunityService
{
    public function __construct(
        protected MarketingProfileAnalyticsService $analyticsService
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array{created:int,potential:int}
     */
    public function generate(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $created = 0;
        $potential = 0;

        MarketingProfile::query()
            ->whereJsonContains('source_channels', 'event')
            ->orderBy('id')
            ->chunkById(120, function ($profiles) use (&$created, &$potential, $dryRun): void {
                foreach ($profiles as $profile) {
                    $metrics = $this->analyticsService->metricsForProfile($profile);
                    $eventNames = collect((array) ($metrics['purchased_event_names'] ?? []))
                        ->map(fn ($value) => strtolower(trim((string) $value)))
                        ->filter()
                        ->values();

                    if ($eventNames->isEmpty()) {
                        continue;
                    }

                    if ($eventNames->contains(fn (string $name): bool => str_contains($name, 'florida strawberry festival'))
                        && ! (bool) ($metrics['has_shopify_link'] ?? false)) {
                        $result = $this->createProfileRecommendation(
                            profile: $profile,
                            payload: [
                                'type' => 'send_suggestion',
                                'title' => 'Florida Strawberry Festival buyer has not reordered online',
                                'summary' => 'This event buyer has no Shopify reorder signal and is a strong candidate for online reactivation.',
                                'details_json' => [
                                    'event_context' => 'Florida Strawberry Festival',
                                    'suggested_action' => 'Add to online event-buyer reactivation campaign',
                                ],
                                'confidence' => 0.81,
                            ],
                            dryRun: $dryRun
                        );
                        $created += $result['created'];
                        $potential += $result['potential'];
                    }

                    if ($eventNames->contains(fn (string $name): bool => str_contains($name, 'flowertown'))
                        && (bool) ($metrics['has_sms_consent'] ?? false)
                        && ! $this->hasEventFollowupTouch($profile)) {
                        $result = $this->createProfileRecommendation(
                            profile: $profile,
                            payload: [
                                'type' => 'send_suggestion',
                                'title' => 'Flowertown buyer has SMS consent but no follow-up sent',
                                'summary' => 'Profile has Flowertown event activity and SMS consent but no event follow-up campaign touch.',
                                'details_json' => [
                                    'event_context' => 'Flowertown',
                                    'suggested_channel' => 'sms',
                                    'suggested_action' => 'Queue event follow-up SMS suggestion',
                                ],
                                'confidence' => 0.84,
                            ],
                            dryRun: $dryRun
                        );
                        $created += $result['created'];
                        $potential += $result['potential'];
                    }

                    $balance = (int) (CandleCashBalance::query()
                        ->where('marketing_profile_id', $profile->id)
                        ->value('balance') ?? 0);
                    $hasRedemption = CandleCashRedemption::query()
                        ->where('marketing_profile_id', $profile->id)
                        ->exists();

                    if ($balance > 0 && ! $hasRedemption) {
                        $result = $this->createProfileRecommendation(
                            profile: $profile,
                            payload: [
                                'type' => 'reward_opportunity',
                                'title' => 'Event buyer has Rewards balance with no redemption',
                                'summary' => 'Profile has event purchase activity and unredeemed Rewards balance.',
                                'details_json' => [
                                    'current_balance' => $balance,
                                    'suggested_action' => 'Send reward reminder message',
                                ],
                                'confidence' => 0.74,
                            ],
                            dryRun: $dryRun
                        );
                        $created += $result['created'];
                        $potential += $result['potential'];
                    }

                    if ((int) ($metrics['event_attribution_count'] ?? 0) >= 2) {
                        $result = $this->createProfileRecommendation(
                            profile: $profile,
                            payload: [
                                'type' => 'segment_opportunity',
                                'title' => 'Repeat event buyer is a VIP list candidate',
                                'summary' => 'Profile has repeated event purchase attribution and should be considered for a VIP/customer list.',
                                'details_json' => [
                                    'event_attribution_count' => (int) ($metrics['event_attribution_count'] ?? 0),
                                    'suggested_action' => 'Add to VIP event-buyer group',
                                ],
                                'confidence' => 0.71,
                            ],
                            dryRun: $dryRun
                        );
                        $created += $result['created'];
                        $potential += $result['potential'];
                    }
                }
            });

        return [
            'created' => $created,
            'potential' => $potential,
        ];
    }

    protected function hasEventFollowupTouch(MarketingProfile $profile): bool
    {
        return MarketingMessageDelivery::query()
            ->where('marketing_profile_id', $profile->id)
            ->whereHas('campaign', fn ($query) => $query->where('objective', 'event_followup'))
            ->exists();
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{created:int,potential:int}
     */
    protected function createProfileRecommendation(MarketingProfile $profile, array $payload, bool $dryRun): array
    {
        $duplicate = MarketingRecommendation::query()
            ->where('type', (string) $payload['type'])
            ->where('marketing_profile_id', $profile->id)
            ->where('title', (string) $payload['title'])
            ->where('status', 'pending')
            ->exists();

        if ($duplicate) {
            return ['created' => 0, 'potential' => 0];
        }

        if ($dryRun) {
            return ['created' => 0, 'potential' => 1];
        }

        MarketingRecommendation::query()->create([
            'type' => (string) $payload['type'],
            'marketing_profile_id' => $profile->id,
            'title' => (string) $payload['title'],
            'summary' => (string) $payload['summary'],
            'details_json' => is_array($payload['details_json'] ?? null) ? $payload['details_json'] : null,
            'status' => 'pending',
            'confidence' => $payload['confidence'] ?? null,
            'created_by_system' => true,
        ]);

        return ['created' => 1, 'potential' => 1];
    }
}

