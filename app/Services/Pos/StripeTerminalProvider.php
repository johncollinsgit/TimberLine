<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentProvider;
use App\Services\PaymentsCore\ProviderEvidence;
use Illuminate\Support\Facades\Cache;

/** One original PaymentIntent; fresh retrieval is the only canonical payment evidence. */
final class StripeTerminalProvider implements PaymentProvider
{
    public function __construct(private PosStore $store, private StripeTerminalClient $stripe) {}

    public function supports(MerchantBinding $binding): bool
    {
        return $binding->provider === 'stripe_terminal' && $binding->runtimeId === $this->store->runtime()
            && in_array($binding->environment, ['test', 'live'], true);
    }

    public function reserve(array $scope, object $sale): object
    {
        return $this->store->db->transaction(function () use ($scope, $sale): object {
            $reader = $this->store->rows('readers', $scope['tenant_id'])->where('register_id', $sale->register_id)->lockForUpdate()->first();
            if (! $reader || $reader->connection_id !== $scope['connection']->id) {
                throw new PosException('reader_not_bound');
            }
            if ($this->store->rows('stripe_operations', $scope['tenant_id'])->where('active_reader_claim', $reader->identity_hash)->where('id', '!=', $sale->id)->exists()) {
                throw new PosException('reader_busy', 409);
            }
            $old = $this->store->rows('stripe_operations', $scope['tenant_id'])->where('id', $sale->id)->first();
            if ($old) {
                if (! $old->active_reader_claim) {
                    $this->store->rows('stripe_operations', $scope['tenant_id'])->where('id', $sale->id)->update(['active_reader_claim' => $reader->identity_hash]);
                }

                return $this->store->rows('stripe_operations', $scope['tenant_id'])->where('id', $sale->id)->firstOrFail();
            }
            $payload = ['amount' => (int) $sale->amount_minor, 'currency' => 'usd', 'payment_method_types' => ['card_present'],
                'capture_method' => 'automatic', 'metadata' => ['pos_sale' => $sale->id, 'pos_request' => $sale->payment_request_id,
                    'pos_runtime' => $this->store->runtime(), 'pos_tenant' => (string) $scope['tenant_id'],
                    'pos_location' => $scope['connection']->provider_location_id, 'pos_reader' => $reader->reader_id]];
            $this->store->rows('stripe_operations', $scope['tenant_id'])->insert($this->store->values($scope['tenant_id'], [
                'id' => $sale->id, 'reader_binding_id' => $reader->id, 'connection_id' => $scope['connection']->id,
                'reader_id' => $reader->reader_id, 'request_id' => $sale->payment_request_id,
                'payload' => $this->store->encrypt($payload), 'payload_hash' => Contract::hash($payload),
                'active_reader_claim' => $reader->identity_hash, 'created_at' => now(),
            ]));

            return $this->store->rows('stripe_operations', $scope['tenant_id'])->where('id', $sale->id)->firstOrFail();
        });
    }

    public function releaseUnstarted(object $sale): void
    {
        // Only the caller holding the register start lock can release this reservation.
        if (! $sale->attempt_id && ! app(\App\Services\PaymentsCore\PaymentJournal::class)->findByRequest((int) $sale->tenant_id, $sale->payment_request_id)) {
            $this->store->rows('stripe_operations', (int) $sale->tenant_id)->where('id', $sale->id)->whereNull('payment_id')
                ->where('dispatch_generation', 0)->update(['active_reader_claim' => null]);
        }
    }

    public function createPayment(array $operation): ProviderEvidence
    {
        Cache::lock($this->lock($operation), 180)->block(5, function () use ($operation): void {
            [$sale, $connection, $intent] = $this->context($operation);
            $this->createIntent($connection, $intent);
            $this->dispatch($connection, $this->intent($sale), false);
        });

        return $this->findPayment($operation) ?? throw new PosException('stripe_payment_unknown');
    }

    private function createIntent(object $connection, object $intent): void
    {
        if ($intent->payment_id) {
            return;
        }
        if (strtotime($intent->created_at) <= time() - 23 * 3600) {
            throw new PosException('stripe_creation_review_required');
        }
        $payload = $this->store->decrypt($intent->payload);
        if (! hash_equals($intent->payload_hash, Contract::hash($payload))) {
            throw new PosException('stripe_intent_integrity');
        }
        $value = $this->stripe->request($connection, 'POST', '/v1/payment_intents', $payload, $intent->request_id);
        if (! preg_match('/^pi_[A-Za-z0-9]+$/D', $value['id'] ?? '')) {
            throw new PosException('stripe_payment_unknown');
        }
        $this->validate($connection, $intent, $value);
        $this->store->rows('stripe_operations', (int) $intent->tenant_id)->where('id', $intent->id)->whereNull('payment_id')->update(['payment_id' => $value['id']]);
    }

