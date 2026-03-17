<?php

namespace App\Services\Shopify;

use App\Models\MarketingProfile;
use App\Services\Marketing\CandleCashService;

class ShopifyEmbeddedCustomerSendCandleCashService
{
    public function __construct(
        protected CandleCashService $candleCashService
    ) {
    }

    /**
     * @return array{balance:int,transaction_id:int}
     */
    public function send(MarketingProfile $profile, int $amount, string $reason, ?string $actorId): array
    {
        return $this->candleCashService->addPoints(
            profile: $profile,
            points: $amount,
            type: 'gift',
            source: 'shopify_embedded_admin',
            sourceId: $actorId,
            description: $reason
        );
    }
}
