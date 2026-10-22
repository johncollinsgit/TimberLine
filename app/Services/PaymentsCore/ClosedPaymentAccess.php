<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore;

use DomainException;

final class ClosedPaymentAccess implements PaymentAccess
{
    public function assertAllowed(MerchantBinding $binding, string $operation): void
    {
        throw new DomainException('Payments are unavailable until server authorization and merchant readiness are integrated.');
    }
}
