<?php

declare(strict_types=1);

namespace App\Services\Pos;

use Carbon\CarbonImmutable;

/**
 * Classifies a freshly retrieved Shopify observation for staff review only.
 * This never authorizes a discount, starts a payment, or changes Shopify.
 * Provider authentication and retrieval belong to the future adapter.
 */
final class PosBirthdayChannelEvidence
{
    public function classify(array $issuance, array $observation, CarbonImmutable $now): array
    {
        if (config('pos_live.birthday_channel_evidence_enabled', false) !== true) {
            return ['state' => 'disabled', 'redeemable' => false];
        }

        // These are exact identifiers of the existing online issuance, never a
        // code/name lookup or a match inferred from customer contact details.
        foreach (['tenant_id', 'issuance_id', 'cycle_year', 'store_key', 'discount_node_id'] as $key) {
            if (! isset($issuance[$key], $observation[$key]) || $issuance[$key] !== $observation[$key]) {
                return ['state' => 'identity_review_required', 'redeemable' => false];
            }
        }
        if (! is_int($issuance['tenant_id']) || $issuance['tenant_id'] < 1
            || ! is_int($issuance['issuance_id']) || $issuance['issuance_id'] < 1
            || ! is_int($issuance['cycle_year']) || $issuance['cycle_year'] < 1900 || $issuance['cycle_year'] > 9999
            || ! is_string($issuance['store_key']) || $issuance['store_key'] === ''
            || ! is_string($issuance['discount_node_id']) || $issuance['discount_node_id'] === '') {
            return ['state' => 'identity_review_required', 'redeemable' => false];
        }
        try {
            $retrieved = CarbonImmutable::parse($observation['retrieved_at'] ?? '');
        } catch (\Throwable) {
            return ['state' => 'provider_review_required', 'redeemable' => false];
        }
        if (empty($observation['retrieved_at']) || $retrieved->greaterThan($now)
            || $retrieved->lessThan($now->subSeconds(90))
            || ! isset($observation['usage_count']) || ! is_int($observation['usage_count']) || $observation['usage_count'] < 0
            || ! isset($observation['active']) || ! is_bool($observation['active'])) {
            return ['state' => 'provider_review_required', 'redeemable' => false];
        }
        if ($observation['usage_count'] > 0 || ($issuance['pos_consumed'] ?? false) === true) {
            return ['state' => 'usage_review_required', 'redeemable' => false];
        }
        if ($observation['active']) {
            return ['state' => 'online_code_active', 'redeemable' => false];
        }

        // Even a fresh inactive/unused response cannot prove that an already
        // accepted checkout won't arrive later, nor fence a concurrent activation.
        return ['state' => 'channel_fence_required', 'redeemable' => false];
    }
}
