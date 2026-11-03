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

    public function beginNativePreparation(int $tenant, string $saleId, ?array $rewardTerms = null): object
    {
        if (! app(\App\Services\PaymentsCore\PaymentJournal::class) instanceof \App\Services\PaymentsCore\NativeAtomicPaymentJournal) {
            throw new PosException('native_atomic_handoff_unavailable', 503);
        }

        return $this->claim($tenant, $saleId, 'preparing_native_v1', true, $rewardTerms);
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
            $cancelPreparing = $existing && $requested === 'cancelled' && $existing->state === 'preparing_native_v1';
            if ($existing) {
                if ($existing->payment_request_id !== $sale->payment_request_id) {
                    throw new PosException('payment_claim_mismatch');
                }
                if ($existing->state !== $requested && ! $cancelPreparing) {
                    throw new PosException('payment_claim_conflict');
                }
                if ($requireNew && ! $cancelPreparing) {
                    throw new PosException('payment_recovery_required');
                }
                if (! $cancelPreparing) {
                    return $existing;
                }
            }
            if ($sale->state !== 'quoted' || $sale->attempt_id || $sale->descriptor_issued_at || $sale->source_reference
                || ($requested !== 'cancelled' && strtotime($sale->expires_at) <= time())
                || (($requested === 'preparing_native_v1' || $cancelPreparing) && $sale->commerce_source !== StockPosCommerce::VERSION)) {
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
            $id = $existing->id ?? (string) Str::uuid();
            if ($cancelPreparing) {
                (clone $claims)->where('id', $id)->update(['state' => 'cancelled', 'updated_at' => now()]);
            } else {
                $this->store->db->table('pos_payment_start_claims')->insert([
                    'id' => $id, 'runtime_id' => $this->store->runtime(), 'tenant_id' => $tenant, 'sale_id' => $saleId,
                    'payment_request_id' => $sale->payment_request_id, 'state' => $requested, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
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
