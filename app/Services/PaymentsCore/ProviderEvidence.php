<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore;

use DomainException;

/** Trusted adapter output only, after authenticated provider retrieval/event
 * verification. No controller or client-evidence application API is provided.
 */
final readonly class ProviderEvidence
{
    public function __construct(
        public string $operationId,
        public string $bindingFingerprint,
        public string $providerId,
        public int $amountMinor,
        public string $currency,
        public string $state,
        public int $version,
        public ?string $originalPaymentId = null,
    ) {
        Contract::id($operationId);
        Contract::id($providerId);
        Contract::minor($amountMinor);
        if (! in_array($state, ['processing', 'succeeded', 'failed', 'cancelled'], true) || $version < 1) {
            throw new DomainException('Invalid canonical provider evidence.');
        }
    }
}
