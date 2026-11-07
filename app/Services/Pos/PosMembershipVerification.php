<?php

namespace App\Services\Pos;

use App\Models\MarketingProfile;
use App\Services\Marketing\CustomerAccountFence;

/** Short-lived owner review of a current membership account; never a join date, balance or marketing consent. */
final class PosMembershipVerification
{
    public function __construct(private PosStore $store, private PosAccess $access, private PosCustomerDirectory $customers) {}

    public function record(string $register, int $profile, bool $active, string $reference): array
    {
        $scope = $this->access->register($register, 'admin');
        $policy = app(PosRewardRedemptionSettings::class)->forRegister($scope);
        if (! isset($policy['member_max_minor'])) {
            throw new PosException('membership_redemption_not_configured', 422);
        }

        return app(CustomerAccountFence::class)->forTenant($scope['tenant_id'], function () use ($scope, $profile, $active, $reference) {
            $customer = $this->customers->profile($scope['tenant_id'], $profile);
            MarketingProfile::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $settings = $this->store->decrypt($scope['register']->configuration);
            $expiry = min(time() + 86400, strtotime($settings['tax_valid_until'] ?? '') ?: time());
            if ($expiry <= time()) {
                throw new PosException('tax_review_expired', 409);
            }
            $details = ['profile_id' => (int) $customer->id, 'active' => $active, 'actor_id' => $scope['actor_id'],
                'register_id' => $scope['register']->id, 'reference' => $reference, 'expires_at' => gmdate('c', $expiry),
                'reviewed_at' => now()->toIso8601String(), 'scope' => 'redemption_limit_only'];
            $this->store->event($scope['tenant_id'], $this->key($profile), 'pos_membership_verified', $details);

            return ['recorded' => true, 'active' => $active, 'expires_at' => $details['expires_at']];
        });
    }

    public function active(MarketingProfile $profile): ?array
    {
        if (! config('pos_live.in_person_rewards_enabled') || ! filled(config('pos_live.runtime_id'))) {
            return null;
        }
        $event = $this->store->rows('events', (int) $profile->tenant_id)->where('sale_id', $this->key((int) $profile->id))
            ->where('code', 'pos_membership_verified')->orderByDesc('id')->when($this->store->db->transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
        $d = $event ? $this->store->decrypt($event->details) : [];
        if (($d['profile_id'] ?? null) !== (int) $profile->id || ($d['active'] ?? false) !== true
            || ($d['scope'] ?? null) !== 'redemption_limit_only' || strtotime($d['expires_at'] ?? '') <= time()) {
            return null;
        }

        return ['source' => 'owner_current_membership_review', 'id' => (string) $event->id, 'expires_at' => $d['expires_at'], 'observed_at' => $d['reviewed_at']];
    }

    private function key(int $profile): string
    {
        return substr(hash('sha256', 'pos-current-membership:'.$profile), 0, 32);
    }
}
