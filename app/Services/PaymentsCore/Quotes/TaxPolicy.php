<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Quotes;

use App\Services\PaymentsCore\MerchantBinding;
use DateTimeImmutable;

interface TaxPolicy
{
    /** @param list<CatalogLine> $lines */
    public function assess(MerchantBinding $binding, int $siteId, array $lines, DateTimeImmutable $at): TaxAssessment;
}
