<?php

namespace App\Services\Marketing;

use App\Models\CandleCashBalance;
use App\Models\CandleCashReward;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingGroup;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\MarketingRecommendation;

class MarketingSegmentOpportunityService
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

        $floridaCount = $this->floridaProfileCount();
        if ($floridaCount >= 6) {
            $result = $this->createRecommendation([
                'type' => 'segment_opportunity',
                'title' => 'Create a Florida customers segment',
                'summary' => 'Profiles with Florida addresses have enough volume for location-specific launches and event reactivation.',
                'details_json' => [
                    'candidate_segment' => 'Florida Customers',
                    'estimated_profiles' => $floridaCount,
                ],
                'confidence' => 0.72,
            ], $dryRun);
            $created += $result['created'];
            $potential += $result['potential'];
        }

        $flowertownCount = $this->flowertownReactivationCount();
        if ($flowertownCount >= 4) {
            $result = $this->createRecommendation([
                'type' => 'segment_opportunity',
                'title' => 'Create a Flowertown buyers reactivation segment',
                'summary' => 'Flowertown event buyers with stale recency are large enough for a targeted follow-up segment.',
                'details_json' => [
                    'candidate_segment' => 'Flowertown Reactivation',
                    'estimated_profiles' => $flowertownCount,
                ],
                'confidence' => 0.76,
            ], $dryRun);
            $created += $result['created'];
            $potential += $result['potential'];
        }

        $directSends = (int) MarketingMessageDelivery::query()->whereNull('campaign_id')->count()
            + (int) MarketingEmailDelivery::query()->whereNull('marketing_campaign_recipient_id')->count();
        $internalGroups = (int) MarketingGroup::query()->where('is_internal', true)->count();
        if ($directSends >= 8 && $internalGroups <= 1) {
            $result = $this->createRecommendation([
                'type' => 'segment_opportunity',
                'title' => 'Create an internal updates group',
                'summary' => 'Repeated direct internal sends suggest creating a dedicated internal coordination group.',
                'details_json' => [
                    'direct_send_events' => $directSends,
                    'existing_internal_groups' => $internalGroups,
                    'candidate_group' => 'Internal Ops Updates',
                ],
                'confidence' => 0.61,
            ], $dryRun);
            $created += $result['created'];
            $potential += $result['potential'];
        }

        $thresholdCount = $this->nearRewardThresholdCount();
        if ($thresholdCount >= 5) {
            $result = $this->createRecommendation([
                'type' => 'segment_opportunity',
                'title' => 'Create a reward-threshold segment',
                'summary' => 'Customers near reward redemption threshold can be targeted with reminder campaigns.',
                'details_json' => [
                    'candidate_segment' => 'Near Reward Threshold',
                    'estimated_profiles' => $thresholdCount,
                    'distance_points' => 100,
                ],
                'confidence' => 0.70,
            ], $dryRun);
            $created += $result['created'];
            $potential += $result['potential'];
        }

        return [
            'created' => $created,
            'potential' => $potential,
        ];
    }

    protected function floridaProfileCount(): int
    {
        return MarketingProfile::query()
            ->get(['id', 'state'])
            ->filter(function (MarketingProfile $profile): bool {
                $state = strtolower(trim((string) $profile->state));

                return in_array($state, ['fl', 'florida'], true);
            })
            ->count();
    }

    protected function flowertownReactivationCount(): int
    {
        $count = 0;
        MarketingProfile::query()
            ->whereJsonContains('source_channels', 'event')
            ->orderBy('id')
            ->chunkById(150, function ($profiles) use (&$count): void {
                foreach ($profiles as $profile) {
                    $metrics = $this->analyticsService->metricsForProfile($profile);
                    $eventNames = collect((array) ($metrics['purchased_event_names'] ?? []))
                        ->map(fn ($value) => strtolower(trim((string) $value)))
                        ->filter()
                        ->values();

                    if ($eventNames->contains(fn (string $name): bool => str_contains($name, 'flowertown'))
                        && (int) ($metrics['days_since_last_order'] ?? 9999) >= 45) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    protected function nearRewardThresholdCount(): int
    {
        $minimumCost = (int) (CandleCashReward::query()
            ->where('is_active', true)
            ->min('points_cost') ?? 0);
        if ($minimumCost <= 0) {
            return 0;
        }

        $lower = max(0, $minimumCost - 100);
        $upper = max(0, $minimumCost - 1);

        return (int) CandleCashBalance::query()
            ->whereBetween('balance', [$lower, $upper])
            ->count();
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{created:int,potential:int}
     */
    protected function createRecommendation(array $payload, bool $dryRun): array
    {
        $duplicate = MarketingRecommendation::query()
            ->where('type', (string) $payload['type'])
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

