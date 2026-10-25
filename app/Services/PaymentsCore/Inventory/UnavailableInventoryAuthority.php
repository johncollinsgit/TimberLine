<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Inventory;

use App\Services\PaymentsCore\Quotes\TrustedQuote;

final class UnavailableInventoryAuthority implements InventoryReservationAuthority
{
    public function assertAllowed(TrustedQuote $quote, string $operation): void
    {
        throw new InventoryUnavailableException('Independent reviewed local inventory authority is unavailable.');
    }
}
