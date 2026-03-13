<?php

namespace App\Services\Shopify;

class ShopifyOAuth
{
    public function buildAuthUrl(array $store, string $redirectUri, string $state): string
    {
        $scopes = implode(',', $this->requestedScopes());

        $params = http_build_query([
            'client_id' => $store['client_id'],
            'scope' => $scopes,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'https://'.rtrim($store['shop'], '/').'/admin/oauth/authorize?'.$params;
    }

    /**
     * @return array<int,string>
     */
    public function requestedScopes(): array
    {
        $configured = explode(',', (string) config('services.shopify.scopes', 'read_orders,read_products,read_customers'));
        $scopes = array_values(array_unique(array_filter(array_map(
            static fn (string $scope): string => trim(strtolower($scope)),
            $configured
        ))));

        // Metafield customer sync requires Admin customer read scope.
        if (! in_array('read_customers', $scopes, true) && ! in_array('write_customers', $scopes, true)) {
            $scopes[] = 'read_customers';
        }

        return $scopes;
    }
}
