<?php

namespace App\Services\PointOfSale;

/** Provider-owned payment primitives; routing is frozen on each original attempt. */
interface PosPaymentProvider
{
    public function key(): string;

    /** Explicit supported features, never inferred from another provider. */
    public function capabilities(): array;

    public function createPayment(array $snapshot, string $key): array;

    public function payment(int $tenantId, string $id): array;

    public function findPayment(int $tenantId, string $key): ?array;

    public function cancelPayment(int $tenantId, string $id): array;

    public function createRefund(array $snapshot, string $key): array;

    public function refund(int $tenantId, string $id): array;

    public function findRefund(int $tenantId, string $key): ?array;
}