    private function dispatch(object $connection, object $intent, bool $retry): void
    {
        $reader = $this->stripe->reader($connection, $intent->reader_id);
        $payment = $this->stripe->request($connection, 'GET', '/v1/payment_intents/'.$intent->payment_id);
        $this->validate($connection, $intent, $payment);
        if (($payment['status'] ?? null) !== 'requires_payment_method' || ($reader['status'] ?? null) !== 'online'
            || ($reader['action']['status'] ?? null) === 'in_progress') {
            throw new PosException('reader_or_payment_not_ready');
        }
        if ($retry && (($reader['action']['status'] ?? null) !== 'failed'
            || ($reader['action']['process_payment_intent']['payment_intent'] ?? null) !== $intent->payment_id)) {
            throw new PosException('original_decline_required');
        }
        $generation = (int) $intent->dispatch_generation + 1;
        $claimed = $this->store->rows('stripe_operations', (int) $intent->tenant_id)->where('id', $intent->id)
            ->where('dispatch_generation', $intent->dispatch_generation)->where('reader_state', $retry ? 'declined' : 'reserved')
            ->update(['dispatch_generation' => $generation, 'reader_state' => 'dispatch_unknown']);
        if ($claimed !== 1) {
            throw new PosException('original_reader_recovery_required');
        }
        $this->stripe->request($connection, 'POST', '/v1/terminal/readers/'.$intent->reader_id.'/process_payment_intent',
            ['payment_intent' => $intent->payment_id, 'process_config' => ['skip_tipping' => 'true', 'enable_customer_cancellation' => 'true']],
            'pos-reader-'.$intent->request_id.'-'.$generation);
    }

    public function control(array $operation, string $action): void
    {
        if (! in_array($action, ['cancel', 'retry', 'resume'], true)) {
            throw new PosException('invalid_reader_control');
        }
        Cache::lock($this->lock($operation), 180)->block(5, function () use ($operation, $action): void {
            [$sale, $connection, $intent] = $this->context($operation);
            if ($action === 'resume' && ! $intent->payment_id) {
                $this->createIntent($connection, $intent);
                $intent = $this->intent($sale);
            }
            if (! $intent->payment_id) {
                throw new PosException('original_payment_unknown');
            }
            if ($action === 'cancel') {
                // Cancelling the reader alone never proves the PaymentIntent unpaid.
                $reader = $this->stripe->reader($connection, $intent->reader_id);
                if (($reader['action']['status'] ?? '') === 'in_progress' && ($reader['action']['process_payment_intent']['payment_intent'] ?? '') === $intent->payment_id) {
                    $this->stripe->request($connection, 'POST', '/v1/terminal/readers/'.$intent->reader_id.'/cancel_action', [], 'pos-reader-cancel-'.$intent->request_id);
                }
                $this->stripe->request($connection, 'POST', '/v1/payment_intents/'.$intent->payment_id.'/cancel', [], 'pos-cancel-'.$intent->request_id);
            } else {
                $this->dispatch($connection, $intent, $action === 'retry');
            }
        });
    }

