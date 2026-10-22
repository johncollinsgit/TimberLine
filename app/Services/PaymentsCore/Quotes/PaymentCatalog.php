<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Quotes;

interface PaymentCatalog
{
    public function item(int $tenantId, int $siteId, int $variantId, int $quantity = 1): CatalogLine;
}
