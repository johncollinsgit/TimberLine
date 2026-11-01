<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use Illuminate\Support\Str;

/**
 * Reserves the shared customer wallet inside the sale transaction. The payment
 * lifecycle keeps uncertain holds and uses durable claims for unstarted release.
 * Customer-facing issuance and collection remain behind independent rollout gates.
 */
final class PosCandleCashReservation
{
    public function __construct(
        private PosStore $store,
        private PosRewardReservationAuthority $authority,
        private PosRewardRedemptionPolicy $policy,
    ) {}

    public function reserve(int $tenant, string $saleId, int $amountMinor, string $customerProof): object
    {
        if (config('pos_live.in_person_rewards_enabled', false) !== true) {
            throw new PosException('in_person_rewards_disabled', 403);
        }
        if ($tenant < 1 || $amountMinor < 1 || $customerProof === '') {
            throw new PosException('reward_reservation_invalid', 422);
        }

        return $this->store->db->transaction(function () use ($tenant, $saleId, $amountMinor, $customerProof): object {
            // Same lock serializes retries for a sale and prevents two holds on it.
            $sale = $this->store->rows('sales', $tenant)->where('id', $saleId)->lockForUpdate()->first();
            if (! $sale) {
                throw new PosException('sale_not_found', 404);
            }
            $terms = $this->authority->authorize($tenant, $sale, $customerProof, $amountMinor);
            $profileId = (int) ($terms['profile_id'] ?? 0);
            $expectedAmount = $terms['expected_amount_minor'] ?? null;
            $limit = (int) ($terms['limit_minor'] ?? -1);
            $eligible = (int) ($terms['eligible_minor'] ?? -1);
            $version = $terms['policy_version'] ?? null;
            $authorization = $terms['authorization_reference'] ?? null;
            if ($profileId < 1 || ! is_int($expectedAmount) || $expectedAmount !== $amountMinor || $limit < 0 || $eligible < 0 || ! is_string($version) || $version === ''
                || strlen($version) > 64 || ! is_string($authorization) || $authorization === ''
                || strlen($authorization) > 128 || hash_equals($customerProof, $authorization)) {
                throw new PosException('reward_authorization_invalid', 403);
            }
            // Merge takes the same profile lock before moving the wallet. The
            // profile cannot become an archived donor while a hold is created.
            $profile = MarketingProfile::query()->whereKey($profileId)->where('tenant_id', $tenant)->lockForUpdate()->first();
            if (! $profile) {
                throw new PosException('customer_not_found', 404);
            }
            if ($profile->merged_at !== null || $profile->merged_into_profile_id !== null) {
                throw new PosException('customer_identity_review_required', 409);
            }
            if ($this->authority instanceof PosVerifiedRewardReservationAuthority
                && (! is_int($terms['account_link_id'] ?? null)
                    || ! app(PosVerifiedCustomerAccount::class)->verified($profile, $terms['account_link_id']))) {
                throw new PosException('customer_identity_review_required', 409);
            }
            if (! $this->store->db->getSchemaBuilder()->hasTable('customer_merge_operation_members')
                || ! $this->store->db->getSchemaBuilder()->hasTable('customer_merge_operations')) {
                throw new PosException('customer_identity_review_required', 409);
            }
            if ($this->store->db->table('customer_merge_operation_members as member')
                ->join('customer_merge_operations as operation', 'operation.id', '=', 'member.customer_merge_operation_id')
                ->where('operation.tenant_id', $tenant)->where('member.marketing_profile_id', $profileId)
                ->whereNotIn('operation.status', ['completed', 'cancelled'])->lockForUpdate()->exists()) {
                throw new PosException('customer_identity_review_required', 409);
            }
            $holds = $this->store->db->table('pos_candle_cash_reservations')
                ->where('runtime_id', $this->store->runtime())->where('tenant_id', $tenant);
            $existing = (clone $holds)->where('sale_id', $saleId)->first();
            if ($existing) {
                if ((int) $existing->marketing_profile_id !== $profileId || (int) $existing->amount_minor !== $amountMinor
                    || $existing->policy_version !== $version || $existing->authorization_reference !== $authorization) {
                    throw new PosException('reward_reservation_conflict', 409);
                }

                return $existing;
            }
            if ($sale->state !== 'quoted' || $sale->attempt_id !== null || $sale->descriptor_issued_at !== null
                || $sale->source_reference !== null
                || $this->store->db->table('pos_payment_start_claims')->where('runtime_id', $this->store->runtime())
                    ->where('tenant_id', $tenant)->where('sale_id', $saleId)->exists()) {
                throw new PosException('reward_sale_not_editable', 409);
            }
            $this->store->db->table('candle_cash_balances')->insertOrIgnore([
                'marketing_profile_id' => $profileId, 'balance' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $balance = CandleCashBalance::query()->whereKey($profileId)->lockForUpdate()->firstOrFail();
            // Stored values retain thousandths; floor ensures they never offer a cent that is absent.
            $balanceMinor = (int) floor(round((float) $balance->balance * 1000) / 10);
            if ($amountMinor > $this->policy->available($balanceMinor, $eligible, $limit)) {
                throw new PosException('reward_amount_unavailable', 422);
            }
            $id = (string) Str::uuid();
            $balance->balance = $balance->balance - ($amountMinor / 100);
            $balance->save();
            CandleCashTransaction::query()->create([
                'marketing_profile_id' => $profileId, 'type' => 'redeem',
                // The legacy integer column remains mandatory on MySQL; the canonical decimal follows it.
                'points' => (int) round(-$amountMinor / 100), 'candle_cash_delta' => -$amountMinor / 100,
                'source' => 'pos_reservation', 'source_id' => $id, 'description' => 'In-person reward reservation',
            ]);
            $this->store->db->table('pos_candle_cash_reservations')->insert([
                'id' => $id, 'runtime_id' => $this->store->runtime(), 'tenant_id' => $tenant, 'sale_id' => $saleId,
                'marketing_profile_id' => $profileId, 'amount_minor' => $amountMinor, 'policy_version' => $version,
                'authorization_reference' => $authorization, 'state' => 'held', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return (clone $holds)->where('id', $id)->first();
        }, 3);
    }
}
