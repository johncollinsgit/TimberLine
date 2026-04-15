<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingGroupMember;
use App\Models\MarketingProfile;
use Illuminate\Database\Eloquent\Builder;

class MarketingCampaignAudienceBuilder
{
    public function __construct(
        protected MarketingSegmentEvaluator $segmentEvaluator,
        protected MarketingRecommendationEngine $recommendationEngine,
        protected MarketingTenantOwnershipService $ownershipService,
        protected MarketingSmsEligibilityService $smsEligibilityService
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

        $tenantId = $this->ownershipService->campaignOwnerTenantId((int) $campaign->id);
        if ($this->ownershipService->strictModeEnabled() && $tenantId === null) {
            return $summary;
        }

        $campaign->loadMissing(['segment', 'groups:id,name']);

        $segmentMatches = $this->segmentMatches($campaign, $tenantId);
        $groupMatches = $this->groupMatches($campaign, $tenantId);
        $manualProfileIds = $this->manualProfileIds($campaign, $tenantId);

        $candidateIds = collect(array_keys($segmentMatches))
            ->merge(array_keys($groupMatches))
            ->merge($manualProfileIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->sort()
            ->values();

        if ($limit !== null) {
            $candidateIds = $candidateIds->take(max(1, $limit))->values();
        }

        if ($candidateIds->isEmpty()) {
            return $summary;
        }

        $profiles = MarketingProfile::query()
            ->whereIn('id', $candidateIds->all())
            ->when($tenantId !== null, fn (Builder $query) => $query->forTenantId($tenantId))
            ->get()
            ->keyBy('id');
        $smsEligibility = strtolower(trim((string) $campaign->channel)) === 'sms'
            ? $this->smsEligibilityService->evaluateProfiles($profiles->values(), $tenantId)
            : collect();

        $defaultVariant = $this->defaultVariantForCampaign($campaign);
        $manualLookup = collect($manualProfileIds)->map(fn ($id) => (int) $id)->flip();

        foreach ($candidateIds as $profileId) {
            $profile = $profiles->get((int) $profileId);
            if (! $profile) {
                continue;
            }

            $segmentReasons = (array) ($segmentMatches[(int) $profileId] ?? []);
            $groupData = (array) ($groupMatches[(int) $profileId] ?? ['group_ids' => [], 'group_names' => []]);
            $groupIds = collect((array) ($groupData['group_ids'] ?? []))
                ->map(fn ($value) => (int) $value)
                ->filter(fn (int $value) => $value > 0)
                ->unique()
                ->values()
                ->all();
            $groupNames = collect((array) ($groupData['group_names'] ?? []))
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->unique()
                ->values()
                ->all();
            $manual = $manualLookup->has((int) $profileId);

            $summary['processed']++;

            $smsEvaluation = strtolower(trim((string) $campaign->channel)) === 'sms'
                ? (array) ($smsEligibility->get((int) $profile->id) ?? [])
                : null;
            [$status, $reasons] = $this->eligibilityForChannel($profile, $campaign->channel, $smsEvaluation);
            $existing = MarketingCampaignRecipient::query()
                ->where('campaign_id', $campaign->id)
                ->where('marketing_profile_id', $profile->id)
                ->first();

            if ($existing && in_array($existing->status, ['approved', 'rejected'], true)) {
                $status = $existing->status;
            }

            $audienceSources = [];
            $audienceReasons = [];
            if ($segmentReasons !== []) {
                $audienceSources[] = 'segment';
                $audienceReasons[] = 'segment_match';
            }
            if ($groupIds !== []) {
                $audienceSources[] = 'group';
                foreach ($groupIds as $groupId) {
                    $audienceReasons[] = 'group_' . $groupId;
                }
            }
            if ($manual) {
                $audienceSources[] = 'manual_add';
                $audienceReasons[] = 'manual_add';
            }

            $recommendationSnapshot = [
                'score' => $profile->marketing_score,
                'segment_reasons' => $segmentReasons,
                'group_names' => $groupNames,
                'manual_add' => $manual,
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
                        'segment_reasons' => $segmentReasons,
                        'group_ids' => $groupIds,
                        'group_names' => $groupNames,
                        'manual_add' => $manual,
                        'audience_sources' => $audienceSources,
                    ],
                    'recommendation_snapshot' => $recommendationSnapshot,
                    'variant_id' => $defaultVariant?->id,
                    'channel' => $campaign->channel,
                    'status' => $status,
                    'reason_codes' => array_values(array_unique(array_filter([
                        ...$audienceReasons,
                        ...$reasons,
                    ]))),
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
                $this->recommendationEngine->generateSendSuggestionForProfile($profile, $campaign, [
                    'tenant_id' => $tenantId,
                ]);
            }
        }

        return $summary;
    }

