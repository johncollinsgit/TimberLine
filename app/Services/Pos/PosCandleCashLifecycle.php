<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\PaymentJournal;
use Illuminate\Support\Facades\Cache;

/**
 * Internal lifecycle only; no controller, provider or outbox hook is installed.
 * Journal retrieval is outside wallet transactions and all money moves once.
 */
final class PosCandleCashLifecycle
{
    // A cache lease is not a durable start/cancel fence. Never enable this until
    // every collection path enforces PosPaymentStartClaim end to end.
    private const UNSTARTED_RELEASE_READY = false;

    public function __construct(private PosStore $store, private PaymentJournal $journal, private PosService $sales,
        private PosCustomerPurchases $purchases) {}

    /** A verified terminal operation changes the hold; uncertain operations keep it. */
    public function reconcile(int $tenant, string $saleId): string
    {
        $sale = $this->store->rows('sales', $tenant)->where('id', $saleId)->firstOrFail();
        if (! $sale->attempt_id) {
            return 'held';
        }
        $snapshot = $this->sales->snapshot($sale);
        $operation = $this->journal->get($tenant, $sale->attempt_id);
        $this->assertPayment($sale, $snapshot, $operation);
        if (! in_array($operation['state'], ['succeeded', 'failed', 'cancelled'], true)) {
            return 'held';
        }
        if (! is_string($operation['provider_id'] ?? null) || $operation['provider_id'] === '') {
            throw new PosException('reward_payment_evidence_required', 409);
        }

        return $this->store->db->transaction(function () use ($tenant, $saleId, $snapshot, $operation): string {
            $current = $this->store->rows('sales', $tenant)->where('id', $saleId)->lockForUpdate()->firstOrFail();
            if ($current->attempt_id !== $operation['id'] || $current->state !== $operation['state']) {
                throw new PosException('reward_payment_evidence_changed');
            }
            $hold = $this->lockedHold($current, $snapshot);
            if ($operation['state'] === 'succeeded') {
                if ($hold->state === 'held') {
                    $this->setState($hold->id, 'spent');

                    return 'spent';
                }
                if (in_array($hold->state, ['spent', 'refunded'], true)) {
                    return $hold->state;
                }
                throw new PosException('reward_lifecycle_review_required');
            }
            if ($hold->state === 'held') {
                $this->credit($hold, 'pos_reservation_release', 'Released uncollected in-person reward');
                $this->setState($hold->id, 'released');

                return 'released';
            }
            if ($hold->state === 'released') {
                return 'released';
            }
            throw new PosException('reward_lifecycle_review_required');
        }, 3);
    }

    /** The same register lock as collection excludes a new descriptor while checking the journal. */
    public function releaseUnstarted(int $tenant, string $saleId): string
    {
        if (! self::UNSTARTED_RELEASE_READY) {
            throw new PosException('reward_unstarted_release_not_ready', 503);
        }
        $sale = $this->store->rows('sales', $tenant)->where('id', $saleId)->firstOrFail();
        $key = 'pos-start-'.hash('sha256', $this->store->runtime().':'.$tenant.':'.$sale->register_id);

        return Cache::lock($key, 180)->block(5, function () use ($tenant, $saleId): string {
            $sale = $this->store->rows('sales', $tenant)->where('id', $saleId)->firstOrFail();
            $snapshot = $this->sales->snapshot($sale);
            if ($sale->state !== 'cancelled' || $sale->attempt_id || $sale->descriptor_issued_at || $sale->source_reference
                || $this->journal->findByRequest($tenant, $sale->payment_request_id)) {
                throw new PosException('reward_payment_uncertain', 409);
            }

            return $this->store->db->transaction(function () use ($tenant, $saleId, $snapshot): string {
                $current = $this->store->rows('sales', $tenant)->where('id', $saleId)->lockForUpdate()->firstOrFail();
                if ($current->state !== 'cancelled' || $current->attempt_id || $current->descriptor_issued_at || $current->source_reference) {
                    throw new PosException('reward_payment_uncertain', 409);
                }
                $hold = $this->lockedHold($current, $snapshot);
                if ($hold->state === 'held') {
                    $this->credit($hold, 'pos_reservation_release', 'Released unstarted in-person reward');
                    $this->setState($hold->id, 'released');

                    return 'released';
                }
                if ($hold->state === 'released') {
                    return 'released';
                }
                throw new PosException('reward_lifecycle_review_required');
            }, 3);
        });
    }

