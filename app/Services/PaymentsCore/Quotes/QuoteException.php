<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Quotes;

use DomainException;

/** Fixed public codes only; do not forward database, policy or provider error bodies. */
final class QuoteException extends DomainException
{
    public function __construct(public readonly string $blockerCode, public readonly int $httpStatus = 422)
    {
        parent::__construct($blockerCode);
    }
}
