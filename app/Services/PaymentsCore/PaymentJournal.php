<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore;

/** Internal durable repository. No provider/network operation belongs here.
 * Mutations must commit before returning; caller-owned transactions are refused.
 * Installation is a migration concern and deliberately absent from this contract.
 */
interface PaymentJournal
{
    public function reservePayment(MerchantBinding $binding, SaleSnapshot $sale, string $requestId): array;

    public function reserveRefund(int $tenantId, string $paymentId, string $requestId, int $amountMinor, string $reason): array;

    public function get(int $tenantId, string $id): array;

    public function findByRequest(int $tenantId, string $requestId): ?array;

    public function claim(int $tenantId, string $id): bool;

    public function unknown(int $tenantId, string $id): array;

    /** Preserve financial uncertainty while explicitly separating a finished customer interaction. */
    public function parkForReview(int $tenantId, string $id): array;

    public function apply(int $tenantId, string $id, ProviderEvidence $evidence): array;
}
