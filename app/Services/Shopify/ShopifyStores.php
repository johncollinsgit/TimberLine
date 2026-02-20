<?php

namespace App\Services\Shopify;

use App\Models\ShopifyStore;
use Illuminate\Support\Facades\Schema;

class ShopifyStores
{
    public static function all(bool $allowMissingToken = false): array
    {
        return array_values(array_filter(array_map(function (array $store): array {
            return self::overlayDbStore($store);
        }, self::stores()), function (array $store) use ($allowMissingToken): bool {
            if (empty($store['shop'])) {
                return false;
            }

            if ($allowMissingToken) {
                return !empty($store['client_id']);
            }

            return !empty($store['token']);
        }));
    }

    public static function resolve(?string $storeKey, bool $allowMissingToken = false): array
    {
        if ($storeKey === null) {
            return self::all($allowMissingToken);
        }

        $stores = self::stores();
        $normalized = strtolower($storeKey);

        if ($normalized === 'all') {
            return self::all($allowMissingToken);
        }

        if (in_array($normalized, ['shopify_retail', 'retail'], true)) {
            $normalized = 'retail';
        }

        if (in_array($normalized, ['shopify_wholesale', 'wholesale'], true)) {
            $normalized = 'wholesale';
        }

        if (!array_key_exists($normalized, $stores)) {
            return [];
        }

        $store = $stores[$normalized];
        $store = self::overlayDbStore($store);
        if (empty($store['shop'])) {
            return [];
        }
        if ($allowMissingToken) {
            if (empty($store['client_id'])) {
                return [];
            }
        } else {
            if (empty($store['token'])) {
                return [];
            }
        }

        return [$store];
    }

    public static function find(string $storeKey, bool $allowMissingToken = false): ?array
    {
        $resolved = self::resolve($storeKey, $allowMissingToken);
        return $resolved[0] ?? null;
    }

    protected static function stores(): array
    {
        $retailShop = config('services.shopify.stores.retail.shop')
            ?? config('services.shopify.retail.shop');
        $retailToken = config('services.shopify.stores.retail.token')
            ?? config('services.shopify.stores.retail.access_token')
            ?? config('services.shopify.retail.token')
            ?? config('services.shopify.retail.access_token');
        $retailClientId = config('services.shopify.stores.retail.client_id')
            ?? config('services.shopify.retail.client_id');
        $retailSecret = config('services.shopify.stores.retail.client_secret')
            ?? config('services.shopify.retail.client_secret');
        $wholesaleShop = config('services.shopify.stores.wholesale.shop')
            ?? config('services.shopify.wholesale.shop');
        $wholesaleToken = config('services.shopify.stores.wholesale.token')
            ?? config('services.shopify.stores.wholesale.access_token')
            ?? config('services.shopify.wholesale.token')
            ?? config('services.shopify.wholesale.access_token');
        $wholesaleClientId = config('services.shopify.stores.wholesale.client_id')
            ?? config('services.shopify.wholesale.client_id');
        $wholesaleSecret = config('services.shopify.stores.wholesale.client_secret')
            ?? config('services.shopify.wholesale.client_secret');

        return [
            'retail' => [
                'key' => 'retail',
                'shop' => $retailShop,
                'token' => $retailToken,
                'source' => 'shopify_retail',
                'secret' => $retailSecret,
                'client_id' => $retailClientId,
                'api_version' => config('services.shopify.api_version', '2026-01'),
            ],
            'wholesale' => [
                'key' => 'wholesale',
                'shop' => $wholesaleShop,
                'token' => $wholesaleToken,
                'source' => 'shopify_wholesale',
                'secret' => $wholesaleSecret,
                'client_id' => $wholesaleClientId,
                'api_version' => config('services.shopify.api_version', '2026-01'),
            ],
        ];
    }

    public static function findByShopDomain(string $shopDomain): ?array
    {
        $normalized = strtolower(preg_replace('#^https?://#', '', $shopDomain));
        $normalized = rtrim($normalized, '/');

        foreach (self::stores() as $store) {
            $storeDomain = strtolower(preg_replace('#^https?://#', '', (string) ($store['shop'] ?? '')));
            $storeDomain = rtrim($storeDomain, '/');

            if ($storeDomain !== '' && $storeDomain === $normalized) {
                return self::overlayDbStore($store);
            }
        }

        return null;
    }

    protected static function overlayDbStore(array $store): array
    {
        if (!Schema::hasTable('shopify_stores')) {
            return $store;
        }

        $record = ShopifyStore::query()
            ->where('store_key', $store['key'])
            ->first();

        if (!$record) {
            return $store;
        }

        $store['shop'] = $record->shop_domain ?: $store['shop'];
        $store['token'] = $record->access_token ?: $store['token'];

        return $store;
    }
}
