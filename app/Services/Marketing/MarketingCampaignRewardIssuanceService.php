<?php

namespace App\Services\Marketing;

use App\Models\CandleCashTransaction;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingProfile;
use Illuminate\Database\Eloquent\Builder;

class MarketingCampaignRewardIssuanceService
{
    /**
     * @var array<int,string>
     */
    protected const FINAL_RECIPIENT_STATUSES = [
        'approved',
        'scheduled',
        'sending',
        'sent',
        'delivered',
        'failed',
        'undelivered',
        'converted',
    ];

    public function __construct(
        protected CandleCashService $candleCashService,
        protected MarketingSmsEligibilityService $smsEligibilityService,
        protected MarketingTenantOwnershipService $ownershipService
    ) {
    }

    public function sourceIdForCampaign(MarketingCampaign $campaign, int $amount): string
    {
        return sprintf('campaign:%d:subscriber-thank-you-%d', (int) $campaign->id, max(1, $amount));
    }

    /**
     * @return array{
     *   amount:int,
     *   source:string,
     *   source_id:string,
     *   issued_count:int,
     *   issued_candle_cash:float,
     *   last_issued_at:mixed
     * }
     */
    public function summaryForCampaign(MarketingCampaign $campaign, ?int $tenantId = null, int $amount = 5): array
    {
        $sourceId = $this->sourceIdForCampaign($campaign, $amount);

        $query = CandleCashTransaction::query()
            ->where('source', 'campaign_reward')
            ->where('source_id', $sourceId);

        if ($this->ownershipService->strictModeEnabled() && $tenantId !== null) {
            $query->whereHas('profile', fn (Builder $profileQuery) => $profileQuery->forTenantId($tenantId));
        }

        $latest = (clone $query)
            ->orderByDesc('id')
            ->first();

        return [
            'amount' => max(1, $amount),
            'source' => 'campaign_reward',
            'source_id' => $sourceId,
            'issued_count' => (int) (clone $query)->count(),
            'issued_candle_cash' => round((float) (clone $query)->sum('candle_cash_delta'), 3),
            'last_issued_at' => $latest?->created_at,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{
     *   campaign_id:int,
     *   amount:int,
     *   dry_run:bool,
     *   source:string,
     *   source_id:string,
     *   recipients_considered:int,
     *   profiles_considered:int,
     *   eligible_profiles:int,
     *   awarded:int,
     *   already_awarded:int,
     *   skipped:int,
     *   skipped_reasons:array<string,int>
     * }
     */
    public function issueForCampaign(MarketingCampaign $campaign, array $options = []): array
    {
        $amount = max(1, (int) ($options['amount'] ?? 5));
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $tenantId = $this->positiveInt($options['tenant_id'] ?? null);
        $strict = $this->ownershipService->strictModeEnabled();

        if ($strict && $tenantId === null) {
            $tenantId = $this->ownershipService->campaignOwnerTenantId((int) $campaign->id);
        }

        $summary = [
            'campaign_id' => (int) $campaign->id,
            'amount' => $amount,
            'dry_run' => $dryRun,
            'source' => 'campaign_reward',
            'source_id' => $this->sourceIdForCampaign($campaign, $amount),
            'recipients_considered' => 0,
            'profiles_considered' => 0,
            'eligible_profiles' => 0,
            'awarded' => 0,
            'already_awarded' => 0,
            'skipped' => 0,
            'skipped_reasons' => [],
        ];

        if (strtolower(trim((string) $campaign->channel)) !== 'sms') {
            $summary['skipped'] = 1;
            $summary['skipped_reasons'] = ['non_sms_campaign' => 1];

            return $summary;
        }

        if ($strict && $tenantId === null) {
            $summary['skipped'] = 1;
            $summary['skipped_reasons'] = ['tenant_context_required' => 1];

            return $summary;
        }

        if ($strict && $tenantId !== null && ! $this->ownershipService->campaignOwnedByTenant((int) $campaign->id, $tenantId)) {
            $summary['skipped'] = 1;
            $summary['skipped_reasons'] = ['foreign_tenant_campaign' => 1];

            return $summary;
        }

        $recipientsQuery = MarketingCampaignRecipient::query()
            ->with('profile:id,tenant_id,phone,normalized_phone,accepts_sms_marketing')
            ->where('campaign_id', $campaign->id)
            ->where('channel', 'sms')
            ->whereIn('status', self::FINAL_RECIPIENT_STATUSES)
            ->orderBy('id');

        if ($strict && $tenantId !== null) {
            $recipientsQuery->whereHas('profile', fn (Builder $profileQuery) => $profileQuery->forTenantId($tenantId));
        }

        $recipients = $recipientsQuery->get();
        $profiles = $recipients
            ->map(fn (MarketingCampaignRecipient $recipient): ?MarketingProfile => $recipient->profile)
            ->filter(fn (?MarketingProfile $profile): bool => $profile instanceof MarketingProfile)
            ->unique(fn (MarketingProfile $profile): int => (int) $profile->id)
            ->values();

        $summary['recipients_considered'] = (int) $recipients->count();
        $summary['profiles_considered'] = (int) $profiles->count();

        $evaluations = $this->smsEligibilityService->evaluateProfiles($profiles, $tenantId);

        foreach ($profiles as $profile) {
            $evaluation = (array) ($evaluations->get((int) $profile->id) ?? []);
            $eligible = (bool) ($evaluation['eligible'] ?? false);

            if (! $eligible) {
                $summary['skipped']++;
                $reason = (string) ($evaluation['blocking_reason'] ?? 'sms_not_eligible');
                $summary['skipped_reasons'][$reason] = (int) ($summary['skipped_reasons'][$reason] ?? 0) + 1;
                continue;
            }

            $summary['eligible_profiles']++;

            if ($dryRun) {
                if ($this->alreadyAwarded((int) $profile->id, (string) $summary['source_id'])) {
                    $summary['already_awarded']++;
                } else {
                    $summary['awarded']++;
                }

                continue;
            }

            $result = $this->candleCashService->addPointsIdempotent(
                profile: $profile,
                points: $amount,
                source: 'campaign_reward',
                sourceId: (string) $summary['source_id'],
                type: 'gift',
                description: 'Text subscriber thank-you reward',
                extraAttributes: [
                    'gift_intent' => 'retention',
                    'gift_origin' => 'sms_campaign',
                    'notified_via' => 'sms_campaign',
                    'notification_status' => 'pending_send',
                    'campaign_key' => trim((string) ($campaign->slug ?: ('campaign-' . $campaign->id))),
                ]
            );

            if ((bool) ($result['already_awarded'] ?? false)) {
                $summary['already_awarded']++;
            } else {
                $summary['awarded']++;
            }
        }

        return $summary;
    }

    protected function alreadyAwarded(int $profileId, string $sourceId): bool
    {
        return CandleCashTransaction::query()
            ->where('marketing_profile_id', $profileId)
            ->where('source', 'campaign_reward')
            ->where('source_id', $sourceId)
            ->exists();
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }
}
