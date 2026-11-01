<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use Illuminate\Support\Str;

/**
 * Reservation foundation only. There is deliberately no production authority,
 * route, lifecycle wiring, settlement or release. Pending payment uncertainty
 * therefore cannot inadvertently release a balance or permit a second charge.
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
            $terms = $this->authority->authorize($tenant, $sale, $customerProof);
            $profileId = (int) ($terms['profile_id'] ?? 0);
            $limit = (int) ($terms['limit_minor'] ?? -1);
            $eligible = (int) ($terms['eligible_minor'] ?? -1);
            $version = $terms['policy_version'] ?? null;
            $authorization = $terms['authorization_reference'] ?? null;
            if ($profileId < 1 || $limit < 0 || $eligible < 0 || ! is_string($version) || $version === ''
                || strlen($version) > 64 || ! is_string($authorization) || $authorization === ''
                || strlen($authorization) > 128 || hash_equals($customerProof, $authorization)) {
                throw new PosException('reward_authorization_invalid', 403);
            }
            if (! MarketingProfile::query()->whereKey($profileId)->where('tenant_id', $tenant)->exists()) {
                throw new PosException('customer_not_found', 404);
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
            if ($sale->state !== 'quoted' || $sale->attempt_id !== null || $sale->descriptor_issued_at !== null) {
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
