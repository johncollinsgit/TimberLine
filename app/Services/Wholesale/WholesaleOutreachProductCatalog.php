<?php

namespace App\Services\Wholesale;

use App\Models\TenantWholesaleSetting;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\Cache;

/** Current public catalog metadata, matched only by this store's product IDs. */
class WholesaleOutreachProductCatalog
{
    public const QUERY = 'query WholesaleOutreachProducts($ids:[ID!]!){shop{primaryDomain{url}} nodes(ids:$ids){... on Product{id title onlineStoreUrl featuredMedia{preview{image{url(transform:{maxWidth:600,maxHeight:600}) altText}}}}}}';

    public function products(int $tenantId, array $productIds): array
    {
        $setting = TenantWholesaleSetting::query()->where('tenant_id', $tenantId)->whereNotNull('confirmed_at')->with('shopifyStore')->first();
        if (! $setting?->shopifyStore || (int) $setting->shopifyStore->tenant_id !== $tenantId || $setting->shopifyStore->store_role !== 'wholesale'
            || ! app(TenantModuleAccessResolver::class)->canAccess($tenantId, 'wholesale_operations')) {
            throw new \DomainException('A confirmed wholesale connection is required.');
        }
        $store = ShopifyStores::find($setting->shopifyStore->store_key, true);
        if (! $store || (int) $store['tenant_id'] !== $tenantId || ($store['store_role'] ?? null) !== 'wholesale') {
            throw new \DomainException('The wholesale connection does not belong to this workspace.');
        }
        $ids = collect($productIds)->map(fn ($id) => (string) $id)->filter(fn ($id) => preg_match('/^[1-9][0-9]*$/D', $id))->unique()->sort()->take(40)->values();
        $key = 'wholesale:outreach-products:v1:'.$tenantId.':'.$setting->shopify_store_id.':'.sha1($store['shop'].'|'.$ids->join(','));

        return Cache::remember($key, now()->addMinutes(5), function () use ($store, $ids): array {
            $data = (new ShopifyGraphqlClient($store['shop'], $store['token'], $store['api_version']))->query(self::QUERY, [
                'ids' => $ids->map(fn ($id) => 'gid://shopify/Product/'.$id)->all(),
            ]);
            $root = rtrim((string) data_get($data, 'shop.primaryDomain.url'), '/');
            if (! $this->safeUrl($root) || ! empty(parse_url($root, PHP_URL_QUERY)) || ! in_array(parse_url($root, PHP_URL_PATH), [null, '', '/'], true)) {
                throw new \RuntimeException('The wholesale storefront could not be verified.');
            }
            $hosts = [strtolower((string) parse_url($root, PHP_URL_HOST)), strtolower($store['shop'])];
            $products = [];
            foreach ($data['nodes'] ?? [] as $node) {
                $id = is_array($node) ? (string) basename((string) ($node['id'] ?? '')) : '';
                $url = $node['onlineStoreUrl'] ?? null;
                if (! $ids->contains($id) || ! $this->safeUrl($url) || ! in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), $hosts, true)) {
                    continue;
                }
                $image = data_get($node, 'featuredMedia.preview.image.url');
                $imageHost = strtolower((string) parse_url((string) $image, PHP_URL_HOST));
                $products[$id] = [
                    'id' => $id, 'title' => trim((string) ($node['title'] ?? 'Product')),
                    'url' => $url,
                    'image_url' => $this->safeUrl($image) && ($imageHost === 'cdn.shopify.com' || in_array($imageHost, $hosts, true)) ? $image : null,
                    'image_alt' => trim((string) data_get($node, 'featuredMedia.preview.image.altText')) ?: trim((string) ($node['title'] ?? 'Product')),
                ];
            }

            return ['storefront_url' => $root.'/', 'products' => $products];
        });
    }

    private function safeUrl(mixed $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https'
            && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS)
            && in_array(parse_url($url, PHP_URL_PORT), [null, 443], true);
    }
}