    public function findPayment(array $operation): ?ProviderEvidence
    {
        return $this->serialized($operation, function ($sale, $connection, $intent) use ($operation): ?array {
            if (! $intent->payment_id) {
                $result = $this->stripe->request($connection, 'GET', '/v1/payment_intents/search',
                    ['query' => "metadata['pos_request']:'".$operation['request_id']."'", 'limit' => 100]);
                $matches = $result['data'] ?? [];
                if (($result['has_more'] ?? true) || count($matches) > 1) {
                    throw new PosException('stripe_search_requires_review');
                }
                if (! $matches) {
                    return null;
                }
                $this->validate($connection, $intent, $matches[0]);
                $this->store->rows('stripe_operations', (int) $sale->tenant_id)->where('id', $sale->id)->update(['payment_id' => $matches[0]['id']]);
                $intent = $this->intent($sale);
            }
            $payment = $this->stripe->request($connection, 'GET', '/v1/payment_intents/'.$intent->payment_id, ['expand' => ['latest_charge']]);
            $this->validate($connection, $intent, $payment);
            $status = $payment['status'] ?? '';
            $state = match ($status) {
                'succeeded' => 'succeeded', 'canceled' => 'cancelled',
                'requires_payment_method', 'processing', 'requires_confirmation', 'requires_capture', 'requires_action' => 'processing',
                default => throw new PosException('stripe_payment_status_unknown')
            };
            if ($state === 'succeeded') {
                $charge = $payment['latest_charge'] ?? [];
                if (($payment['amount_received'] ?? null) !== $operation['amount_minor'] || ($charge['payment_intent'] ?? null) !== $intent->payment_id
                    || ($charge['amount'] ?? null) !== $operation['amount_minor'] || ($charge['currency'] ?? null) !== 'usd'
                    || ($charge['paid'] ?? null) !== true || ($charge['captured'] ?? null) !== true
                    || ($charge['payment_method_details']['type'] ?? null) !== 'card_present'
                ) {
                    throw new PosException('stripe_charge_identity_mismatch');
                }
                $receipt = ['provider' => 'stripe_terminal', 'payment_id' => $intent->payment_id,
                    'receipt_url' => is_string($charge['receipt_url'] ?? null) && preg_match('~^https://(?:pay|dashboard)\.stripe\.com/~D', $charge['receipt_url']) ? $charge['receipt_url'] : null, 'card_brand' => $charge['payment_method_details']['card_present']['brand'] ?? null,
                    'card_last4' => $charge['payment_method_details']['card_present']['last4'] ?? null];
                $this->store->rows('stripe_operations', (int) $sale->tenant_id)->where('id', $sale->id)->update(['receipt' => $this->store->encrypt($receipt)]);
            }
            $readerState = 'pending';
            if ($status === 'requires_payment_method') {
                $reader = $this->stripe->reader($connection, $intent->reader_id);
                $same = ($reader['action']['process_payment_intent']['payment_intent'] ?? null) === $intent->payment_id;
                if ($same && ($reader['action']['status'] ?? null) === 'failed') {
                    $readerState = 'declined';
                } elseif ((int) $intent->dispatch_generation === 0) {
                    $readerState = 'reserved';
                }
            }
            $updates = ['reader_state' => $readerState, 'verified_at' => now()];
            if (in_array($state, ['succeeded', 'cancelled'], true)) {
                $updates['active_reader_claim'] = null;
                $updates['reader_state'] = $state;
            }
            $this->store->rows('stripe_operations', (int) $sale->tenant_id)->where('id', $sale->id)->update($updates);

            return ['id' => $intent->payment_id, 'state' => $state];
        });
    }

    private function validate(object $connection, object $intent, array $payment): void
    {
        $payload = $this->store->decrypt($intent->payload);
        if (! hash_equals($intent->payload_hash, Contract::hash($payload))
            || $intent->reader_id !== ($payload['metadata']['pos_reader'] ?? null)
            || $connection->provider_location_id !== ($payload['metadata']['pos_location'] ?? null)
            || $intent->request_id !== ($payload['metadata']['pos_request'] ?? null)
            || ! preg_match('/^pi_[A-Za-z0-9]+$/D', $payment['id'] ?? '')
            || ($intent->payment_id && $payment['id'] !== $intent->payment_id)
            || ($payment['livemode'] ?? null) !== ($connection->environment === 'production')
            || ($payment['amount'] ?? null) !== $payload['amount'] || ($payment['currency'] ?? null) !== 'usd'
            || ($payment['capture_method'] ?? null) !== 'automatic' || ($payment['payment_method_types'] ?? null) !== ['card_present']
            || ! empty($payment['application_fee_amount']) || ! empty($payment['transfer_data']) || ! empty($payment['on_behalf_of'])
            || ! empty($payment['setup_future_usage']) || ! empty($payment['customer']) || ! empty($payment['amount_details']['tip']['amount'])) {
            throw new PosException('stripe_payment_identity_mismatch');
        }
        foreach ($payload['metadata'] as $k => $v) {
            if (($payment['metadata'][$k] ?? null) !== $v) {
                throw new PosException('stripe_payment_correlation_mismatch');
            }
        }
    }

    public function createRefund(array $operation): ProviderEvidence
    {
        [$sale, $connection] = $this->context($operation);
        $this->stripe->request($connection, 'POST', '/v1/refunds', ['payment_intent' => $operation['original_provider_id'],
            'amount' => $operation['amount_minor'], 'reason' => 'requested_by_customer', 'metadata' => ['pos_request' => $operation['request_id']]], $operation['request_id']);

        return $this->findRefund($operation) ?? throw new PosException('stripe_refund_unknown');
    }

