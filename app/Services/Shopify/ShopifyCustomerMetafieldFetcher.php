<?php

namespace App\Services\Shopify;

use RuntimeException;

class ShopifyCustomerMetafieldFetcher
{
    protected const QUERY = <<<'GRAPHQL'
query FetchCustomerMetafields($first: Int!, $after: String) {
  customers(first: $first, after: $after, sortKey: ID) {
    edges {
      cursor
      node {
        id
        email
        phone
        firstName
        lastName
        updatedAt
        metafields(first: 250) {
          edges {
            node {
              namespace
              key
              value
              type
            }
          }
        }
      }
    }
    pageInfo {
      hasNextPage
      endCursor
    }
  }
}
GRAPHQL;

    public function __construct(
        protected ShopifyGraphqlClient $client
    ) {}

    /**
     * @return array{
     *   customers:array<int,array{
     *     gid:string,
     *     shopify_customer_id:string,
     *     email:?string,
     *     phone:?string,
     *     first_name:?string,
     *     last_name:?string,
     *     updated_at:?string,
     *     metafields:array<int,array{namespace:string,key:string,value:string,type:?string}>
     *   }>,
     *   has_next:bool,
     *   cursor:?string
     * }
     */
    public function fetchPage(?string $cursor = null, int $limit = 50): array
    {
        $data = $this->client->query(self::QUERY, [
            'first' => min(max($limit, 1), 100),
            'after' => $cursor,
        ]);

        $customers = $data['customers'] ?? null;
        if (! is_array($customers)) {
            throw new RuntimeException('Shopify GraphQL customers connection was missing.');
        }

        $edges = $customers['edges'] ?? null;
        if (! is_array($edges)) {
            throw new RuntimeException('Shopify GraphQL customers.edges was missing.');
        }

        $pageInfo = $customers['pageInfo'] ?? null;
        if (! is_array($pageInfo)) {
            throw new RuntimeException('Shopify GraphQL customers.pageInfo was missing.');
        }

        $items = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                throw new RuntimeException('Shopify GraphQL customer edge was malformed.');
            }

            $node = $edge['node'] ?? null;
            if (! is_array($node)) {
                throw new RuntimeException('Shopify GraphQL customer node was missing.');
            }

            $gid = trim((string) ($node['id'] ?? ''));
            if ($gid === '') {
                throw new RuntimeException('Shopify GraphQL customer node is missing id.');
            }

            $shopifyCustomerId = $this->legacyCustomerIdFromGid($gid);
            if ($shopifyCustomerId === null) {
                throw new RuntimeException("Unable to parse Shopify customer ID from gid '{$gid}'.");
            }

            $items[] = [
                'gid' => $gid,
                'shopify_customer_id' => $shopifyCustomerId,
                'email' => $this->nullableString($node['email'] ?? null),
                'phone' => $this->nullableString($node['phone'] ?? null),
                'first_name' => $this->nullableString($node['firstName'] ?? null),
                'last_name' => $this->nullableString($node['lastName'] ?? null),
                'order_count' => null,
                'last_order_at' => null,
                'accepts_marketing' => null,
                'updated_at' => $this->nullableString($node['updatedAt'] ?? null),
                'metafields' => $this->normalizeMetafields($node['metafields'] ?? null),
            ];
        }

        return [
            'customers' => $items,
            'has_next' => (bool) ($pageInfo['hasNextPage'] ?? false),
            'cursor' => $this->nullableString($pageInfo['endCursor'] ?? null),
        ];
    }

    protected function legacyCustomerIdFromGid(string $gid): ?string
    {
        if (! preg_match('#^gid://shopify/Customer/(\d+)$#', $gid, $matches)) {
            return null;
        }

        return $matches[1] ?? null;
    }

    /**
     * @return array<int,array{namespace:string,key:string,value:string,type:?string}>
     */
    protected function normalizeMetafields(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $edges = $payload['edges'] ?? [];
        if (! is_array($edges)) {
            return [];
        }

        $rows = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $node = $edge['node'] ?? null;
            if (! is_array($node)) {
                continue;
            }

            $namespace = trim((string) ($node['namespace'] ?? ''));
            $key = trim((string) ($node['key'] ?? ''));
            if ($namespace === '' || $key === '') {
                continue;
            }

            $rows[] = [
                'namespace' => $namespace,
                'key' => $key,
                'value' => (string) ($node['value'] ?? ''),
                'type' => $this->nullableString($node['type'] ?? null),
            ];
        }

        return $rows;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
