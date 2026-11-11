<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\MarketingProfile;
use App\Services\Marketing\CustomerAccountFence;
use App\Services\PaymentsCore\MerchantBinding;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/** Final local-only checks within the journal's descriptor transaction, shared with policy publication. */
final class PosRewardPaymentAdmission
{
    public const PROGRAM_KEYS = ['candle_cash_program_config', 'candle_cash_policy_config', 'candle_cash_access_state'];

    public function assert(Connection $db, MerchantBinding $binding, object $sale, array $snapshot): void
    {
        $terms = $snapshot['terms']['pos_candle_cash'] ?? null;
        if ($terms === null) {
            return;
        }
        if ($db->transactionLevel() < 1 || $db->getPdo() !== DB::connection()->getPdo()) {
            throw new PosException('reward_database_boundary_required', 503);
        }
        app(CustomerAccountFence::class)->lock($binding->tenantId);
        $settings = $db->table('tenant_marketing_settings')->where('tenant_id', $binding->tenantId)
            ->whereIn('key', [...self::PROGRAM_KEYS, PosRewardRedemptionSettings::SETTING_KEY])->orderBy('key')->lockForUpdate()->get();
        if ($settings->count() !== 4) {
            throw new PosException('published_live_tenant_program_required', 503);
        }
        $register = $db->table('pos_live_registers')->where('runtime_id', $binding->runtimeId)->where('tenant_id', $binding->tenantId)
            ->where('id', $binding->registerId)->where('active', true)->lockForUpdate()->first();
        if (! $register) {
            throw new PosException('register_unavailable', 404);
        }
        $policy = app(PosRewardRedemptionSettings::class)->forRegister(['tenant_id' => $binding->tenantId, 'register' => $register]);
        if (($snapshot['customer_rewards']['version'] ?? null) !== $policy['rewards_version']
            || ($terms['policy_version'] ?? null) !== $policy['version']) {
            throw new PosException('in_person_rewards_policy_changed', 409);
        }
        $hold = $db->table('pos_candle_cash_reservations')->where('runtime_id', $binding->runtimeId)->where('tenant_id', $binding->tenantId)
            ->where('sale_id', $sale->id)->lockForUpdate()->first();
        $authorization = $hold ? $db->table('pos_reward_authorizations')->where('runtime_id', $binding->runtimeId)
            ->where('tenant_id', $binding->tenantId)->where('id', $hold->authorization_reference)->where('sale_id', $sale->id)->lockForUpdate()->first() : null;
        $profile = MarketingProfile::query()->forTenantId($binding->tenantId)->whereKey($terms['profile_id'] ?? 0)->lockForUpdate()->first();
        if (! $hold || $hold->state !== 'held' || (int) $hold->marketing_profile_id !== ($terms['profile_id'] ?? null)
            || (int) $hold->amount_minor !== ($terms['amount_minor'] ?? null) || $hold->policy_version !== $policy['version']
            || ! $authorization || ! $profile || (int) $authorization->marketing_profile_id !== (int) $profile->id
            || ! app(PosRewardCustomerProof::class)->identityValid($profile, $authorization, $sale)) {
            throw new PosException('customer_identity_review_required', 409);
        }
        // Eligibility withdrawals serialize with admission. A later pause cannot rewrite an initiated purchase.
        if ($db->getSchemaBuilder()->hasTable('subscription_pilot_members') && $db->getSchemaBuilder()->hasTable('subscription_billing_agreements')) {
            $members = $db->table('subscription_pilot_members')->where('tenant_id', $binding->tenantId)->where('marketing_profile_id', $profile->id)
                ->orderBy('id')->lockForUpdate()->pluck('id');
            $db->table('subscription_billing_agreements')->where('tenant_id', $binding->tenantId)->whereIn('member_id', $members)
                ->orderBy('id')->lockForUpdate()->get();
        }
        $effective = app(PosRewardRedemptionSettings::class)->forRegisterProfile(['tenant_id' => $binding->tenantId, 'register' => $register], $profile);
        if ((int) $hold->amount_minor > $effective['max_minor']) {
            throw new PosException('membership_redemption_limit_changed', 409);
        }
    }
}