    /**
     * @return array<int,array<int,string>>
     */
    protected function segmentMatches(MarketingCampaign $campaign, ?int $tenantId = null): array
    {
        if (! $campaign->segment) {
            return [];
        }

        $matches = [];
        MarketingProfile::query()
            ->when($tenantId !== null, fn (Builder $query) => $query->forTenantId($tenantId))
            ->orderBy('id')
            ->chunkById(300, function ($profiles) use ($campaign, &$matches): void {
                foreach ($profiles as $profile) {
                    $evaluation = $this->segmentEvaluator->evaluateProfile($campaign->segment, $profile);
                    if (! ($evaluation['matched'] ?? false)) {
                        continue;
                    }

                    $matches[(int) $profile->id] = array_values(array_filter((array) ($evaluation['reasons'] ?? [])));
                }
            });

        return $matches;
    }

    /**
     * @return array<int,array{group_ids:array<int,int>,group_names:array<int,string>}>
     */
    protected function groupMatches(MarketingCampaign $campaign, ?int $tenantId = null): array
    {
        $groupIds = $campaign->groups->pluck('id')->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->all();
        if ($groupIds === []) {
            return [];
        }

        $groupNames = $campaign->groups
            ->keyBy('id')
            ->map(fn ($group) => (string) $group->name);

        $matches = [];
        $members = MarketingGroupMember::query()
            ->whereIn('marketing_group_id', $groupIds)
            ->when($tenantId !== null, function (Builder $query) use ($tenantId): void {
                $query->whereHas('profile', fn (Builder $profileQuery) => $profileQuery->forTenantId($tenantId));
            })
            ->get(['marketing_group_id', 'marketing_profile_id']);

        foreach ($members as $member) {
            $profileId = (int) $member->marketing_profile_id;
            $groupId = (int) $member->marketing_group_id;
            if ($profileId <= 0 || $groupId <= 0) {
                continue;
            }

            $existing = $matches[$profileId] ?? ['group_ids' => [], 'group_names' => []];
            $existing['group_ids'][] = $groupId;
            $existing['group_names'][] = (string) ($groupNames[$groupId] ?? ('group_' . $groupId));
            $existing['group_ids'] = array_values(array_unique($existing['group_ids']));
            $existing['group_names'] = array_values(array_unique(array_filter($existing['group_names'])));
            $matches[$profileId] = $existing;
        }

        return $matches;
    }

    /**
     * @return array<int,int>
     */
    protected function manualProfileIds(MarketingCampaign $campaign, ?int $tenantId = null): array
    {
        return MarketingCampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->when($tenantId !== null, function (Builder $query) use ($tenantId): void {
                $query->whereHas('profile', fn (Builder $profileQuery) => $profileQuery->forTenantId($tenantId));
            })
            ->get(['marketing_profile_id', 'reason_codes', 'segment_snapshot'])
            ->filter(function (MarketingCampaignRecipient $recipient): bool {
                $reasonCodes = collect((array) $recipient->reason_codes)
                    ->map(fn ($value) => strtolower(trim((string) $value)))
                    ->filter()
                    ->values();
                if ($reasonCodes->contains('manual_add')) {
                    return true;
                }

                $snapshot = (array) $recipient->segment_snapshot;
                if ((bool) ($snapshot['manual_add'] ?? false)) {
                    return true;
                }

                $sources = collect((array) ($snapshot['audience_sources'] ?? []))
                    ->map(fn ($value) => strtolower(trim((string) $value)))
                    ->filter()
                    ->values();

                return $sources->contains('manual_add');
            })
            ->pluck('marketing_profile_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{0:string,1:array<int,string>}
     */
    protected function eligibilityForChannel(MarketingProfile $profile, string $channel, ?array $smsEvaluation = null): array
    {
        $channel = strtolower(trim($channel));
        $reasons = [];
        $eligible = true;

        if ($channel === 'sms') {
            if ($smsEvaluation !== null && $smsEvaluation !== []) {
                $eligible = (bool) ($smsEvaluation['eligible'] ?? false);
                $reasons = collect((array) ($smsEvaluation['reason_codes'] ?? []))
                    ->map(fn ($value): string => strtolower(trim((string) $value)))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return [$eligible ? 'queued_for_approval' : 'skipped', $reasons];
            }

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
