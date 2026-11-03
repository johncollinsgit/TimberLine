<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingProfile;
use App\Services\Marketing\BirthdayClubService;
use Illuminate\Support\Facades\DB;

/** Read-only evidence, never a POS issuance, hold, discount, or authorization to redeem. */
final class PosBirthdayEntitlementEvidence
{
    public function __construct(private BirthdayClubService $club) {}

    public function inspect(int $tenant, int $profileId, int $cycleYear): array
    {
        if (config('pos_live.birthday_pos_evidence_enabled', false) !== true) {
            return ['state' => 'disabled', 'redeemable' => false];
        }
        if ($tenant < 1 || $profileId < 1 || $cycleYear < 1900 || $cycleYear > 9999) {
            throw new PosException('birthday_evidence_invalid', 422);
        }
        $profile = MarketingProfile::query()->where('tenant_id', $tenant)->whereKey($profileId)->first();
        if (! $profile) {
            throw new PosException('customer_not_found', 404);
        }
        if (! $this->club->enabled($tenant)) {
            return ['state' => 'birthday_club_unavailable', 'redeemable' => false];
        }
        try {
            $identity = $this->club->identity($profile);
        } catch (\RuntimeException) {
            return ['state' => 'identity_review_required', 'redeemable' => false];
        }
        // An alias graph can include a historical catch-up identity; do not infer a POS
        // entitlement from it until an explicit, audited customer link is reviewed.
        if ((int) $identity['tenant_id'] !== $tenant || (int) $identity['canonical_profile_id'] !== $profileId
            || $identity['profile_ids'] !== [$profileId]) {
            return ['state' => 'identity_review_required', 'redeemable' => false];
        }
        $coverage = DB::table('birthday_cycle_coverage')->where('tenant_id', $tenant)
            ->where('canonical_profile_id', $profileId)->where('cycle_year', $cycleYear)->first();
        $issuances = BirthdayRewardIssuance::query()->where('marketing_profile_id', $profileId)
            ->where('cycle_year', $cycleYear)->limit(2)->get();
        $historical = CustomerBirthdayProfile::query()->where('marketing_profile_id', $profileId)
            ->where('reward_last_issued_year', $cycleYear)->exists();
        if ($issuances->count() === 0 && ! $coverage && ! $historical) {
            return ['state' => 'not_issued', 'redeemable' => false];
        }
        if ($issuances->count() !== 1 || ! $coverage || (bool) $coverage->legacy_covered
            || (int) $coverage->issuance_id !== (int) $issuances[0]->id) {
            return ['state' => 'coverage_review_required', 'redeemable' => false];
        }
        $issuance = $issuances[0];
        if ($issuance->reward_type !== 'discount_code') {
            return ['state' => 'unsupported_reward_type', 'redeemable' => false];
        }
        if (in_array((string) $issuance->status, ['redeemed', 'cancelled', 'expired'], true) || $issuance->isExpired()) {
            return ['state' => 'unavailable', 'redeemable' => false];
        }
        if (! in_array((string) $issuance->status, ['issued', 'claimed'], true) || $issuance->redeemed_at !== null) {
            return ['state' => 'coverage_review_required', 'redeemable' => false];
        }

        // Online issuance/activation remains independent. A future POS lifecycle must
        // coordinate Shopify redemption, durable holds, payment outcome and retries.
        return ['state' => 'channel_coordination_required', 'redeemable' => false, 'issuance_id' => (int) $issuance->id, 'cycle_year' => $cycleYear];
    }
}
