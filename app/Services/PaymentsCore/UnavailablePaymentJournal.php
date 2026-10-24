<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore;

use DomainException;

/** Unsupported application databases never fall back to the SQLite rehearsal. */
final class UnavailablePaymentJournal implements PaymentJournal
{
    public function reservePayment(MerchantBinding $binding, SaleSnapshot $sale, string $requestId): array
    {
        throw new DomainException('Durable payment persistence is unavailable.');
    }

    public function reserveRefund(int $tenantId, string $paymentId, string $requestId, int $amountMinor, string $reason): array
    {
        throw new DomainException('Durable payment persistence is unavailable.');
    }

    public function get(int $tenantId, string $id): array
    {
        throw new DomainException('Durable payment persistence is unavailable.');
    }

    public function findByRequest(int $tenantId, string $requestId): ?array
    {
        throw new DomainException('Durable payment persistence is unavailable.');
    }

    public function claim(int $tenantId, string $id): bool
    {
        throw new DomainException('Durable payment persistence is unavailable.');
    }

    public function unknown(int $tenantId, string $id): array
    {
        throw new DomainException('Durable payment persistence is unavailable.');
    }

    public function apply(int $tenantId, string $id, ProviderEvidence $evidence): array
    {
        throw new DomainException('Durable payment persistence is unavailable.');
    }
}
