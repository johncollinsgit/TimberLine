<?php

declare(strict_types=1);

namespace App\Services\Pos;

use Illuminate\Support\Str;

/**
 * Unwired durable start/cancel fence. Every collection path must adopt this
 * fence before any caller may rely on a cancellation to release value.
 * Claims never expire; an interrupted start is recovered under its original ID.
 */
final class PosPaymentStartClaim
{
    public function __construct(private PosStore $store) {}

    public function start(int $tenant, string $saleId): object
    {
        return $this->claim($tenant, $saleId, 'starting');
    }

    public function cancel(int $tenant, string $saleId): object
    {
        return $this->claim($tenant, $saleId, 'cancelled');
    }

    private function claim(int $tenant, string $saleId, string $requested): object
    {
        if (config('pos_live.durable_start_claims_enabled', false) !== true) {
            throw new PosException('durable_start_claims_disabled', 403);
        }

        return $this->store->db->transaction(function () use ($tenant, $saleId, $requested): object {
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
            $id = (string) Str::uuid();
            $this->store->db->table('pos_payment_start_claims')->insert([
                'id' => $id, 'runtime_id' => $this->store->runtime(), 'tenant_id' => $tenant, 'sale_id' => $saleId,
                'payment_request_id' => $sale->payment_request_id, 'state' => $requested, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($requested === 'cancelled') {
                $this->store->rows('sales', $tenant)->where('id', $saleId)->update(['state' => 'cancelled']);
            }

            return (clone $claims)->where('id', $id)->first();
        }, 3);
    }
}
