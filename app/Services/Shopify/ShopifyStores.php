<?php

namespace App\Services\Shopify;

use App\Models\ShopifyStore;
use Illuminate\Support\Facades\Schema;

class ShopifyStores
{
    /**
     * @return array<int,string>
     */
    public static function activeStoreKeys(): array
    {
        $configured = self::configuredStoreKeys();
        if ($configured === []) {
            return [];
        }

        $requested = self::parseStoreKeyList(config('services.shopify.active_store_keys'));
        if ($requested === []) {
            return $configured;
        }

        $lookup = array_fill_keys($configured, true);
        $active = array_values(array_filter(
            $requested,
            static fn (string $key): bool => isset($lookup[$key])
        ));

        return $active !== [] ? array_values(array_unique($active)) : $configured;
    }

    /**
     * @return array<int,string>
     */
    public static function requiredStoreKeys(): array
    {
        $active = self::activeStoreKeys();
        if ($active === []) {
            return [];
        }

        $requested = self::parseStoreKeyList(config('services.shopify.required_store_keys'));
        $lookup = array_fill_keys($active, true);

        if ($requested !== []) {
            $required = array_values(array_filter(
                $requested,
                static fn (string $key): bool => isset($lookup[$key])
            ));

            if ($required !== []) {
                return array_values(array_unique($required));
            }
        }

        if (in_array('retail', $active, true)) {
            return ['retail'];
        }

        return [$active[0]];
    }

    public static function isRequiredStoreKey(?string $storeKey): bool
    {
        $normalized = self::normalizeStoreKeyValue($storeKey);
        if ($normalized === null) {
            return false;
        }

        return in_array($normalized, self::requiredStoreKeys(), true);
    }

    public static function all(bool $allowMissingToken = false): array
    {
        [$stores] = self::resolvedStoresAndIssues(null, $allowMissingToken);

        return array_values($stores);
    }

    public static function resolve(?string $storeKey, bool $allowMissingToken = false): array
    {
        [$stores] = self::resolvedStoresAndIssues($storeKey, $allowMissingToken);

        return array_values($stores);
    }

    /**
     * @return array<int,string>
     */
    public static function unresolvedMessages(?string $storeKey, bool $allowMissingToken = false): array
    {
        [, $issues] = self::resolvedStoresAndIssues($storeKey, $allowMissingToken);

        return array_values($issues);
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
        $retailLegacyToken = config('services.shopify.stores.retail.token')
            ?? config('services.shopify.stores.retail.access_token')
            ?? config('services.shopify.retail.token')
            ?? config('services.shopify.retail.access_token');
        $retailTimezone = config('services.shopify.stores.retail.timezone')
            ?? config('services.shopify.reporting_timezone')
            ?? 'UTC';
        $retailClientId = config('services.shopify.stores.retail.client_id')
            ?? config('services.shopify.retail.client_id');
        $retailSecret = config('services.shopify.stores.retail.client_secret')
            ?? config('services.shopify.retail.client_secret');
        $wholesaleShop = config('services.shopify.stores.wholesale.shop')
            ?? config('services.shopify.wholesale.shop');
        $wholesaleLegacyToken = config('services.shopify.stores.wholesale.token')
            ?? config('services.shopify.stores.wholesale.access_token')
            ?? config('services.shopify.wholesale.token')
            ?? config('services.shopify.wholesale.access_token');
        $wholesaleTimezone = config('services.shopify.stores.wholesale.timezone')
            ?? config('services.shopify.reporting_timezone')
            ?? 'UTC';
        $wholesaleClientId = config('services.shopify.stores.wholesale.client_id')
            ?? config('services.shopify.wholesale.client_id');
        $wholesaleSecret = config('services.shopify.stores.wholesale.client_secret')
            ?? config('services.shopify.wholesale.client_secret');

        return [
            'retail' => [
                'key' => 'retail',
                'shop' => $retailShop,
                'legacy_token' => $retailLegacyToken,
                'source' => 'shopify_retail',
                'timezone' => $retailTimezone,
                'secret' => $retailSecret,
                'client_id' => $retailClientId,
                'api_version' => config('services.shopify.api_version', '2026-01'),
            ],
            'wholesale' => [
                'key' => 'wholesale',
                'shop' => $wholesaleShop,
                'legacy_token' => $wholesaleLegacyToken,
                'source' => 'shopify_wholesale',
                'timezone' => $wholesaleTimezone,
                'secret' => $wholesaleSecret,
                'client_id' => $wholesaleClientId,
                'api_version' => config('services.shopify.api_version', '2026-01'),
            ],
        ];
    }

    public static function findByShopDomain(string $shopDomain): ?array
    {
        $normalized = self::normalizeDomain($shopDomain);

        if ($normalized === '') {
            return null;
        }

        // Domain-to-store resolution must remain global so optional stores can still
        // resolve signed storefront/admin requests even when launch defaults are retail-only.
        foreach (self::configuredStoreKeys() as $storeKey) {
            $store = self::find($storeKey, true);
            if (! is_array($store)) {
                continue;
            }

            $storeDomain = self::normalizeDomain((string) ($store['shop'] ?? ''));

            if ($storeDomain !== '' && $storeDomain === $normalized) {
                return $store;
            }
        }

        return null;
    }

