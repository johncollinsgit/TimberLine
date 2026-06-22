<?php

namespace App\Services\Mobile;

use App\Models\MarketingProfile;

class ModernForestryMobileCustomerSession
{
    /**
     * @param  array<string,mixed>  $identity
     * @param  array<string,mixed>  $claims
     */
    public function __construct(
        public readonly MarketingProfile $profile,
        public readonly string $accessToken,
        public readonly array $identity = [],
        public readonly array $claims = []
    ) {
    }
}
