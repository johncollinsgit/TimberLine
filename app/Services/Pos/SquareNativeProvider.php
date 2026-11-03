<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentProvider;
use App\Services\PaymentsCore\ProviderEvidence;
use Illuminate\Support\Facades\Cache;

/** SDK results are hints only. Canonical evidence always comes from Square. */
final class SquareNativeProvider implements PaymentProvider
{
    public function __construct(private PosStore $store, private SquareClient $square) {}

    public function supports(MerchantBinding $binding): bool
    {
        return $binding->provider === 'square_native' && $binding->runtimeId === $this->store->runtime()
            && in_array($binding->environment, ['test', 'live'], true);
    }

    public function createPayment(array $reservedAttempt): ProviderEvidence
    {
        // Native collection has not happened. Preserve the committed claim; do
        // not invent a temporary canonical payment ID or contact a card API.
        throw new PosException('native_collection_required');
    }

    public function findPayment(array $operation): ?ProviderEvidence
    {
        return $this->serialized($operation, function (object $sale, object $connection) use ($operation): ?array {
            $hint = $sale->payment_hint ? $this->store->decrypt($sale->payment_hint)['payment_id'] ?? null : null;
            if ($operation['provider_id'] || $hint) {
                $id = $operation['provider_id'] ?: $hint;
            } else {
                $matches = $this->scan($connection, 'payments', $operation, $sale);
                if (count($matches) !== 1) {
                    if (count($matches) > 1) {
                        throw new PosException('multiple_square_payments');
                    }

                    return null;
                }
                $id = $matches[0]['id'];
            }
            // List output and native callbacks are never the canonical snapshot.
            $payment = $this->square->get($connection, '/v2/payments/'.rawurlencode($id))['payment'] ?? [];
            if (($payment['reference_id'] ?? null) !== $operation['request_id']
                || ($payment['location_id'] ?? null) !== $connection->provider_location_id
                || ($payment['amount_money']['amount'] ?? null) !== $operation['amount_minor']
                || ($payment['amount_money']['currency'] ?? null) !== strtoupper($operation['currency'])
                || ($payment['source_type'] ?? null) !== 'CARD'
                || (int) ($payment['tip_money']['amount'] ?? 0) !== 0
                || (int) ($payment['app_fee_money']['amount'] ?? 0) !== 0
                || ($payment['total_money']['amount'] ?? null) !== $operation['amount_minor']) {
                throw new PosException('square_payment_identity_mismatch');
            }
            $state = match ($payment['status'] ?? '') {
                'COMPLETED' => 'succeeded', 'FAILED' => 'failed', 'CANCELED' => 'cancelled',
                'APPROVED', 'PENDING' => 'processing', default => throw new PosException('square_status_unrecognized'),
            };

            if (($payment['status'] ?? null) === 'COMPLETED') {
                app(PosSquareRefundObserver::class)->observe($sale, $connection, $payment);
                app(PosReceiptDetails::class)->observe($sale, $payment);
            }
            if (! empty($payment['order_id'])) {
                $this->store->rows('sales', (int) $sale->tenant_id)->where('id', $sale->id)->update(['provider_order_id' => $payment['order_id']]);
            }

            return ['id' => $payment['id'], 'state' => $state];
        });
    }

    public function createRefund(array $refund): ProviderEvidence
    {
        [$sale, $connection] = $this->context($refund);
        $response = $this->square->post($connection, '/v2/refunds', [
            'idempotency_key' => $refund['request_id'], 'payment_id' => $refund['original_provider_id'],
            'amount_money' => ['amount' => $refund['amount_minor'], 'currency' => strtoupper($refund['currency'])],
            'reason' => 'Everbranch '.$refund['request_id'],
        ]);
        if (! isset($response['refund']['id'])) {
            throw new PosException('refund_unconfirmed', 503);
        }

        return $this->findRefund($refund) ?? throw new PosException('refund_unconfirmed', 503);
    }

    public function audit(array $operation): void
    {
        $fresh = $operation['kind'] === 'payment' ? $this->findPayment($operation) : $this->findRefund($operation);
        if (! $fresh || $fresh->providerId !== $operation['provider_id'] || $fresh->state !== $operation['state']) {
            throw new PosException('original_provider_audit_mismatch');
        }
    }

