<?php

namespace App\Services\Marketing;

use App\Models\CandleCashTransaction;
use App\Models\MarketingConsentRequest;
use App\Models\MarketingProfile;
use Illuminate\Support\Collection;

class MarketingStorefrontWidgetService
{
    public function __construct(
        protected MarketingProfileAnalyticsService $analyticsService
    ) {
    }

    /**
     * @param Collection<int,\App\Models\CandleCashReward> $rewards
     * @param Collection<int,\App\Models\CandleCashRedemption> $redemptions
     * @return array<int,string>
     */
    public function rewardWidgetStates(
        ?MarketingProfile $profile,
        int $balance,
        Collection $rewards,
        Collection $redemptions
    ): array {
        if (! $profile) {
            return ['unknown_customer'];
        }

        $states = [];
        $states[] = $balance > 0 ? 'known_customer_has_balance' : 'known_customer_no_balance';

        $activeRewards = $rewards->filter(fn ($reward): bool => (bool) ($reward->is_active ?? true));
        $minPoints = (int) ($activeRewards->min('points_cost') ?? 0);
        if ($minPoints > 0) {
            if ($balance >= $minPoints) {
                $states[] = 'reward_available';
            } elseif ($balance > 0 && ($minPoints - $balance) <= 25) {
                $states[] = 'reward_near_threshold';
            }
        }

        if ($redemptions->where('status', 'issued')->isNotEmpty()) {
            $states[] = 'reward_code_issued';
        }
        if ($redemptions->where('status', 'redeemed')->isNotEmpty()) {
            $states[] = 'reward_code_redeemed';
        }
        if ($redemptions->where('status', 'expired')->isNotEmpty()) {
            $states[] = 'reward_code_expired';
        }

        return $this->clean($states);
    }

    /**
     * @return array<int,string>
     */
    public function consentWidgetStates(
        ?MarketingProfile $profile,
        ?MarketingConsentRequest $request = null,
        bool $incentiveEnabled = false
    ): array {
        if (! $profile) {
            return ['consent_unknown'];
        }

        $states = [];
        if ($request && $request->status === 'requested') {
            $states[] = 'sms_requested';
        }

        $states[] = $profile->accepts_sms_marketing ? 'sms_confirmed' : 'sms_not_consented';
        $states[] = $profile->accepts_email_marketing ? 'email_confirmed' : 'email_not_consented';

        if ($incentiveEnabled) {
            $awarded = CandleCashTransaction::query()
                ->where('marketing_profile_id', $profile->id)
                ->where('source', 'consent')
                ->where('points', '>', 0)
                ->exists();
            $states[] = $awarded ? 'incentive_already_awarded' : 'incentive_available';
        }

        return $this->clean($states);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,string>
     */
    public function customerStatusStates(?MarketingProfile $profile, string $identityStatus, array $context = []): array
    {
        if (! $profile) {
            return match ($identityStatus) {
                'review_required' => ['needs_verification'],
                'partial_match' => ['partial_match', 'needs_verification'],
                default => ['unknown_customer'],
            };
        }

        $metrics = is_array($context['metrics'] ?? null)
            ? (array) $context['metrics']
            : $this->analyticsService->metricsForProfile($profile);

        $states = ['linked_customer'];
        if ((bool) ($metrics['purchased_at_event'] ?? false)) {
            $states[] = 'recent_event_buyer';
        }

        $hasShopify = (bool) ($metrics['has_shopify_link'] ?? false);
        $hasSquare = (bool) ($metrics['has_square_link'] ?? false);
        if ($hasShopify && ! $hasSquare) {
            $states[] = 'online_only';
        } elseif ($hasSquare && ! $hasShopify) {
            $states[] = 'square_only';
        }

        $daysSinceLastOrder = $metrics['days_since_last_order'] ?? null;
        if (is_numeric($daysSinceLastOrder) && (int) $daysSinceLastOrder >= 45 && (bool) ($metrics['has_sms_consent'] ?? false)) {
            $states[] = 'eligible_for_winback';
        }

        $balance = (int) ($context['candle_cash_balance'] ?? 0);
        $minRewardCandleCash = (int) ($context['min_reward_candle_cash'] ?? $context['min_reward_points'] ?? 0);
        if ($balance > 0 && $minRewardCandleCash > 0 && $balance >= max(1, $minRewardCandleCash - 25)) {
            $states[] = 'eligible_for_reward_nudge';
        }

        return $this->clean($states);
    }

    /**
     * @param array<string,mixed> $result
     * @return array<int,string>
     */
    public function redemptionStates(array $result): array
    {
        $state = trim(strtolower((string) ($result['state'] ?? '')));
        $states = [];
        if ($state !== '') {
            $states[] = $state;
        }

        if (in_array((string) ($result['error'] ?? ''), ['insufficient_candle_cash', 'insufficient_points'], true)) {
            $states[] = 'insufficient_candle_cash';
        }
        if ((string) ($result['error'] ?? '') === 'already_has_active_code') {
            $states[] = 'already_has_active_code';
        }
        if ((string) ($result['error'] ?? '') === 'code_already_used') {
            $states[] = 'reward_code_already_used';
        }
        if ((string) ($result['error'] ?? '') === 'code_expired') {
            $states[] = 'reward_code_expired';
        }
        if ((string) ($result['error'] ?? '') === 'reward_unavailable') {
            $states[] = 'reward_unavailable';
        }

        return $this->clean($states);
    }

    /**
     * @return array<int,string>
     */
    public function recoveryStatesForError(string $code): array
    {
        return match (strtolower(trim($code))) {
            'unauthorized_storefront_request' => ['contact_support'],
            'identity_review_required' => ['verification_required', 'contact_support'],
            'profile_not_found' => ['verification_required'],
            'insufficient_candle_cash', 'insufficient_points' => ['try_again_later'],
            'reward_unavailable' => ['try_again_later'],
            'already_has_active_code' => ['already_redeemed'],
            'code_already_used' => ['already_redeemed'],
            'code_expired' => ['try_again_later'],
            'redemption_blocked' => ['unresolved_reconciliation_pending', 'contact_support'],
            default => ['try_again_later'],
        };
    }

    /**
     * @param array<int,string> $states
     * @return array<int,string>
     */
    protected function clean(array $states): array
    {
        return collect($states)
            ->map(fn ($value): string => trim(strtolower((string) $value)))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }
}
