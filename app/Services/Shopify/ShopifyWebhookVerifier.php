<?php

namespace App\Services\Shopify;

class ShopifyWebhookVerifier
{
    public function isValid(string $payload, string $hmacHeader, ?string $shopDomain = null): bool
    {
        if ($payload === '' || trim($hmacHeader) === '') {
            return false;
        }

        foreach ($this->candidateSecrets($shopDomain) as $secret) {
            $calculated = base64_encode(hash_hmac('sha256', $payload, $secret, true));
            if (hash_equals($calculated, $hmacHeader)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    public function candidateSecrets(?string $shopDomain = null): array
    {
        $secrets = [];
        $shopDomain = strtolower(trim((string) $shopDomain));

        if ($shopDomain !== '') {
            $store = ShopifyStores::findByShopDomain($shopDomain);
            $secret = trim((string) ($store['secret'] ?? ''));
            if ($secret !== '') {
                $secrets[] = $secret;
            }
        }

        foreach ((array) config('services.shopify.stores', []) as $store) {
            if (! is_array($store)) {
                continue;
            }

            $secret = trim((string) ($store['client_secret'] ?? ''));
            if ($secret !== '') {
                $secrets[] = $secret;
            }
        }

        return array_values(array_unique($secrets));
    }
}
