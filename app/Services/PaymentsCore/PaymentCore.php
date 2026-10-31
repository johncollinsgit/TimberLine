<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore;

use DomainException;
use Throwable;

/** Provider-neutral orchestration. Default access is closed and no provider ships. */
final class PaymentCore
{
    /** @param array<string, PaymentProvider> $providers Exact provider keys only. */
    public function __construct(
        private PaymentJournal $journal,
        private array $providers = [],
        private PaymentAccess $access = new ClosedPaymentAccess,
    ) {}

    /** Safe inspection never looks up or contacts a provider. */
    public function inspect(MerchantBinding $scope, string $operationId): array
    {
        $this->access->assertAllowed($scope, 'inspect');
        $row = $this->original($scope, $operationId);
        $this->access->assertAllowed(MerchantBinding::restore($row['binding']), 'inspect');

        return $row;
    }

    public function start(MerchantBinding $binding, SaleSnapshot $sale, string $requestId): array
    {
        $this->access->assertAllowed($binding, 'collect');
        $provider = $this->provider($binding);
        $row = $this->journal->reservePayment($binding, $sale, $requestId);
        if (! $this->journal->claim($binding->tenantId, $row['id'])) {
            return $this->journal->get($binding->tenantId, $row['id']);
        }

        return $this->dispatch($provider, $this->journal->get($binding->tenantId, $row['id']));
    }

    public function recover(MerchantBinding $scope, string $operationId): array
    {
        $this->access->assertAllowed($scope, 'recover');
        $row = $this->original($scope, $operationId);
        $provider = $this->provider(MerchantBinding::restore($row['binding']));
        $this->access->assertAllowed(MerchantBinding::restore($row['binding']), 'recover');
        if (in_array($row['state'], ['succeeded', 'failed', 'cancelled'], true)) {
            return $row;
        }
        try {
            // Always search by original durable request identity, including a lost create response.
            $evidence = $row['kind'] === 'payment' ? $provider->findPayment($row) : $provider->findRefund($row);

            return $evidence ? $this->journal->apply($scope->tenantId, $operationId, $evidence)
                : $this->journal->unknown($scope->tenantId, $operationId);
        } catch (Throwable) {
            return $this->journal->unknown($scope->tenantId, $operationId);
        }
    }

    public function refund(MerchantBinding $scope, string $paymentId, string $requestId, int $amountMinor, string $reason): array
    {
        $this->access->assertAllowed($scope, 'refund');
        $payment = $this->original($scope, $paymentId);
        $provider = $this->provider(MerchantBinding::restore($payment['binding']));
        $row = $this->journal->reserveRefund($scope->tenantId, $paymentId, $requestId, $amountMinor, $reason);
        if (! $this->journal->claim($scope->tenantId, $row['id'])) {
            return $this->journal->get($scope->tenantId, $row['id']);
        }

        return $this->dispatch($provider, $this->journal->get($scope->tenantId, $row['id']));
    }

    private function original(MerchantBinding $scope, string $id): array
    {
        $row = $this->journal->get($scope->tenantId, $id);
        if (! hash_equals(MerchantBinding::restore($row['binding'])->fingerprint(), $scope->fingerprint())) {
            throw new DomainException('Operation requires its immutable original register/provider/merchant binding.');
        }

        return $row;
    }

    private function provider(MerchantBinding $binding): PaymentProvider
    {
        $provider = $this->providers[$binding->provider] ?? null;
        if (! $provider instanceof PaymentProvider || ! $provider->supports($binding)) {
            throw new ProviderUnavailableException('The original provider and merchant capability are unavailable.');
        }

        return $provider;
    }

    private function dispatch(PaymentProvider $provider, array $row): array
    {
        try {
            // The reserved terms and once-only dispatch claim are committed before HTTP.
            $binding = MerchantBinding::restore($row['binding']);
            $this->access->assertAllowed($binding, $row['kind'] === 'payment' ? 'collect' : 'refund');
            $evidence = $row['kind'] === 'payment' ? $provider->createPayment($row) : $provider->createRefund($row);

            return $this->journal->apply($row['tenant_id'], $row['id'], $evidence);
        } catch (Throwable) {
            return $this->journal->unknown($row['tenant_id'], $row['id']);
        }
    }
}
