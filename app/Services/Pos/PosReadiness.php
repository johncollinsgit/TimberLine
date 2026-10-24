<?php

declare(strict_types=1);

namespace App\Services\Pos;

final class PosReadiness
{
    public function __construct(private PosStore $store) {}

    /** Saved evidence only: this report never creates grants or contacts a provider. */
    public function blockers(object $register, object $connection, ?int $amount = null): array
    {
        $settings = $this->store->decrypt($register->configuration);
        $blockers = [];
        foreach (['enabled', 'collection_enabled', 'native_client_enabled'] as $flag) {
            if (config('pos_live.'.$flag) !== true) {
                $blockers[] = $flag.'_off';
            }
        }
        if (! $register->active || ! $register->collection_enabled) {
            $blockers[] = 'register_collection_off';
        }
        if (! $connection->active || ! $connection->verified_at) {
            $blockers[] = 'merchant_unverified';
        }
        if ($connection->environment !== config('pos_live.square_environment')) {
            $blockers[] = 'provider_environment_mismatch';
        }
        if (! in_array((int) $register->tenant_id, config('pos_live.tenant_ids', []), true)) {
            $blockers[] = 'tenant_not_allowlisted';
        }
        if (empty(config('pos_live.square_application_id'))) {
            $blockers[] = 'square_application_missing';
        }
        if ($this->store->db->getDriverName() !== 'mysql' && ! app()->environment('testing')) {
            $blockers[] = 'durable_mysql_required';
        }
        if ($connection->environment === 'production') {
            foreach (['distribution_verified', 'tap_entitlement_verified', 'square_signature_verified', 'webhooks_verified'] as $flag) {
                if (config('pos_live.'.$flag) !== true) {
                    $blockers[] = $flag.'_missing';
                }
            }
            if (empty(config('pos_live.apple_team_id'))) {
                $blockers[] = 'apple_team_missing';
            }
            if (in_array(config('cache.default'), ['array', 'null'], true)) {
                $blockers[] = 'shared_lock_store_required';
            }
            if (! $connection->acceptance_at || ! $connection->acceptance_build) {
                // A separately reviewed bounded acceptance session can precede
                // launch. Its named device/actor are still checked by PosAccess.
                $test = $settings['acceptance'] ?? [];
                $eligible = isset($test['expires_at'], $test['max_amount_minor'], $test['build'])
                    && strtotime($test['expires_at']) > time() && is_int($test['max_amount_minor'])
                    && $test['max_amount_minor'] > 0 && ($amount === null || $amount <= $test['max_amount_minor']);
                $spent = (int) $this->store->rows('sales', (int) $register->tenant_id)
                    ->where('register_id', $register->id)->whereNotIn('state', ['quoted', 'failed', 'cancelled'])->sum('amount_minor');
                if (! $eligible || ($amount !== null && $spent + $amount > $test['max_amount_minor'])) {
                    $blockers[] = 'physical_acceptance_missing';
                }
            }
            if (! $connection->verified_at || strtotime($connection->verified_at) < time() - 86400) {
                $blockers[] = 'merchant_evidence_stale';
            }
        }
        if ($connection->environment === 'production' && (empty($settings['merchant_review']['document_hash']) || ($settings['merchant_review']['markup_minor'] ?? null) !== 0)) {
            $blockers[] = 'actual_merchant_rate_review_missing';
        }
        if (($settings['catalog_reviewed'] ?? false) !== true || ($settings['tax_reviewed'] ?? false) !== true) {
            $blockers[] = 'catalog_or_tax_unreviewed';
        }
        if (empty($settings['tax_valid_until']) || strtotime($settings['tax_valid_until']) <= time()) {
            $blockers[] = 'tax_review_expired';
        }
        if ($register->catalog_source === 'shopify' && ($settings['shopify_acceptance_verified'] ?? false) !== true) {
            $blockers[] = 'shopify_acceptance_missing';
        }

        return array_values(array_unique($blockers));
    }
}
