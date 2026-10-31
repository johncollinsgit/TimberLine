<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore;

/** Recheck current authenticated actor, tenant membership, device/register scope,
 * capability freshness and independent merchant/collection/servicing gates.
 * A future implementation must independently authorize collect, recover, refund.
 */
interface PaymentAccess
{
    public function assertAllowed(MerchantBinding $binding, string $operation): void;
}
