<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore;

/** No implementation ships. Credentials and raw card data never enter core.
 * Versions must be monotonic canonical revisions, not event arrival timestamps.
 * A provider without revisions must serialize a fresh authenticated retrieval.
 */
interface PaymentProvider
{
    public function supports(MerchantBinding $binding): bool;

    public function createPayment(array $reservedAttempt): ProviderEvidence;

    public function findPayment(array $reservedAttempt): ?ProviderEvidence;

    public function createRefund(array $reservedRefund): ProviderEvidence;

    public function findRefund(array $reservedRefund): ?ProviderEvidence;
}
