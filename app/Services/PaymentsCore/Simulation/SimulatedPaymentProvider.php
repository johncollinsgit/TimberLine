<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Simulation;

use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentProvider;
use App\Services\PaymentsCore\ProviderEvidence;
use DomainException;

/** Credential-free fictional adapter, constructed explicitly in local/test code only.
 * It is never registered by application configuration or production composition.
 */
final class SimulatedPaymentProvider implements PaymentProvider
{
    public function __construct(
        private FileSimulationStore $store,
        private string $outcome = 'succeeded',
        private bool $loseCreateResponse = false,
    ) {
        $this->assertFixture();
        if (! in_array($outcome, ['processing', 'succeeded', 'failed', 'cancelled'], true)) {
            throw new DomainException('Unsupported fixture outcome.');
        }
    }

    public function supports(MerchantBinding $binding): bool
    {
        return app()->environment(['local', 'testing']) && $binding->environment === 'test'
            && $binding->provider === 'fixture_inventory';
    }

    public function createPayment(array $reservedAttempt): ProviderEvidence
    {
        return $this->create($reservedAttempt, 'payment');
    }

    public function findPayment(array $reservedAttempt): ?ProviderEvidence
    {
        return $this->find($reservedAttempt, 'payment');
    }

    public function createRefund(array $reservedRefund): ProviderEvidence
    {
        return $this->create($reservedRefund, 'refund');
    }

    public function findRefund(array $reservedRefund): ?ProviderEvidence
    {
        return $this->find($reservedRefund, 'refund');
    }

    private function create(array $row, string $kind): ProviderEvidence
    {
        $key = $this->key($row, $kind);
        $evidence = $this->store->locked(function (array &$state) use ($row, $kind, $key): ProviderEvidence {
            if (isset($state[$key])) {
                return $this->restore($state[$key], $row);
            }
            $state[$key] = ['terms_hash' => $row['terms_hash'], 'request_id' => $row['request_id'],
                'binding_fingerprint' => MerchantBinding::restore($row['binding'])->fingerprint(),
                'provider_id' => 'fixture_'.$kind.'_'.substr($key, 0, 32), 'amount_minor' => $row['amount_minor'],
                'currency' => $row['currency'], 'state' => $this->outcome, 'version' => 1,
                'original_provider_id' => $row['original_provider_id']];

            return $this->restore($state[$key], $row);
        });
        if ($this->loseCreateResponse) {
            // Persist first: a new adapter instance/process recovers the same effect.
            throw new SimulatedResponseLost('Synthetic response loss after durable fixture confirmation.');
        }

        return $evidence;
    }

    private function find(array $row, string $kind): ?ProviderEvidence
    {
        $key = $this->key($row, $kind);

        return $this->store->locked(fn (array &$state) => isset($state[$key]) ? $this->restore($state[$key], $row) : null);
    }

    private function key(array $row, string $kind): string
    {
        $this->assertFixture();
        $binding = MerchantBinding::restore($row['binding']);
        if (! $this->supports($binding) || $row['kind'] !== $kind || ! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $row['request_id'])) {
            throw new DomainException('Fixture operation scope invalid.');
        }

        return Contract::hash([$binding->fingerprint(), $kind, $row['request_id']]);
    }

    private function restore(array $record, array $row): ProviderEvidence
    {
        if (! hash_equals($record['terms_hash'], $row['terms_hash']) || $record['amount_minor'] !== $row['amount_minor']
            || $record['currency'] !== $row['currency'] || $record['original_provider_id'] !== $row['original_provider_id']) {
            throw new DomainException('Fixture request identity conflicts with frozen terms.');
        }

        return new ProviderEvidence($record['request_id'], $record['binding_fingerprint'], $record['provider_id'],
            $record['amount_minor'], $record['currency'], $record['state'], $record['version'], $record['original_provider_id']);
    }

    private function assertFixture(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new DomainException('Simulation is unavailable outside explicit local/testing composition.');
        }
    }
}
