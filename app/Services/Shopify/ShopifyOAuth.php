<?php

namespace App\Services\Shopify;

class ShopifyOAuth
{
    public function buildAuthUrl(array $store, string $redirectUri, string $state): string
    {
        $scopes = config('services.shopify.scopes', 'read_orders,read_products');

        $params = http_build_query([
            'client_id' => $store['client_id'],
            'scope' => $scopes,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'https://'.rtrim($store['shop'], '/').'/admin/oauth/authorize?'.$params;
    }
}
