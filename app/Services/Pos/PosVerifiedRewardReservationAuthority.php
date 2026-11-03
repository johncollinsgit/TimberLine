<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\Marketing\CanonicalMarketingProfileResolver;

/** Server-only proof and reviewed policy verification against the frozen quote. */
final class PosVerifiedRewardReservationAuthority implements PosRewardReservationAuthority
{
    public function __construct(
        private PosStore $store,
        private PosService $sales,
        private PosRewardCustomerProof $proofs,
        private PosRewardRedemptionSettings $settings,
        private CanonicalMarketingProfileResolver $identities,
    ) {}

    public function authorize(int $tenant, object $sale, string $customerProof, int $amountMinor): array
    {
        if ((int) $sale->tenant_id !== $tenant || $sale->runtime_id !== $this->store->runtime()) {
            throw new PosException('scope_invalid', 403);
        }
        $snapshot = $this->sales->snapshot($sale);
        if (($snapshot['binding']['environment'] ?? null) !== 'live') {
            throw new PosException('in_person_rewards_live_payment_required', 403);
        }
        $terms = $snapshot['terms']['pos_candle_cash'] ?? null;
        $subtotal = $snapshot['terms']['subtotal_minor'] ?? null;
        $discount = $snapshot['terms']['discount_minor'] ?? null;
        if (! is_array($terms) || ! is_int($terms['profile_id'] ?? null) || ! is_int($terms['amount_minor'] ?? null)
            || $terms['amount_minor'] !== $amountMinor || ! is_int($terms['eligible_minor'] ?? null)
            || ! is_int($subtotal) || ! is_int($discount) || $discount < $amountMinor
            || $terms['eligible_minor'] !== $subtotal - ($discount - $amountMinor)
            || (int) ($snapshot['customer']['id'] ?? 0) !== $terms['profile_id']) {
            throw new PosException('reward_quote_invalid', 409);
        }
        $register = $this->store->rows('registers', $tenant)->where('id', $sale->register_id)->where('active', true)->first();
        if (! $register) {
            throw new PosException('register_unavailable', 404);
        }
        $policy = $this->settings->forRegister(['tenant_id' => $tenant, 'register' => $register]);
        if (! is_string($terms['policy_version'] ?? null) || ! hash_equals($policy['version'], $terms['policy_version'])
            || ($terms['refund_policy'] ?? null) !== $policy['refund_policy']) {
            throw new PosException('in_person_rewards_policy_changed', 409);
        }
        $proof = $this->proofs->bind($tenant, $customerProof, $sale->id);
        if ((int) $proof->cap_minor < $amountMinor || ! hash_equals($policy['version'], $proof->policy_version)
            || $proof->disclosure_version !== PosRewardCustomerProof::DISCLOSURE_VERSION) {
            throw new PosException('reward_authorization_invalid', 403);
        }
        $profileId = (int) $proof->marketing_profile_id;
        $canonical = $this->identities->byId($tenant, $profileId);
        if ($profileId !== $terms['profile_id'] || ! $canonical || (int) $canonical->id !== $profileId) {
            throw new PosException('customer_identity_review_required', 409);
        }

        return ['profile_id' => $profileId, 'expected_amount_minor' => $terms['amount_minor'],
            'limit_minor' => $policy['max_minor'], 'eligible_minor' => $terms['eligible_minor'],
            'policy_version' => $policy['version'], 'authorization_reference' => $proof->id,
            'account_link_id' => (int) $proof->account_link_id];
    }
}
