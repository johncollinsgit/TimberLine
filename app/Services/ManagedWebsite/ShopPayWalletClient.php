<?php

namespace App\Services\ManagedWebsite;

use App\Models\WebsiteShopPayConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Shopify Wallet traffic is confined to the merchant's verified myshopify host. */
class ShopPayWalletClient
{
    /** @param array<string,mixed> $variables @return array<string,mixed> */
    public function storefront(WebsiteShopPayConnection $connection, string $query, array $variables = []): array
    {
        return $this->graphql($connection, 'storefront', $query, $variables);
    }

    /** @param array<string,mixed> $variables @return array<string,mixed> */
    public function admin(WebsiteShopPayConnection $connection, string $query, array $variables = []): array
    {
        return $this->graphql($connection, 'admin', $query, $variables);
    }

    /** @param array<string,mixed> $variables @return array<string,mixed> */
    private function graphql(#[\SensitiveParameter] WebsiteShopPayConnection $connection, string $api, string $query, #[\SensitiveParameter] array $variables): array
    {
        $host = strtolower(trim((string) $connection->shop_domain));
        if (! preg_match('/\A[a-z0-9][a-z0-9-]*\.myshopify\.com\z/', $host)) {
            throw new RuntimeException('Shop Pay shop domain must be a myshopify.com host.');
        }
        $version = (string) $connection->api_version;
        if (! preg_match('/\A20\d{2}-(01|04|07|10)\z/', $version)) {
            throw new RuntimeException('Unsupported Shopify API version.');
        }

        $token = $api === 'admin' ? (string) $connection->admin_access_token : (string) $connection->storefront_access_token;
        if ($token === '') {
            throw new RuntimeException('Shop Pay API credential is missing.');
        }
        $headers = $api === 'admin'
            ? ['X-Shopify-Access-Token' => $token]
            : ['X-Shopify-Storefront-Access-Token' => $token];
        $path = $api === 'admin' ? "/admin/api/{$version}/graphql.json" : "/api/{$version}/graphql.json";
        try {
            $response = Http::withoutRedirecting()->asJson()->acceptJson()->connectTimeout(5)->withHeaders($headers)->timeout(15)->post('https://'.$host.$path, [
                'query' => $query,
                'variables' => $variables,
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            throw new RuntimeException('Shopify could not be reached. The original payment must be reconciled before retrying.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Shopify rejected the '.$api.' API request (HTTP '.$response->status().').');
        }
        $body = $response->json();
        if (! is_array($body) || ! is_array($body['data'] ?? null)) {
            throw new RuntimeException('Shopify returned an invalid '.$api.' API response.');
        }
        if (! empty($body['errors'])) {
            // GraphQL errors can include sensitive request details; do not propagate them.
            throw new RuntimeException('Shopify rejected the '.$api.' API query. Check app permissions and API version.');
        }

        return $body['data'];
    }
}
