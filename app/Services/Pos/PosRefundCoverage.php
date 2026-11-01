<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentJournal;

/** Fresh Square coverage only; this service never records a refund or moves rewards. */
final class PosRefundCoverage
{
    public function __construct(private PosStore $store, private PaymentJournal $journal, private SquareClient $square) {}

    /** @return array{refunded_minor:int,refunds:array<string,int>,fingerprint:string} */
    public function verify(object $sale): array
    {
        $this->store->outsideTransaction();
        $apiVersion = config('pos_live.square_api_version');
        if (! is_string($apiVersion) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $apiVersion) || $apiVersion < '2024-08-21'
            || $sale->state !== 'succeeded' || ! is_string($sale->attempt_id) || $sale->attempt_id === '') {
            throw new PosException('square_refund_coverage_requires_review', 503);
        }
        $snapshot = app(PosService::class)->snapshot($sale);
        $binding = MerchantBinding::restore($snapshot['binding']);
        $operation = $this->journal->get((int) $sale->tenant_id, $sale->attempt_id);
        if ($binding->provider !== 'square_native' || $binding->environment !== 'live'
            || ($operation['kind'] ?? null) !== 'payment' || ($operation['state'] ?? null) !== 'succeeded'
            || ($operation['sale_id'] ?? null) !== $sale->id || ($operation['request_id'] ?? null) !== $sale->payment_request_id
            || ($operation['amount_minor'] ?? null) !== (int) $sale->amount_minor || ($operation['currency'] ?? null) !== 'usd'
            || ! is_string($operation['provider_id'] ?? null) || ! preg_match('/^[A-Za-z0-9_-]{1,192}$/D', $operation['provider_id'])
            || Contract::hash($operation['binding'] ?? []) !== Contract::hash($snapshot['binding'])) {
            throw new PosException('square_refund_coverage_requires_review', 503);
        }
        $connection = $this->square->connection((int) $sale->tenant_id, $snapshot['connection_id']);
        if ($connection->environment !== 'production' || $connection->merchant_id !== $binding->merchantAccount
            || $connection->provider_location_id !== $binding->locationId) {
            throw new PosException('square_refund_coverage_requires_review', 503);
        }
        $path = '/v2/payments/'.rawurlencode($operation['provider_id']);
        $first = $this->payment($this->square->get($connection, $path), $operation, $connection);
        $refunds = [];
        foreach ($first['ids'] as $id) {
            $response = $this->square->get($connection, '/v2/refunds/'.rawurlencode($id));
            $value = $response['refund'] ?? null;
            if (! empty($response['errors']) || ! is_array($value) || ($value['id'] ?? null) !== $id || ($value['payment_id'] ?? null) !== $operation['provider_id']
                || ($value['location_id'] ?? null) !== $connection->provider_location_id || ($value['status'] ?? null) !== 'COMPLETED'
                || ($value['amount_money']['currency'] ?? null) !== 'USD' || ! is_int($value['amount_money']['amount'] ?? null)
                || $value['amount_money']['amount'] < 1 || $value['amount_money']['amount'] > $sale->amount_minor) {
                throw new PosException('square_refund_coverage_requires_review', 503);
            }
            $refunds[$id] = $value['amount_money']['amount'];
        }
        ksort($refunds);
        $second = $this->payment($this->square->get($connection, $path), $operation, $connection);
        if ($first !== $second || array_sum($refunds) !== $first['refunded_minor'] || $first['refunded_minor'] > $sale->amount_minor) {
            throw new PosException('square_refund_coverage_requires_review', 503);
        }

        return ['refunded_minor' => $first['refunded_minor'], 'refunds' => $refunds,
            'fingerprint' => Contract::hash([$operation['provider_id'], $operation['request_id'], $refunds, $first['refunded_minor']])];
    }

    private function payment(array $response, array $operation, object $connection): array
    {
        $payment = $response['payment'] ?? null;
        if (! empty($response['errors']) || ! is_array($payment) || ($payment['id'] ?? null) !== $operation['provider_id']
            || ($payment['reference_id'] ?? null) !== $operation['request_id'] || ($payment['location_id'] ?? null) !== $connection->provider_location_id
            || ($payment['status'] ?? null) !== 'COMPLETED' || ($payment['source_type'] ?? null) !== 'CARD'
            || ($payment['amount_money']['amount'] ?? null) !== $operation['amount_minor'] || ($payment['amount_money']['currency'] ?? null) !== 'USD'
            || ($payment['total_money']['amount'] ?? null) !== $operation['amount_minor'] || ($payment['total_money']['currency'] ?? null) !== 'USD'
            || (int) ($payment['tip_money']['amount'] ?? 0) !== 0 || (int) ($payment['app_fee_money']['amount'] ?? 0) !== 0
            || ($payment['refunded_money']['currency'] ?? null) !== 'USD' || ! is_int($payment['refunded_money']['amount'] ?? null)
            || $payment['refunded_money']['amount'] < 1 || ! is_array($payment['refund_ids'] ?? null)
            || ! array_is_list($payment['refund_ids']) || count($payment['refund_ids']) < 1 || count($payment['refund_ids']) > 100) {
            throw new PosException('square_refund_coverage_requires_review', 503);
        }
        $ids = $payment['refund_ids'];
        foreach ($ids as $id) {
            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,192}$/D', $id)) {
                throw new PosException('square_refund_coverage_requires_review', 503);
            }
        }
        if (count($ids) !== count(array_unique($ids))) {
            throw new PosException('square_refund_coverage_requires_review', 503);
        }
        sort($ids);

        return ['ids' => $ids, 'refunded_minor' => $payment['refunded_money']['amount']];
    }
}
