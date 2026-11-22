<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\VideoCatalogSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class MakerVideoShopify
{
    public const CATALOG_QUERY = <<<'GRAPHQL'
        query MakerVideoVariants($after: String, $query: String!) {
          productVariants(first: 40, after: $after, query: $query) {
            nodes {
              legacyResourceId title availableForSale
              product { title status onlineStoreUrl }
            }
            pageInfo { hasNextPage endCursor }
          }
        }
        GRAPHQL;

    public function configured(): bool
    {
        return filled(config('marketplace.video_shopify_client_id')) && filled(config('marketplace.video_shopify_client_secret'));
    }

    public function shopDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        abort_unless(preg_match('/^[a-z0-9][a-z0-9-]{0,62}\.myshopify\.com$/D', $domain), 422, 'Enter the permanent myshopify.com domain.');

        return $domain;
    }

    public function authorizationUrl(string $shop, string $state): string
    {
        abort_unless($this->configured(), 503, 'Shopify catalog connections are awaiting app setup.');

        return 'https://'.$this->shopDomain($shop).'/admin/oauth/authorize?'.http_build_query([
            'client_id' => config('marketplace.video_shopify_client_id'), 'scope' => 'read_products',
            'redirect_uri' => 'https://'.config('marketplace.host').'/integrations/video-catalog/shopify/callback', 'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(string $shop, array $grant): array
    {
        try {
            $response = Http::withoutRedirecting()->asForm()->acceptJson()->connectTimeout(3)->timeout(8)
                ->post('https://'.$this->shopDomain($shop).'/admin/oauth/access_token', [
                    'client_id' => config('marketplace.video_shopify_client_id'),
                    'client_secret' => config('marketplace.video_shopify_client_secret'), ...$grant,
                ]);
        } catch (ConnectionException) {
            $this->unavailable();
        }
        $data = $response->json();
        if (! $response->successful() || empty($data['access_token']) || empty($data['refresh_token'])
            || ! in_array('read_products', explode(',', $data['scope'] ?? ''), true) || (int) ($data['expires_in'] ?? 0) <= 0) {
            $this->unavailable();
        }

        return ['access_token' => $data['access_token'], 'refresh_token' => $data['refresh_token'], 'expires_at' => now()->timestamp + (int) $data['expires_in']];
    }

    private function accessToken(VideoCatalogSource $source): string
    {
        $tokens = $source->credentials;
        if (($tokens['expires_at'] ?? 0) > now()->timestamp + 60) {
            return $tokens['access_token'];
        }

        return DB::transaction(function () use ($source) {
            $current = VideoCatalogSource::query()->where('maker_id', $source->maker_id)->lockForUpdate()->find($source->id);
            if (! $current || ! $current->credentials) {
                $this->unavailable();
            }
            $tokens = $current->credentials;
            if ($tokens['expires_at'] <= now()->timestamp + 60) {
                $tokens = $this->exchange($current->reference, ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);
                $current->update(['credentials' => $tokens]);
            }

            return $tokens['access_token'];
        });
    }

    public function variants(VideoCatalogSource $source, ?string $after = null, ?string $id = null): array
    {
        abort_unless($source->provider === 'shopify' && ($id === null || preg_match('/^[1-9][0-9]{0,19}$/D', $id)), 422);
        $query = 'published_status:published'.($id !== null ? ' id:'.$id : '');
        try {
            $response = Http::withoutRedirecting()->acceptJson()->connectTimeout(3)->timeout(8)
                ->withHeaders(['X-Shopify-Access-Token' => $this->accessToken($source)])
                ->post('https://'.$this->shopDomain($source->reference).'/admin/api/'.config('marketplace.api_version').'/graphql.json', [
                    'query' => self::CATALOG_QUERY, 'variables' => ['query' => $query, 'after' => $after],
                ]);
        } catch (ConnectionException) {
            $this->unavailable();
        }
        $page = $response->json('data.productVariants');
        if (! $response->successful() || $response->json('errors') || ! is_array($page['nodes'] ?? null)) {
            $this->unavailable();
        }
        $variants = [];
        foreach ($page['nodes'] as $node) {
            $variantId = (string) ($node['legacyResourceId'] ?? '');
            $product = $node['product'] ?? [];
            if (($product['status'] ?? null) !== 'ACTIVE' || ! preg_match('/^[1-9][0-9]{0,19}$/D', $variantId) || ($id !== null && $variantId !== $id)) {
                continue;
            }
            // Anchor links on the shop Shopify authenticated. Only accept a published product path.
            $url = parse_url((string) ($product['onlineStoreUrl'] ?? ''));
            $path = $url['path'] ?? '';
            if (($url['scheme'] ?? null) !== 'https' || ! preg_match('~^/(?:[a-zA-Z-]{2,10}/)?products/[^/?#\\\\]+$~D', $path)) {
                continue;
            }
            $variants[$variantId] = ['id' => $variantId, 'label' => $product['title'], 'variant' => $node['title'],
                'available' => (bool) $node['availableForSale'], 'href' => 'https://'.$source->reference.$path.'?variant='.$variantId];
        }

        return ['variants' => $variants, 'next' => ($page['pageInfo']['hasNextPage'] ?? false) ? ($page['pageInfo']['endCursor'] ?? null) : null];
    }

    private function unavailable(): never
    {
        throw ValidationException::withMessages(['shopify' => 'Shopify could not verify the catalog. Try again or reconnect your store.']);
    }
}
