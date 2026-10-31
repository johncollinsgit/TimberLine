<?php

declare(strict_types=1);

namespace App\Services\Pos;

/** Also reconciles refunds made in Square Dashboard; never initiates a refund. */
final class PosSquareRefundObserver
{
    public function __construct(private PosStore $store, private SquareClient $square) {}

    public function observe(object $sale, object $connection, array $payment): void
    {
        $ids = $payment['refund_ids'] ?? [];
        if (! is_array($ids) || count($ids) > 100) {
            throw new PosException('square_refund_coverage_requires_review');
        }
        foreach (array_unique($ids) as $id) {
            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,192}$/D', $id)) {
                throw new PosException('square_refund_identity_mismatch');
            }
            $refund = $this->square->get($connection, '/v2/refunds/'.rawurlencode($id))['refund'] ?? [];
            if (($refund['id'] ?? null) !== $id || ($refund['payment_id'] ?? null) !== $payment['id']
                || ($refund['location_id'] ?? null) !== $connection->provider_location_id
                || ($refund['amount_money']['currency'] ?? null) !== 'USD'
                || ! is_int($refund['amount_money']['amount'] ?? null) || $refund['amount_money']['amount'] < 1
                || $refund['amount_money']['amount'] > $sale->amount_minor) {
                throw new PosException('square_refund_identity_mismatch');
            }
            if (($refund['status'] ?? null) !== 'COMPLETED') {
                continue;
            }
            $details = ['refund_id' => $id, 'payment_id' => $payment['id'], 'amount_minor' => $refund['amount_money']['amount']];
            $this->store->db->transaction(function () use ($sale, $id, $details): void {
                $tenant = (int) $sale->tenant_id;
                $this->store->rows('sales', $tenant)->where('id', $sale->id)->lockForUpdate()->firstOrFail();
                $prior = $this->store->rows('events', $tenant)->where('sale_id', $sale->id)->where('code', 'square_refund_observed')
                    ->get()->map(fn ($e) => $this->store->decrypt($e->details))->firstWhere('refund_id', $id);
                if ($prior !== null && $prior !== $details) {
                    throw new PosException('square_refund_evidence_changed');
                }
                if ($prior === null) {
                    $this->store->event($tenant, $sale->id, 'square_refund_observed', $details);
                }
                app(PosService::class)->enqueue($tenant, $sale->id, 'customer_refunds_'.substr(hash('sha256', $id), 0, 40), []);
            }, 3);
        }
    }
}
