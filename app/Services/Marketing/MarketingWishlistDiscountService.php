<?php

namespace App\Services\Marketing;

use App\Models\MarketingWishlistOutreachQueue;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use RuntimeException;

class MarketingWishlistDiscountService
{
    protected const LOOKUP_BY_CODE_QUERY = <<<'GRAPHQL'
query WishlistDiscountByCode($code: String!) {
  codeDiscountNodeByCode(code: $code) {
    id
    codeDiscount {
      __typename
      ... on DiscountCodeBasic {
        title
        startsAt
        endsAt
      }
    }
  }
}
GRAPHQL;

    protected const CREATE_BASIC_DISCOUNT_MUTATION = <<<'GRAPHQL'
mutation WishlistDiscountCodeBasicCreate($basicCodeDiscount: DiscountCodeBasicInput!) {
  discountCodeBasicCreate(basicCodeDiscount: $basicCodeDiscount) {
    codeDiscountNode {
      id
      codeDiscount {
        __typename
        ... on DiscountCodeBasic {
          title
          startsAt
          endsAt
        }
      }
    }
    userErrors {
      field
      message
      code
    }
  }
}
GRAPHQL;

    /**
     * @return array{discount_id:?string,discount_node_id:?string,store_key:string,starts_at:?string,ends_at:?string}
     */
    public function ensureDiscountForQueue(MarketingWishlistOutreachQueue $queue): array
    {
        $offerCode = trim((string) $queue->offer_code);
        if ($offerCode === '') {
            throw new RuntimeException('Wishlist outreach is missing an offer code.');
        }

        $storeKey = trim((string) $queue->store_key);
        if ($storeKey === '') {
            throw new RuntimeException('Wishlist outreach is missing a Shopify store key.');
        }

        $store = ShopifyStores::find($storeKey);
        if (! $store) {
            throw new RuntimeException('The Shopify store for this wishlist outreach could not be resolved.');
        }

        if ($queue->tenant_id && (int) ($store['tenant_id'] ?? 0) > 0 && (int) $store['tenant_id'] !== (int) $queue->tenant_id) {
            throw new RuntimeException('The wishlist outreach store does not belong to the current tenant.');
        }

        $client = new ShopifyGraphqlClient(
            trim((string) ($store['shop'] ?? '')),
            trim((string) ($store['token'] ?? '')),
            trim((string) ($store['api_version'] ?? '')) ?: '2026-01'
        );

        $lookup = $client->query(self::LOOKUP_BY_CODE_QUERY, [
            'code' => $offerCode,
        ]);

        $existing = $this->discountIdentifiersFromPayload($lookup['codeDiscountNodeByCode'] ?? null);
        if ($existing !== null) {
            return [
                ...$existing,
                'store_key' => $storeKey,
            ];
        }

        $data = $client->query(self::CREATE_BASIC_DISCOUNT_MUTATION, [
            'basicCodeDiscount' => $this->basicDiscountInput($queue),
        ]);

        $payload = $data['discountCodeBasicCreate'] ?? null;
        if (! is_array($payload)) {
            throw new RuntimeException('Shopify wishlist discount create response was invalid.');
        }

        $errors = $this->extractUserErrors((array) ($payload['userErrors'] ?? []));
        if ($errors !== []) {
            throw new RuntimeException('Shopify wishlist discount create failed: ' . implode(' | ', $errors));
        }

        $created = $this->discountIdentifiersFromPayload($payload['codeDiscountNode'] ?? null);
        if ($created === null) {
            throw new RuntimeException('Shopify wishlist discount create did not return a discount identifier.');
        }

        return [
            ...$created,
            'store_key' => $storeKey,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function basicDiscountInput(MarketingWishlistOutreachQueue $queue): array
    {
        $offerType = strtolower(trim((string) $queue->offer_type));
        $offerValue = (float) $queue->offer_value;
        if ($offerValue <= 0) {
            throw new RuntimeException('Wishlist outreach offer value is missing or invalid.');
        }

        $productId = $this->numericProductId($queue->product_id);
        $items = $productId !== null
            ? ['products' => ['productsToAdd' => ['gid://shopify/Product/' . $productId]]]
            : ['all' => true];

        $value = $offerType === 'percent_off'
            ? ['percentage' => $offerValue / 100]
            : ['discountAmount' => [
                'amount' => number_format($offerValue, 2, '.', ''),
                'appliesOnEachItem' => false,
            ]];

        return [
            'title' => $this->discountTitle($queue),
            'code' => (string) $queue->offer_code,
            'startsAt' => optional($queue->created_at ?: now())->toIso8601String(),
            'endsAt' => now()->addDays(14)->toIso8601String(),
            'appliesOncePerCustomer' => true,
            'customerSelection' => ['all' => true],
            'customerGets' => [
                'items' => $items,
                'value' => $value,
            ],
        ];
    }

    protected function discountTitle(MarketingWishlistOutreachQueue $queue): string
    {
        $productTitle = trim((string) ($queue->product_title ?? 'Wishlist Product'));

        return 'Wishlist Offer · ' . $productTitle;
    }

    protected function numericProductId(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $numeric = (int) $value;

        return $numeric > 0 ? $numeric : null;
    }

    /**
     * @param mixed $payload
     * @return array{discount_id:?string,discount_node_id:?string,starts_at:?string,ends_at:?string}|null
     */
    protected function discountIdentifiersFromPayload(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $discountNodeId = trim((string) ($payload['id'] ?? ''));
        $discount = $payload['codeDiscount'] ?? null;
        if (! is_array($discount)) {
            return $discountNodeId !== '' ? [
                'discount_id' => null,
                'discount_node_id' => $discountNodeId,
                'starts_at' => null,
                'ends_at' => null,
            ] : null;
        }

        return [
            'discount_id' => trim((string) ($discount['id'] ?? '')) ?: null,
            'discount_node_id' => $discountNodeId !== '' ? $discountNodeId : null,
            'starts_at' => trim((string) ($discount['startsAt'] ?? '')) ?: null,
            'ends_at' => trim((string) ($discount['endsAt'] ?? '')) ?: null,
        ];
    }

    /**
     * @param array<int,mixed> $errors
     * @return array<int,string>
     */
    protected function extractUserErrors(array $errors): array
    {
        return collect($errors)
            ->map(function ($error): ?string {
                if (! is_array($error)) {
                    return null;
                }

                $field = collect((array) ($error['field'] ?? []))
                    ->filter()
                    ->implode('.');
                $message = trim((string) ($error['message'] ?? 'Unknown error'));

                return $field !== ''
                    ? $field . ': ' . $message
                    : $message;
            })
            ->filter()
            ->values()
            ->all();
    }
}
