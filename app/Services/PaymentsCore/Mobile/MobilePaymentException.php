<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Mobile;

use RuntimeException;

final class MobilePaymentException extends RuntimeException
{
    public function __construct(public readonly string $blockerCode, public readonly int $httpStatus)
    {
        parent::__construct($blockerCode);
    }
}
