<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\TenantMarketingSetting;
use App\Services\PaymentsCore\Contract;

/** Separate reviewed tenant policy for POS redemption; online rules are unchanged. */
final class PosRewardRedemptionSettings
{
    public const SETTING_KEY = 'pos_candle_cash_redemption';

    public function __construct(private PosStore $store, private PosCustomerRewards $rewards) {}

    /** An owner must explicitly review this exact policy and the underlying published rewards program. */
    public function version(array $setting, string $rewardsVersion): string
    {
        if (($setting['enabled'] ?? null) !== true || ! is_int($setting['max_minor'] ?? null)
            || $setting['max_minor'] < 1 || $setting['max_minor'] > Contract::MAX_MINOR
            || ($setting['refund_policy'] ?? null) !== 'restore_full_review_partial'
            || ! preg_match('/^[a-f0-9]{64}$/D', $rewardsVersion)) {
            throw new PosException('in_person_rewards_policy_invalid', 422);
        }

        return Contract::hash(['enabled' => true, 'max_minor' => $setting['max_minor'],
            'refund_policy' => 'restore_full_review_partial', 'rewards_version' => $rewardsVersion]);
    }

    public function tenant(int $tenant): array
    {
        if (config('pos_live.in_person_rewards_enabled', false) !== true || $tenant < 1) {
            throw new PosException('in_person_rewards_disabled', 403);
        }
        $setting = TenantMarketingSetting::query()->forTenantId($tenant)->where('key', self::SETTING_KEY)->first()?->value;
        $program = $this->rewards->policy($tenant);
        if (! is_array($setting) || ! $program || ($program['access']['launch_state'] ?? 'published') !== 'published'
            || ($program['access']['test_mode'] ?? false)) {
            throw new PosException('in_person_rewards_unavailable', 503);
        }
        $version = $this->version($setting, $program['version']);
        if (! is_string($setting['reviewed_version'] ?? null) || ! hash_equals($version, $setting['reviewed_version'])
            || ! is_int($setting['reviewed_by'] ?? null) || $setting['reviewed_by'] < 1
            || ! is_string($setting['reviewed_at'] ?? null) || strtotime($setting['reviewed_at']) === false) {
            throw new PosException('in_person_rewards_review_required', 503);
        }

        return ['version' => $version, 'max_minor' => $setting['max_minor'],
            'refund_policy' => $setting['refund_policy'], 'rewards_version' => $program['version']];
    }

    public function forRegister(array $scope): array
    {
        $policy = $this->tenant((int) $scope['tenant_id']);
        $config = $this->store->decrypt($scope['register']->configuration);
        $reviewed = $config['pos_candle_cash_reviewed_version'] ?? null;
        if (! is_string($reviewed) || ! hash_equals($policy['version'], $reviewed)) {
            throw new PosException('in_person_rewards_register_review_required', 503);
        }

        return $policy;
    }

    public function availableForTenant(int $tenant): bool
    {
        try {
            $this->tenant($tenant);

            return true;
        } catch (PosException) {
            return false;
        }
    }
}
