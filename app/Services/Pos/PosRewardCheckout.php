<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\CandleCashBalance;
use App\Services\Marketing\CustomerAccountFence;

/** Customer-present preview only. The quote reserves the actual amount separately and atomically. */
final class PosRewardCheckout
{
    public function __construct(private PosAccess $access, private PosRewardCustomerProof $proofs,
        private PosCustomerDirectory $customers, private PosRewardRedemptionSettings $settings) {}

    public function preview(string $register, string $proof, ?int $expectedCustomer = null): array
    {
        $scope = $this->access->register($register, 'quote');

        return app(CustomerAccountFence::class)->forTenant($scope['tenant_id'], function () use ($scope, $proof, $expectedCustomer) {
            $registerPolicy = $this->settings->forRegister($scope);
            $authorization = $this->proofs->preview($scope['tenant_id'], $proof);
            $profile = $this->customers->profile($scope['tenant_id'], (int) $authorization->marketing_profile_id);
            if (($expectedCustomer !== null && (int) $profile->id !== $expectedCustomer)
                || $authorization->policy_version !== $registerPolicy['version']
                || ! app(PosVerifiedCustomerAccount::class)->verified($profile, (int) $authorization->account_link_id)) {
                throw new PosException('customer_identity_review_required', 409);
            }
            $policy = $this->settings->forProfile($profile);
            $balance = CandleCashBalance::whereKey($profile->id)->value('balance') ?? 0;
            $minor = max(0, (int) floor(round((float) $balance * 1000) / 10));
            $program = app(PosCustomerRewards::class)->policy($scope['tenant_id']);

            return ['customer' => ['id' => (int) $profile->id, 'name' => trim($profile->first_name.' '.$profile->last_name),
                'email' => $profile->email, 'phone' => $profile->phone],
                'label' => data_get($program, 'policy.program_identity.program_name', data_get($program, 'program.label', 'Rewards')),
                'balance_minor' => $minor, 'max_minor' => min((int) $authorization->cap_minor, $policy['max_minor']),
                'available_minor' => min($minor, (int) $authorization->cap_minor, $policy['max_minor']),
                'expires_at' => \Carbon\CarbonImmutable::parse($authorization->expires_at)->toIso8601String()];
        });
    }
}
