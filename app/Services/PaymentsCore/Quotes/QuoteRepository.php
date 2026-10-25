<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Quotes;

interface QuoteRepository
{
    public function findByRequest(int $tenantId, string $requestId): ?TrustedQuote;

    public function findById(int $tenantId, string $quoteId): ?TrustedQuote;

    /** Atomic insert-or-replay. A racing writer cannot replace immutable quote terms. */
    public function saveIfAbsent(TrustedQuote $quote): TrustedQuote;
}
