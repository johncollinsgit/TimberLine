<?php

namespace App\Services\Shopify;

class ShopifyHmacVerifier
{
    /**
     * @param  array<string, mixed>  $query
     */
    public function verifyQuery(array $query, string $secret): bool
    {
        $secret = trim($secret);
        if ($secret === '') {
            return false;
        }

        $hmac = $query['hmac'] ?? '';
        if (! is_string($hmac) || $hmac === '') {
            return false;
        }

        unset($query['hmac'], $query['signature']);
        ksort($query);

        $computed = hash_hmac('sha256', http_build_query($query, '', '&', PHP_QUERY_RFC3986), $secret);

        return hash_equals($computed, $hmac);
    }
}
