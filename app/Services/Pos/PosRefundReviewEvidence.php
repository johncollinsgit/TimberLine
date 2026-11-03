<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\PaymentJournal;

/** Compares fresh provider coverage with all durable local refund evidence. */
final class PosRefundReviewEvidence
{
    public function __construct(private PosStore $store, private PaymentJournal $journal, private PosRefundCoverage $square,
        private PosCustomerPurchases $purchases) {}

    public function inspect(object $sale): array
    {
        $this->store->outsideTransaction();
        $snapshot = app(PosReceiptCustomers::class)->decorate($sale, app(PosService::class)->snapshot($sale));
        $customer = $snapshot['customer']['id'] ?? null;
        if (! is_int($customer) || $customer < 1 || ($snapshot['customer_rewards']['enabled'] ?? false) !== true
            || ($snapshot['customer_rewards']['historical_review_required'] ?? false) === true) {
            throw new PosException('refund_allocation_requires_review', 422);
        }
        $localRevision = $this->purchases->refundRevision($sale);
        $coverage = $this->square->verify($sale);
        $payment = $this->journal->get((int) $sale->tenant_id, $sale->attempt_id);
        $local = [];
        $record = function (string $id, int $amount) use (&$local): void {
            if ($id === '' || $amount < 1 || (isset($local[$id]) && $local[$id] !== $amount)) {
                throw new PosException('square_refund_coverage_requires_review', 503);
            }
            $local[$id] = $amount;
        };
        $verifyOperation = function (string $id) use ($sale, $payment, $record): void {
            $refund = $this->journal->get((int) $sale->tenant_id, $id);
            if (($refund['tenant_id'] ?? null) !== (int) $sale->tenant_id || ($refund['kind'] ?? null) !== 'refund'
                || ($refund['state'] ?? null) !== 'succeeded' || ($refund['original_id'] ?? null) !== $sale->attempt_id
                || ($refund['original_provider_id'] ?? null) !== $payment['provider_id'] || ($refund['currency'] ?? null) !== 'usd'
                || ! is_string($refund['provider_id'] ?? null) || ! is_int($refund['amount_minor'] ?? null)
                || Contract::hash($refund['binding'] ?? []) !== Contract::hash($payment['binding'] ?? [])) {
                throw new PosException('square_refund_coverage_requires_review', 503);
            }
            $record($refund['provider_id'], $refund['amount_minor']);
        };
        foreach ($this->store->db->table('payment_core_operations')->where('tenant_id', $sale->tenant_id)
            ->where('original_id', $sale->attempt_id)->where('kind', 'refund')->where('state', 'succeeded')->pluck('id') as $id) {
            $verifyOperation((string) $id);
        }
        foreach ($this->store->rows('events', (int) $sale->tenant_id)->where('sale_id', $sale->id)
            ->whereIn('code', ['customer_refund_confirmed', 'square_refund_observed'])->get() as $event) {
            $details = $this->store->decrypt($event->details);
            if ($event->code === 'customer_refund_confirmed') {
                if (! is_string($details['operation_id'] ?? null)) {
                    throw new PosException('square_refund_coverage_requires_review', 503);
                }
                $verifyOperation($details['operation_id']);
            } else {
                if (($details['payment_id'] ?? null) !== $payment['provider_id'] || ! is_string($details['refund_id'] ?? null)
                    || ! is_int($details['amount_minor'] ?? null)) {
                    throw new PosException('square_refund_coverage_requires_review', 503);
                }
                $record($details['refund_id'], $details['amount_minor']);
            }
        }
        ksort($local);
        if ($local !== $coverage['refunds'] || ! hash_equals($localRevision, $this->purchases->refundRevision($sale))
            || $coverage['refunded_minor'] >= (int) $sale->amount_minor) {
            throw new PosException('square_refund_coverage_requires_review', 503);
        }

        $contextHash = self::rewardContextHash($snapshot);

        return ['snapshot' => $snapshot, 'profile_id' => $customer, 'reward_context_hash' => $contextHash, 'refunded_minor' => $coverage['refunded_minor'],
            'local_revision' => $localRevision, 'coverage_fingerprint' => $coverage['fingerprint'],
            'review_version' => Contract::hash([$sale->snapshot_hash, $contextHash, $customer, $localRevision, $coverage['fingerprint']])];
    }

    public static function rewardContextHash(array $snapshot): string
    {
        return Contract::hash([$snapshot['customer']['id'] ?? null, $snapshot['customer_rewards'] ?? null]);
    }
}
