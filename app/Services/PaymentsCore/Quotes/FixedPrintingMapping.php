<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Quotes;

use App\Services\PaymentsCore\Contract;

/** An injected reviewed server policy, not a product title, category or client flag. */
final readonly class FixedPrintingMapping
{
    public function __construct(public string $policyRevision)
    {
        Contract::id($policyRevision);
    }
}
