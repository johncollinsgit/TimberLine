<?php

declare(strict_types=1);

namespace App\Services\Pos;

use Illuminate\Support\Str;

/**
 * Durable start/cancel fence. Claims never expire; an interrupted start is
 * recovered under its original ID and can never issue a replacement descriptor.
 */
final class PosPaymentStartClaim
{
    public function __construct(private PosStore $store) {}

    public function cancel(int $tenant, string $saleId): object
    {
        return $this->claim($tenant, $saleId, 'cancelled');
    }

    /** Only the caller that creates the claim may proceed to payment creation. */
    public function beginCollection(int $tenant, string $saleId, ?array $rewardTerms = null): object
    {
        return $this->claim($tenant, $saleId, 'starting', true, $rewardTerms);
    }

    private function claim(int $tenant, string $saleId, string $requested, bool $requireNew = false, ?array $rewardTerms = null): object
    {
        if (config('pos_live.durable_start_claims_enabled', false) !== true) {
            throw new PosException('durable_start_claims_disabled', 403);
        }

        return $this->store->db->transaction(function () use ($tenant, $saleId, $requested, $requireNew, $rewardTerms): object {
            $sale = $this->store->rows('sales', $tenant)->where('id', $saleId)->lockForUpdate()->first();
            if (! $sale) {
                throw new PosException('sale_not_found', 404);
            }
            $claims = $this->store->db->table('pos_payment_start_claims')->where('runtime_id', $this->store->runtime())
                ->where('tenant_id', $tenant)->where('sale_id', $saleId);
            $existing = (clone $claims)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->payment_request_id !== $sale->payment_request_id) {
                    throw new PosException('payment_claim_mismatch');
                }
                if ($existing->state !== $requested) {
                    throw new PosException('payment_claim_conflict');
                }
                if ($requireNew) {
                    throw new PosException('payment_recovery_required');
                }

                return $existing;
            }
            if ($sale->state !== 'quoted' || $sale->attempt_id || $sale->descriptor_issued_at || $sale->source_reference
                || ($requested === 'starting' && strtotime($sale->expires_at) <= time())) {
                throw new PosException('payment_claim_unavailable');
            }
            // This direct read is inside the same DB transaction. No journal
            // operation or provider/network call is performed while locked.
            if ($this->store->db->table('payment_core_operations')->where('tenant_id', $tenant)
                ->where('request_id', $sale->payment_request_id)->lockForUpdate()->exists()) {
                throw new PosException('payment_recovery_required');
            }
            if ($rewardTerms !== null) {
                $hold = $this->store->db->table('pos_candle_cash_reservations')->where('runtime_id', $this->store->runtime())
                    ->where('tenant_id', $tenant)->where('sale_id', $saleId)->lockForUpdate()->first();
                if (! $hold || $hold->state !== 'held' || (int) $hold->marketing_profile_id !== ($rewardTerms['profile_id'] ?? null)
                    || (int) $hold->amount_minor !== ($rewardTerms['amount_minor'] ?? null)
                    || $hold->policy_version !== ($rewardTerms['policy_version'] ?? null)) {
                    throw new PosException('reward_reservation_mismatch');
                }
                $authorization = $this->store->db->table('pos_reward_authorizations')->where('runtime_id', $this->store->runtime())
                    ->where('tenant_id', $tenant)->where('id', $hold->authorization_reference)->where('sale_id', $saleId)->lockForUpdate()->first();
                $profile = \App\Models\MarketingProfile::query()->forTenantId($tenant)->whereKey($hold->marketing_profile_id)
                    ->lockForUpdate()->first();
                if (! $authorization || ! $profile || (int) $authorization->marketing_profile_id !== (int) $profile->id
                    || ! app(PosVerifiedCustomerAccount::class)->verified($profile, (int) $authorization->account_link_id)) {
                    throw new PosException('customer_identity_review_required', 409);
                }
            }
            $id = (string) Str::uuid();
            $this->store->db->table('pos_payment_start_claims')->insert([
                'id' => $id, 'runtime_id' => $this->store->runtime(), 'tenant_id' => $tenant, 'sale_id' => $saleId,
                'payment_request_id' => $sale->payment_request_id, 'state' => $requested, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($requested === 'cancelled') {
                $this->store->rows('sales', $tenant)->where('id', $saleId)->update(['state' => 'cancelled']);
                if ($this->store->db->table('pos_candle_cash_reservations')->where('runtime_id', $this->store->runtime())
                    ->where('tenant_id', $tenant)->where('sale_id', $saleId)->lockForUpdate()->exists()) {
                    // The outbox survives a crash after cancellation and retries
                    // the original hold rather than guessing payment outcome.
                    $this->store->rows('outbox', $tenant)->insertOrIgnore($this->store->values($tenant, [
                        'id' => bin2hex(random_bytes(16)), 'sale_id' => $saleId, 'effect_key' => 'reward_unstarted_release',
                        'payload' => $this->store->encrypt([]), 'state' => 'pending', 'available_at' => now(),
                    ]));
                }
            }

            return (clone $claims)->where('id', $id)->first();
        }, 3);
    }
}
