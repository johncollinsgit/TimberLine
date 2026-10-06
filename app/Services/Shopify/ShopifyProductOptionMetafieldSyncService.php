<?php

namespace App\Services\Shopify;

use App\Models\ShopifyProductOptionRuleset;
use App\Models\ShopifyStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ShopifyProductOptionMetafieldSyncService
{
    public const NAMESPACE = 'everbranch';

    public const KEY = 'bundle_scent_rule';

    private const PRODUCT_LOOKUP_QUERY = <<<'GRAPHQL'
query ProductOptionProductLookup($query: String!) {
  products(first: 1, query: $query) {
    nodes {
      id
      handle
      onlineStoreUrl
    }
  }
}
GRAPHQL;

    private const METAFIELDS_SET_MUTATION = <<<'GRAPHQL'
mutation SetProductOptionRule($metafields: [MetafieldsSetInput!]!) {
  metafieldsSet(metafields: $metafields) {
    metafields {
      ownerType
      namespace
      key
    }
    userErrors {
      field
      message
      code
    }
  }
}
GRAPHQL;

    private const METAFIELDS_DELETE_MUTATION = <<<'GRAPHQL'
mutation DeleteProductOptionRule($metafields: [MetafieldIdentifierInput!]!) {
  metafieldsDelete(metafields: $metafields) {
    deletedMetafields {
      ownerId
      namespace
      key
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

    /**
     * @param  array<int,string>  $previousHandles
     * @return array{synced:int,cleared:int,errors:array<int,string>}
     */
    public function syncRuleset(ShopifyProductOptionRuleset $ruleset, array $previousHandles = []): array
    {
        $ruleset->loadMissing('assignments');

        $currentHandles = $this->normalizeHandles(
            $ruleset->assignments->pluck('product_handle')->all()
        );
        $staleHandles = array_values(array_diff(
            $this->normalizeHandles($previousHandles),
            $currentHandles
        ));

        $result = ['synced' => 0, 'cleared' => 0, 'errors' => []];
        foreach ($this->storesForTenant((int) $ruleset->tenant_id) as $store) {
            $client = $this->client($store);

            foreach ($currentHandles as $handle) {
                try {
                    $product = $this->productByHandle($client, $handle);
                    if ($product === null) {
                        throw new RuntimeException("Shopify product '{$handle}' was not found.");
                    }

                    $this->setRule($client, (string) $product['id'], [
                        'ruleset_id' => (int) $ruleset->id,
                        'enabled' => (bool) $ruleset->enabled,
                        'option_count' => max(1, min(24, (int) $ruleset->option_count)),
                        'require_distinct_values' => (bool) $ruleset->require_distinct_values,
                        'allowed_values' => $this->normalizeValues((array) $ruleset->allowed_values),
                    ]);

                    $ruleset->assignments()
                        ->where('product_handle', $handle)
                        ->update([
                            'shopify_product_id' => $this->numericId((string) $product['id']),
                            'product_url' => $product['onlineStoreUrl']
                                ?: 'https://'.trim((string) $store->shop_domain).'/products/'.$handle,
                        ]);
                    $result['synced']++;
                } catch (\Throwable $exception) {
                    $result['errors'][] = $this->errorMessage($store, $handle, $exception);
                }
            }

            foreach ($staleHandles as $handle) {
                try {
                    $product = $this->productByHandle($client, $handle);
                    if ($product === null) {
                        continue;
                    }

                    $this->clearRule($client, (string) $product['id']);
                    $result['cleared']++;
                } catch (\Throwable $exception) {
                    $result['errors'][] = $this->errorMessage($store, $handle, $exception);
                }
            }
        }

        $result['errors'] = array_values(array_unique($result['errors']));
        if ($result['errors'] !== []) {
            Log::warning('shopify product option validation metafield sync incomplete', [
                'tenant_id' => (int) $ruleset->tenant_id,
                'ruleset_id' => (int) $ruleset->id,
                'errors' => $result['errors'],
            ]);
        }

        return $result;
    }

    /**
     * @param  array<int,string>  $handles
     * @return array{synced:int,cleared:int,errors:array<int,string>}
     */
    public function clearHandles(int $tenantId, array $handles): array
    {
        $handles = $this->normalizeHandles($handles);
        $result = ['synced' => 0, 'cleared' => 0, 'errors' => []];

        foreach ($this->storesForTenant($tenantId) as $store) {
            $client = $this->client($store);

            foreach ($handles as $handle) {
                try {
                    $product = $this->productByHandle($client, $handle);
                    if ($product === null) {
                        continue;
                    }

                    $this->clearRule($client, (string) $product['id']);
                    $result['cleared']++;
                } catch (\Throwable $exception) {
                    $result['errors'][] = $this->errorMessage($store, $handle, $exception);
                }
            }
        }

        $result['errors'] = array_values(array_unique($result['errors']));

        return $result;
    }

    /**
     * @return Collection<int,ShopifyStore>
     */
    private function storesForTenant(int $tenantId): Collection
    {
        return ShopifyStore::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('access_token')
            ->where('access_token', '!=', '')
            ->orderBy('id')
            ->get();
    }

    private function client(ShopifyStore $store): ShopifyGraphqlClient
    {
        return new ShopifyGraphqlClient(
            trim((string) $store->shop_domain),
            trim((string) $store->access_token),
            (string) config('services.shopify.api_version', '2026-01')
        );
    }

    /**
     * @return array{id:string,handle:string,onlineStoreUrl:?string}|null
     */
    private function productByHandle(ShopifyGraphqlClient $client, string $handle): ?array
    {
        $payload = $client->query(self::PRODUCT_LOOKUP_QUERY, [
            'query' => 'handle:'.$handle,
        ]);
        $product = data_get($payload, 'products.nodes.0');

        if (! is_array($product) || blank($product['id'] ?? null)) {
            return null;
        }

        return [
            'id' => (string) $product['id'],
            'handle' => (string) ($product['handle'] ?? $handle),
            'onlineStoreUrl' => filled($product['onlineStoreUrl'] ?? null)
                ? (string) $product['onlineStoreUrl']
                : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $rule
     */
    private function setRule(ShopifyGraphqlClient $client, string $productId, array $rule): void
    {
        $payload = $client->query(self::METAFIELDS_SET_MUTATION, [
            'metafields' => [[
                'ownerId' => $productId,
                'namespace' => self::NAMESPACE,
                'key' => self::KEY,
                'type' => 'json',
                'value' => json_encode($rule, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]],
        ]);

        $this->throwForUserErrors(data_get($payload, 'metafieldsSet.userErrors'));
    }

    private function clearRule(ShopifyGraphqlClient $client, string $productId): void
    {
        $payload = $client->query(self::METAFIELDS_DELETE_MUTATION, [
            'metafields' => [[
                'ownerId' => $productId,
                'namespace' => self::NAMESPACE,
                'key' => self::KEY,
            ]],
        ]);

        $this->throwForUserErrors(data_get($payload, 'metafieldsDelete.userErrors'));
    }

    private function throwForUserErrors(mixed $errors): void
    {
        if (! is_array($errors) || $errors === []) {
            return;
        }

        $messages = collect($errors)
            ->filter(fn ($error): bool => is_array($error))
            ->pluck('message')
            ->filter()
            ->map(fn ($message): string => trim((string) $message))
            ->unique()
            ->values()
            ->all();

        if ($messages !== []) {
            throw new RuntimeException(implode(' ', $messages));
        }
    }

    /**
     * @param  array<int,mixed>  $handles
     * @return array<int,string>
     */
    private function normalizeHandles(array $handles): array
    {
        return collect($handles)
            ->map(fn ($handle): string => strtolower(trim((string) $handle)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function normalizeValues(array $values): array
    {
        return collect($values)
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => strtolower($value))
            ->values()
            ->all();
    }

    private function numericId(string $gid): ?string
    {
        if (preg_match('#^gid://shopify/Product/(\d+)$#', $gid, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function errorMessage(ShopifyStore $store, string $handle, \Throwable $exception): string
    {
        return trim((string) $store->shop_domain).": {$handle}: ".$exception->getMessage();
    }
}
