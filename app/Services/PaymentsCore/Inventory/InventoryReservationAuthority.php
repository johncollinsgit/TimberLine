<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Inventory;

use App\Services\PaymentsCore\Quotes\TrustedQuote;

/** Pure local, revocable policy only. No tax/provider/carrier HTTP in TX. */
interface InventoryReservationAuthority
{
    public function assertAllowed(TrustedQuote $quote, string $operation): void;
}