    public function findRefund(array $refund): ?ProviderEvidence
    {
        return $this->serialized($refund, function (object $sale, object $connection) use ($refund): ?array {
            if ($refund['provider_id']) {
                $id = $refund['provider_id'];
            } else {
                $matches = $this->scan($connection, 'refunds', $refund, $sale);
                if (count($matches) !== 1) {
                    if (count($matches) > 1) {
                        throw new PosException('multiple_square_refunds');
                    }

                    return null;
                }
                $id = $matches[0]['id'];
            }
            $value = $this->square->get($connection, '/v2/refunds/'.rawurlencode($id))['refund'] ?? [];
            if (($value['payment_id'] ?? null) !== $refund['original_provider_id']
                || ($value['location_id'] ?? null) !== $connection->provider_location_id
                || ($value['reason'] ?? null) !== 'Everbranch '.$refund['request_id']
                || ($value['amount_money']['amount'] ?? null) !== $refund['amount_minor']
                || ($value['amount_money']['currency'] ?? null) !== strtoupper($refund['currency'])) {
                throw new PosException('square_refund_identity_mismatch');
            }
            $state = match ($value['status'] ?? '') {
                'COMPLETED' => 'succeeded', 'REJECTED', 'FAILED' => 'failed', 'PENDING' => 'processing',
                default => throw new PosException('square_refund_status_unrecognized'),
            };

            return ['id' => $value['id'], 'state' => $state];
        });
    }

    private function scan(object $connection, string $kind, array $operation, object $sale): array
    {
        $cursor = null;
        $matches = [];
        for ($page = 0; $page < 5; $page++) {
            $query = ['location_id' => $connection->provider_location_id,
                'begin_time' => gmdate('Y-m-d\TH:i:s\Z', strtotime($sale->created_at) - 300), 'limit' => 100];
            if ($cursor) {
                $query['cursor'] = $cursor;
            }
            $result = $this->square->get($connection, '/v2/'.$kind, $query);
            foreach ($result[$kind] ?? [] as $item) {
                $match = $kind === 'payments' ? ($item['reference_id'] ?? null) === $operation['request_id']
                    : ($item['payment_id'] ?? null) === $operation['original_provider_id'] && ($item['reason'] ?? null) === 'Everbranch '.$operation['request_id'];
                if ($match) {
                    $matches[$item['id']] = $item;
                }
            }
            $cursor = $result['cursor'] ?? null;
            if (! $cursor) {
                return array_values($matches);
            }
        }
        throw new PosException('square_search_incomplete', 503);
    }

    private function context(array $operation): array
    {
        $binding = MerchantBinding::restore($operation['binding']);
        if (! $this->supports($binding)) {
            throw new PosException('square_binding_invalid');
        }
        $sale = $this->store->rows('sales', $binding->tenantId)->where('id', $operation['sale_id'])->first();
        if (! $sale) {
            throw new PosException('sale_unavailable', 404);
        }
        $snapshot = $this->store->decrypt($sale->snapshot);
        $connection = $this->square->connection($binding->tenantId, $snapshot['connection_id']);
        if ($connection->merchant_id !== $binding->merchantAccount || $connection->provider_location_id !== $binding->locationId
            || ($connection->environment === 'production' ? 'live' : 'test') !== $binding->environment) {
            throw new PosException('original_merchant_mismatch');
        }

        return [$sale, $connection];
    }

    private function serialized(array $operation, callable $read): ?ProviderEvidence
    {
        $this->store->outsideTransaction();
        // Square's version token is opaque. Serialize fresh retrieval and assign
        // a durable local evidence sequence instead of sorting event timestamps.
        $key = 'pos-square-'.hash('sha256', $this->store->runtime().':'.$operation['tenant_id'].':'.$operation['sale_id']);

        return Cache::lock($key, 180)->block(5, function () use ($operation, $read): ?ProviderEvidence {
            [$sale, $connection] = $this->context($operation);
            $result = $read($sale, $connection);
            if ($result === null) {
                return null;
            }
            $version = $this->store->db->transaction(function () use ($sale): int {
                $query = $this->store->rows('sales', (int) $sale->tenant_id)->where('id', $sale->id);
                $version = (int) $query->lockForUpdate()->firstOrFail()->evidence_version + 1;
                $query->update(['evidence_version' => $version]);

                return $version;
            });

            return new ProviderEvidence($operation['request_id'], MerchantBinding::restore($operation['binding'])->fingerprint(),
                $result['id'], $operation['amount_minor'], $operation['currency'], $result['state'], $version, $operation['original_provider_id']);
        });
    }
}