    /**
     * @return array{0:array<int,array<string,mixed>>,1:array<int,string>}
     */
    protected static function resolvedStoresAndIssues(?string $storeKey, bool $allowMissingToken): array
    {
        $stores = self::stores();
        $keys = self::requestedStoreKeys($storeKey, $stores);
        if ($keys === []) {
            $normalized = trim((string) $storeKey);
            if ($normalized === '') {
                return [[], []];
            }

            return [[], ["Unknown Shopify store key '{$normalized}'. Use retail|wholesale|all."]];
        }

        $records = self::storeRecordsByKey($keys);

        $resolved = [];
        $issues = [];

        foreach ($keys as $key) {
            $base = $stores[$key];
            $record = $records[$key] ?? null;

            $configuredShop = trim((string) ($base['shop'] ?? ''));
            $storedShop = trim((string) ($record?->shop_domain ?? ''));
            $shop = $storedShop !== '' ? $storedShop : $configuredShop;

            $clientId = trim((string) ($base['client_id'] ?? ''));
            $secret = trim((string) ($base['secret'] ?? ''));
            $storedToken = trim((string) ($record?->access_token ?? ''));
            $legacyToken = trim((string) ($base['legacy_token'] ?? ''));

            $token = '';
            $tokenSource = 'none';
            if ($storedToken !== '') {
                $token = $storedToken;
                $tokenSource = 'oauth_db';
            } elseif (self::allowLegacyEnvTokenFallback() && $legacyToken !== '') {
                $token = $legacyToken;
                $tokenSource = 'env_legacy';
            }

            if ($shop === '') {
                $issues[] = "{$key} store domain missing in config.";
                continue;
            }

            if ($allowMissingToken) {
                if ($clientId === '') {
                    $issues[] = "{$key} store client id missing.";
                    continue;
                }
            } elseif ($token === '') {
                if ($record === null) {
                    $issues[] = "{$key} store not installed (OAuth token missing). Run /shopify/auth/{$key}.";
                } else {
                    $issues[] = "{$key} store token missing. Run /shopify/reinstall/{$key}.";
                }

                continue;
            }

            $resolved[] = [
                'key' => $key,
                'shop' => $shop,
                'token' => $token,
                'source' => $base['source'] ?? null,
                'tenant_id' => $record?->tenant_id ? (int) $record->tenant_id : null,
                'secret' => $secret !== '' ? $secret : null,
                'client_id' => $clientId !== '' ? $clientId : null,
                'api_version' => $base['api_version'] ?? config('services.shopify.api_version', '2026-01'),
                'scopes' => $record?->scopes,
                'installed_at' => $record?->installed_at,
                'token_source' => $tokenSource,
                'storefront_widget_settings' => $record?->storefront_widget_settings,
            ];
        }

        return [$resolved, $issues];
    }

    /**
     * @param  array<string,array<string,mixed>>  $stores
     * @return array<int,string>
     */
    protected static function requestedStoreKeys(?string $storeKey, array $stores): array
    {
        if ($storeKey === null) {
            return self::defaultRequestedStoreKeys($stores);
        }

        $normalized = strtolower(trim($storeKey));
        if ($normalized === '' || $normalized === 'all') {
            return self::defaultRequestedStoreKeys($stores);
        }

        if (in_array($normalized, ['shopify_retail', 'retail'], true)) {
            return array_key_exists('retail', $stores) ? ['retail'] : [];
        }

        if (in_array($normalized, ['shopify_wholesale', 'wholesale'], true)) {
            return array_key_exists('wholesale', $stores) ? ['wholesale'] : [];
        }

        return [];
    }

    /**
     * @param  array<string,array<string,mixed>>  $stores
     * @return array<int,string>
     */
    protected static function defaultRequestedStoreKeys(array $stores): array
    {
        $available = array_keys($stores);
        if ($available === []) {
            return [];
        }

        $lookup = array_fill_keys($available, true);
        $active = array_values(array_filter(
            self::activeStoreKeys(),
            static fn (string $key): bool => isset($lookup[$key])
        ));

        return $active !== [] ? array_values(array_unique($active)) : $available;
    }

    /**
     * @param  array<int,string>  $keys
     * @return array<string,ShopifyStore>
     */
    protected static function storeRecordsByKey(array $keys): array
    {
        if ($keys === [] || !Schema::hasTable('shopify_stores')) {
            return [];
        }

        return ShopifyStore::query()
            ->whereIn('store_key', $keys)
            ->get()
            ->keyBy('store_key')
            ->all();
    }

    protected static function allowLegacyEnvTokenFallback(): bool
    {
        return (bool) config('services.shopify.allow_env_token_fallback', false);
    }

    /**
     * @return array<int,string>
     */
    protected static function configuredStoreKeys(): array
    {
        return array_keys(self::stores());
    }

    /**
     * @return array<int,string>
     */
    protected static function parseStoreKeyList(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $keys = [];

        foreach ($items as $item) {
            $normalized = self::normalizeStoreKeyValue($item);
            if ($normalized === null) {
                continue;
            }

            $keys[] = $normalized;
        }

        return array_values(array_unique($keys));
    }

    protected static function normalizeStoreKeyValue(mixed $value): ?string
    {
        if (! is_scalar($value) && $value !== null) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '' || $normalized === 'all') {
            return null;
        }

        return match ($normalized) {
            'shopify_retail' => 'retail',
            'shopify_wholesale' => 'wholesale',
            default => $normalized,
        };
    }

    protected static function normalizeDomain(string $shopDomain): string
    {
        $normalized = strtolower((string) preg_replace('#^https?://#', '', $shopDomain));

        return rtrim($normalized, '/');
    }
}
