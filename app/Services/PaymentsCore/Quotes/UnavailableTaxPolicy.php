<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Quotes;

use App\Services\PaymentsCore\MerchantBinding;
use DateTimeImmutable;

final class UnavailableTaxPolicy implements TaxPolicy
{
    public function assess(MerchantBinding $binding, int $siteId, array $lines, DateTimeImmutable $at): TaxAssessment
    {
        throw new QuoteException('tax_policy_missing', 503);
    }
}