    /** Full verified refunds restore the held credit; amount-only partial refunds require review. */
    public function reconcileRefund(int $tenant, string $saleId): string
    {
        $sale = $this->store->rows('sales', $tenant)->where('id', $saleId)->firstOrFail();
        if (! $sale->attempt_id) {
            throw new PosException('reward_payment_evidence_required');
        }
        $snapshot = $this->sales->snapshot($sale);
        $payment = $this->journal->get($tenant, $sale->attempt_id);
        $this->assertPayment($sale, $snapshot, $payment);
        if ($payment['state'] !== 'succeeded' || empty($payment['provider_id'])) {
            throw new PosException('reward_payment_evidence_required');
        }
        $revision = $this->purchases->refundRevision($sale);
        $refunded = $this->purchases->confirmedRefunds($sale);
        if (! hash_equals($revision, $this->purchases->refundRevision($sale))) {
            throw new PosException('customer_refund_evidence_changed');
        }

        return $this->store->db->transaction(function () use ($tenant, $saleId, $snapshot, $payment, $revision, $refunded): string {
            $current = $this->store->rows('sales', $tenant)->where('id', $saleId)->lockForUpdate()->firstOrFail();
            if ($current->state !== 'succeeded' || $current->attempt_id !== $payment['id']
                || ! hash_equals($revision, $this->purchases->refundRevision($current))) {
                throw new PosException('customer_refund_evidence_changed');
            }
            $hold = $this->lockedHold($current, $snapshot);
            if (! in_array($hold->state, ['held', 'spent', 'refunded'], true)) {
                throw new PosException('reward_lifecycle_review_required');
            }
            if ($hold->state === 'refunded') {
                return 'refunded';
            }
            if ($refunded === (int) $current->amount_minor) {
                $this->credit($hold, 'pos_reservation_refund', 'Restored in-person reward after full payment refund');
                $this->setState($hold->id, 'refunded');

                return 'refunded';
            }
            if ($hold->state === 'held') {
                $this->setState($hold->id, 'spent');
            }

            return $refunded > 0 ? 'partial_refund_review' : 'spent';
        }, 3);
    }

    private function assertPayment(object $sale, array $snapshot, array $operation): void
    {
        if ($operation['kind'] !== 'payment' || (int) $operation['tenant_id'] !== (int) $sale->tenant_id
            || $operation['id'] !== $sale->attempt_id || $operation['sale_id'] !== $sale->id
            || $operation['request_id'] !== $sale->payment_request_id || (int) $operation['amount_minor'] !== (int) $sale->amount_minor
            || ($operation['currency'] ?? null) !== strtolower($sale->currency)
            || Contract::hash($operation['binding']) !== Contract::hash($snapshot['binding'])
            || ($operation['binding']['environment'] ?? null) !== 'live') {
            throw new PosException('reward_payment_evidence_required', 409);
        }
    }

    private function lockedHold(object $sale, array $snapshot): object
    {
        $hold = $this->store->db->table('pos_candle_cash_reservations')->where('runtime_id', $this->store->runtime())
            ->where('tenant_id', $sale->tenant_id)->where('sale_id', $sale->id)->lockForUpdate()->first();
        $terms = $snapshot['terms']['pos_candle_cash'] ?? [];
        if (! $hold || (int) $hold->marketing_profile_id !== ($terms['profile_id'] ?? null)
            || (int) $hold->amount_minor !== ($terms['amount_minor'] ?? null)
            || $hold->policy_version !== ($terms['policy_version'] ?? null)
            || ($terms['refund_policy'] ?? null) !== 'restore_full_review_partial') {
            throw new PosException('reward_reservation_mismatch');
        }

        return $hold;
    }

    private function credit(object $hold, string $source, string $description): void
    {
        $balance = CandleCashBalance::query()->whereKey($hold->marketing_profile_id)->lockForUpdate()->firstOrFail();
        $amount = (int) $hold->amount_minor / 100;
        $balance->balance = $balance->balance + $amount;
        $balance->save();
        CandleCashTransaction::query()->create(['marketing_profile_id' => $hold->marketing_profile_id,
            'type' => 'adjustment', 'points' => (int) round($amount), 'candle_cash_delta' => $amount,
            'source' => $source, 'source_id' => $hold->id, 'description' => $description]);
    }

    private function setState(string $id, string $state): void
    {
        $this->store->db->table('pos_candle_cash_reservations')->where('id', $id)->update(['state' => $state, 'updated_at' => now()]);
    }
}