    public function findRefund(array $operation): ?ProviderEvidence
    {
        return $this->serialized($operation, function ($sale, $connection) use ($operation): ?array {
            $id = $operation['provider_id'];
            if (! $id) {
                $result = $this->stripe->request($connection, 'GET', '/v1/refunds', ['payment_intent' => $operation['original_provider_id'], 'limit' => 100]);
                if ($result['has_more'] ?? true) {
                    throw new PosException('stripe_refund_search_incomplete');
                }
                $matches = array_values(array_filter($result['data'] ?? [], fn ($r) => ($r['metadata']['pos_request'] ?? null) === $operation['request_id']));
                if (count($matches) > 1) {
                    throw new PosException('multiple_stripe_refunds');
                }
                if (! $matches) {
                    return null;
                }
                $id = $matches[0]['id'];
            }
            $refund = $this->stripe->request($connection, 'GET', '/v1/refunds/'.$id);
            if (($refund['id'] ?? null) !== $id || ($refund['payment_intent'] ?? null) !== $operation['original_provider_id']
                || ($refund['amount'] ?? null) !== $operation['amount_minor'] || ($refund['currency'] ?? null) !== 'usd'
                || ($refund['metadata']['pos_request'] ?? null) !== $operation['request_id']) {
                throw new PosException('stripe_refund_identity_mismatch');
            }
            $state = match ($refund['status'] ?? '') {
                'succeeded' => 'succeeded', 'failed' => 'failed', 'canceled' => 'cancelled',
                'pending', 'requires_action' => 'processing', default => throw new PosException('stripe_refund_status_unknown')
            };

            return ['id' => $id, 'state' => $state];
        });
    }

    public function audit(array $operation): void
    {
        $fresh = $operation['kind'] === 'payment' ? $this->findPayment($operation) : $this->findRefund($operation);
        if (! $fresh || $fresh->providerId !== $operation['provider_id'] || $fresh->state !== $operation['state']) {
            throw new PosException('original_provider_audit_mismatch');
        }
    }

    private function intent(object $sale): object
    {
        return $this->store->rows('stripe_operations', (int) $sale->tenant_id)->where('id', $sale->id)->firstOrFail();
    }

    private function context(array $operation): array
    {
        $binding = MerchantBinding::restore($operation['binding']);
        if (! $this->supports($binding)) {
            throw new PosException('stripe_binding_invalid');
        }
        $sale = $this->store->rows('sales', $binding->tenantId)->where('id', $operation['sale_id'])->firstOrFail();
        $snapshot = app(PosService::class)->snapshot($sale);
        $connection = $this->store->rows('connections', $binding->tenantId)->where('id', $snapshot['connection_id'])->firstOrFail();
        $intent = $this->intent($sale);
        if ($connection->provider !== 'stripe_terminal' || $connection->merchant_id !== $binding->merchantAccount
            || $connection->provider_location_id !== $binding->locationId || $intent->connection_id !== $connection->id
            || ($connection->environment === 'production' ? 'live' : 'test') !== $binding->environment) {
            throw new PosException('original_merchant_mismatch');
        }

        return [$sale, $connection, $intent];
    }

    private function lock(array $operation): string
    {
        return 'pos-stripe-'.hash('sha256', $this->store->runtime().':'.$operation['tenant_id'].':'.$operation['sale_id']);
    }

    private function serialized(array $operation, callable $read): ?ProviderEvidence
    {
        $this->store->outsideTransaction();

        return Cache::lock($this->lock($operation), 180)->block(5, function () use ($operation, $read): ?ProviderEvidence {
            [$sale, $connection, $intent] = $this->context($operation);
            $result = $read($sale, $connection, $intent);
            if ($result === null) {
                return null;
            }
            $version = $this->store->db->transaction(function () use ($sale): int {
                $q = $this->store->rows('sales', (int) $sale->tenant_id)->where('id', $sale->id);
                $version = (int) $q->lockForUpdate()->firstOrFail()->evidence_version + 1;
                $q->update(['evidence_version' => $version]);

                return $version;
            });

            return new ProviderEvidence($operation['request_id'], MerchantBinding::restore($operation['binding'])->fingerprint(),
                $result['id'], $operation['amount_minor'], $operation['currency'], $result['state'], $version, $operation['original_provider_id']);
        });
    }
}
