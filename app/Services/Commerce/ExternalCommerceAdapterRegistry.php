<?php

namespace App\Services\Commerce;

use App\Services\Commerce\Adapters\ShopifyCommerceAdapter;
use App\Services\Commerce\Adapters\SquarespaceCommerceAdapter;
use App\Services\Commerce\Adapters\WixCommerceAdapter;
use App\Services\Commerce\Adapters\WooCommerceAdapter;
use App\Services\Commerce\Contracts\ExternalCommerceAdapter;

class ExternalCommerceAdapterRegistry
{
    public function for(string $provider): ExternalCommerceAdapter
    {
        return match ($provider) {
            'shopify' => app(ShopifyCommerceAdapter::class),
            'woocommerce' => app(WooCommerceAdapter::class),
            'squarespace' => app(SquarespaceCommerceAdapter::class),
            'wix' => app(WixCommerceAdapter::class),
            default => throw new \InvalidArgumentException('Unsupported commerce source.'),
        };
    }
}
