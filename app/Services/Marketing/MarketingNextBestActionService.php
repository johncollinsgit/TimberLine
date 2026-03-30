<?php

namespace App\Services\Marketing;

use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\MarketingProfile;
use App\Models\MarketingStorefrontEvent;

class MarketingNextBestActionService
{
    public function __construct(
        protected MarketingProfileAnalyticsService $analyticsService,
        protected CandleCashService $candleCashService
    ) {
    }

    /**
     * @return array{
     *  action_key:string,
     *  title:string,
     *  summary:string,
     *  confidence:float,
     *  reasons:array<int,string>,
     *  suggested_channel:?string
     * }
     */
    public function forProfile(MarketingProfile $profile): array
    {
        $metrics = $this->analyticsService->metricsForProfile($profile);
        $reasons = [];

        $hasSmsConsent = (bool) ($metrics['has_sms_consent'] ?? false);
        $hasEmailConsent = (bool) ($metrics['has_email_consent'] ?? false);
        $daysSinceOrder = (int) ($metrics['days_since_last_order'] ?? 9999);
        $hasPhone = ! empty($profile->normalized_phone);
        $hasEmail = ! empty($profile->normalized_email);
        $openStorefrontIssues = (int) MarketingStorefrontEvent::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('resolution_status', 'open')
            ->whereIn('status', ['error', 'verification_required', 'pending'])
            ->count();

        if ($openStorefrontIssues > 0) {
            return [
                'action_key' => 'resolve_storefront_issue',
                'title' => 'Resolve storefront/reconciliation issue first',
                'summary' => 'Profile has open widget/public-flow or redemption reconciliation issues that should be cleared before new outreach.',
                'confidence' => 0.87,
                'reasons' => ['open_storefront_issue_count_' . $openStorefrontIssues, 'operational_blocker'],
                'suggested_channel' => null,
            ];
        }

        if (! $hasSmsConsent && $hasEmailConsent && $hasEmail) {
            return [
                'action_key' => 'invite_sms_consent',
                'title' => 'Invite profile to SMS consent',
                'summary' => 'Profile already has email consent and can be invited to opt into SMS updates.',
                'confidence' => 0.83,
                'reasons' => ['email_consented', 'sms_not_consented', 'email_available'],
                'suggested_channel' => 'email',
            ];
        }

        if ($hasSmsConsent && $hasPhone && $daysSinceOrder >= 60) {
            $reasons[] = 'sms_consented';
            $reasons[] = 'lapsed_' . $daysSinceOrder . '_days';

            return [
                'action_key' => 'send_winback_sms',
                'title' => 'Send winback SMS suggestion',
                'summary' => 'Profile is lapsed and eligible for SMS follow-up.',
                'confidence' => 0.79,
                'reasons' => $reasons,
                'suggested_channel' => 'sms',
            ];
        }

        $issuedRewards = (int) CandleCashRedemption::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('status', 'issued')
            ->count();
        if ($issuedRewards > 0 && ($hasSmsConsent || ($hasEmailConsent && $hasEmail))) {
            return [
                'action_key' => 'send_reward_code_reminder',
                'title' => 'Send reward code reminder',
                'summary' => 'Profile has issued Rewards code(s) that are not yet reconciled as redeemed.',
                'confidence' => 0.77,
                'reasons' => ['issued_reward_codes', 'no_reconciliation_yet'],
                'suggested_channel' => $hasSmsConsent && $hasPhone ? 'sms' : ($hasEmailConsent && $hasEmail ? 'email' : null),
            ];
        }

        $balance = $this->candleCashService->currentBalance($profile);
        $minimumRewardCost = (int) (CandleCashReward::query()
            ->where('is_active', true)
            ->min('candle_cash_cost') ?? 0);
        $distance = $minimumRewardCost > 0 ? max(0, $minimumRewardCost - $balance) : null;
        if ($distance !== null && $distance > 0 && $distance <= 100 && $hasEmailConsent && $hasEmail) {
            return [
                'action_key' => 'send_reward_reminder_email',
                'title' => 'Send reward reminder email suggestion',
                'summary' => 'Profile is within $' . number_format((float) $distance, 0) . ' of the redemption threshold.',
                'confidence' => 0.71,
                'reasons' => ['near_reward_threshold', 'email_consented'],
                'suggested_channel' => 'email',
            ];
        }

        $eventNames = collect((array) ($metrics['purchased_event_names'] ?? []))
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->values();
        if ($eventNames->contains(fn (string $name): bool => str_contains($name, 'flowertown'))
            && ! (bool) ($metrics['has_shopify_link'] ?? false)) {
            return [
                'action_key' => 'add_to_event_reactivation_campaign',
                'title' => 'Add to Flowertown reactivation campaign',
                'summary' => 'Flowertown buyer has not shown online reorder signals.',
                'confidence' => 0.68,
                'reasons' => ['flowertown_event_signal', 'no_shopify_reorder'],
                'suggested_channel' => $hasSmsConsent && $hasPhone ? 'sms' : ($hasEmailConsent && $hasEmail ? 'email' : null),
            ];
        }

        return [
            'action_key' => 'no_action',
            'title' => 'No action recommended right now',
            'summary' => 'Current profile signals do not cross winback, consent-capture, or reward-threshold rules.',
            'confidence' => 0.55,
            'reasons' => ['insufficient_trigger_signal'],
            'suggested_channel' => null,
        ];
    }
}
