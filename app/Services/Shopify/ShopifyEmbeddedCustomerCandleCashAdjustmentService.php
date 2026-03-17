<?php

namespace App\Services\Shopify;

use App\Models\MarketingProfile;
use App\Services\Marketing\CandleCashService;

class ShopifyEmbeddedCustomerCandleCashAdjustmentService
{
    public function __construct(
        protected CandleCashService $candleCashService
    ) {
    }

    /**
     * @return array{balance:int,transaction_id:int}
     */
    public function adjust(MarketingProfile $profile, string $direction, int $amount, string $reason, ?string $actorId): array
    {
        $points = $direction === 'subtract' ? -1 * $amount : $amount;

        return $this->candleCashService->addPoints(
            profile: $profile,
            points: $points,
            type: 'adjust',
            source: 'shopify_embedded_admin',
            sourceId: $actorId,
            description: $reason
        );
    }
}
