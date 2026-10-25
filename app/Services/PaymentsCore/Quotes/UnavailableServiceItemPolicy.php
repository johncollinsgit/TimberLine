<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Quotes;

final class UnavailableServiceItemPolicy implements ServiceItemPolicy
{
    public function mapping(int $tenantId, int $siteId, int $variantId): ?FixedPrintingMapping
    {
        return null;
    }
}
