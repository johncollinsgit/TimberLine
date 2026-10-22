<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Quotes;

interface ServiceItemPolicy
{
    /** Reviewed fixed-price printing only. Generic service metadata is not approval. */
    public function mapping(int $tenantId, int $siteId, int $variantId): ?FixedPrintingMapping;
}
