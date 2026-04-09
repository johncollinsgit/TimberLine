<?php

namespace App\Services\Marketing;

use App\Models\MarketingReviewHistory;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class ShopifyProductReviewMetafieldService
{
    protected const METAFIELDS_SET_MUTATION = <<<'GRAPHQL'
mutation SetProductReviewMetafields($metafields: [MetafieldsSetInput!]!) {
  metafieldsSet(metafields: $metafields) {
    metafields {
      namespace
      key
      value
      type
    }
    userErrors {
      field
      message
      code
    }
  }
}
GRAPHQL;

    protected const PRODUCT_LOOKUP_QUERY = <<<'GRAPHQL'
query ProductReviewLookup($query: String!) {
  products(first: 1, query: $query) {
    nodes {
      id
      handle
      title
      onlineStoreUrl
    }
  }
}
GRAPHQL;

    protected const METAFIELD_NAMESPACE = 'forestry_reviews';

    protected const MAX_REVIEW_HIGHLIGHTS = 3;

    /**
     * @return array{updated:int,stores:array<int,string>,errors:array<int,string>,summary:array<string,mixed>}
     */
    public function syncReview(MarketingReviewHistory $review): array
    {
        $context = $this->reviewContext($review);
        if ($context === null) {
            return $this->result(0, [], [], [
                'state' => 'skipped',
                'reason' => 'missing_product_context',
            ]);
        }

        $store = ShopifyStores::find($context['store_key']);
        if (! $store) {
            return $this->result(0, [], [], [
                'state' => 'skipped',
                'reason' => 'store_not_configured',
                'store_key' => $context['store_key'],
            ]);
        }

        $shop = trim((string) ($store['shop'] ?? ''));
        $token = trim((string) ($store['token'] ?? ''));
        $apiVersion = trim((string) ($store['api_version'] ?? '')) ?: '2026-01';

        if ($shop === '' || $token === '') {
            return $this->result(0, [], [], [
                'state' => 'skipped',
                'reason' => 'store_credentials_missing',
                'store_key' => $context['store_key'],
            ]);
        }

        try {
            $client = new ShopifyGraphqlClient($shop, $token, $apiVersion);
            $shopifyProductId = $context['product_id'] ?? null;
            if ($shopifyProductId === null && filled($context['product_handle'] ?? null)) {
                $shopifyProductId = $this->resolveProductIdByHandle($client, (string) $context['product_handle']);
            }

            if ($shopifyProductId === null) {
                return $this->result(0, [], [], [
                    'state' => 'skipped',
                    'reason' => 'product_id_unresolved',
                    'store_key' => $context['store_key'],
                    'product_handle' => $context['product_handle'],
                ]);
            }

            $context['product_id'] = $shopifyProductId;
            $summary = $this->summaryForProduct($context);
            $metafields = $this->buildMetafieldsPayload($context, $summary);

            if ($metafields === []) {
                return $this->result(0, [], [], [
                    'state' => 'skipped',
                    'reason' => 'no_metafields_to_write',
                    'store_key' => $context['store_key'],
                    'product_id' => $shopifyProductId,
                ]);
            }

            $payload = $client->query(self::METAFIELDS_SET_MUTATION, [
                'metafields' => $metafields,
            ]);

            $setPayload = $payload['metafieldsSet'] ?? null;
            if (! is_array($setPayload)) {
                throw new RuntimeException('Shopify product review metafield mutation returned an invalid payload.');
            }

            $userErrors = $this->collectUserErrors($setPayload['userErrors'] ?? null);
            if ($userErrors !== []) {
                return $this->result(0, [trim($context['store_key'])], $userErrors, [
                    'state' => 'failed',
                    'reason' => 'shopify_user_errors',
                    'store_key' => $context['store_key'],
                    'product_id' => $shopifyProductId,
                    'summary' => $summary,
                ]);
            }

            return $this->result(1, [trim($context['store_key'])], [], [
                'state' => 'written',
                'store_key' => $context['store_key'],
                'product_id' => $shopifyProductId,
                'product_handle' => $context['product_handle'],
                'summary' => $summary,
            ]);
        } catch (\Throwable $e) {
            Log::warning('shopify product review metafield sync failed', [
                'review_id' => $review->id,
                'store_key' => $context['store_key'] ?? null,
                'product_id' => $context['product_id'] ?? null,
                'product_handle' => $context['product_handle'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return $this->result(0, [trim((string) ($context['store_key'] ?? ''))], [$e->getMessage()], [
                'state' => 'failed',
                'reason' => 'exception',
                'store_key' => $context['store_key'] ?? null,
                'product_id' => $context['product_id'] ?? null,
                'product_handle' => $context['product_handle'] ?? null,
            ]);
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function reviewContext(MarketingReviewHistory $review): ?array
    {
        $storeKey = $this->nullableString($review->store_key);
        if ($storeKey === null) {
            return null;
        }

        $rawProduct = (array) data_get($review->raw_payload, 'product', []);
        $rawImportProduct = (array) data_get($review->raw_payload, 'import.product', []);
        $rawProductUrl = $this->nullableString($review->product_url)
            ?: $this->nullableString(data_get($rawImportProduct, 'url'))
            ?: $this->nullableString(data_get($rawProduct, 'url'))
            ?: $this->nullableString(data_get($review->raw_payload, 'product_url'))
            ?: $this->nullableString(data_get($review->raw_payload, 'import.product_url'));

        $productId = $this->nullableString($review->product_id)
            ?: $this->nullableString(data_get($rawImportProduct, 'id'))
            ?: $this->nullableString(data_get($rawImportProduct, 'product_id'))
            ?: $this->nullableString(data_get($rawImportProduct, 'shopifyProductId'))
            ?: $this->nullableString(data_get($rawImportProduct, 'productId'))
            ?: $this->nullableString(data_get($rawProduct, 'id'))
            ?: $this->nullableString(data_get($rawProduct, 'product_id'))
            ?: $this->nullableString(data_get($rawProduct, 'shopifyProductId'))
            ?: $this->nullableString(data_get($rawProduct, 'productId'));

        $productHandle = $this->nullableString($review->product_handle)
            ?: $this->nullableString(data_get($rawImportProduct, 'handle'))
            ?: $this->nullableString(data_get($rawImportProduct, 'product_handle'))
            ?: $this->nullableString(data_get($rawProduct, 'handle'))
            ?: $this->nullableString(data_get($rawProduct, 'product_handle'))
            ?: $this->productHandleFromUrl($rawProductUrl);

        $productTitle = $this->nullableString($review->product_title)
            ?: $this->nullableString(data_get($rawImportProduct, 'title'))
            ?: $this->nullableString(data_get($rawImportProduct, 'product_title'))
            ?: $this->nullableString(data_get($rawProduct, 'title'))
            ?: $this->nullableString(data_get($rawProduct, 'product_title'));

        $productUrl = $this->nullableString($review->product_url)
            ?: $this->nullableString(data_get($rawImportProduct, 'url'))
            ?: $this->nullableString(data_get($rawProduct, 'url'))
            ?: $this->canonicalProductUrl([
                'product_id' => $productId,
                'product_handle' => $productHandle,
                'product_url' => null,
            ]);

        if ($productId === null && $productHandle === null) {
            return null;
        }

        return [
            'store_key' => $storeKey,
            'tenant_id' => $review->tenant_id ? (int) $review->tenant_id : null,
            'product_id' => $productId,
            'product_handle' => $productHandle,
            'product_title' => $productTitle,
            'product_url' => $productUrl,
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    protected function summaryForProduct(array $context): array
    {
        $query = $this->approvedReviewsQuery($context);
        $countQuery = clone $query;
        $averageQuery = clone $query;
        $reviews = $query
            ->with('profile:id,first_name,last_name,email')
            ->orderByDesc('approved_at')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->limit(self::MAX_REVIEW_HIGHLIGHTS)
            ->get();

        $reviewCount = (int) $countQuery->count();
        $averageRating = round((float) ($averageQuery->avg('rating') ?? 0), 1);

        return [
            'average_rating' => $averageRating,
            'review_count' => $reviewCount,
            'rating_label' => $reviewCount > 0
                ? number_format($averageRating, 1) . ' out of 5'
                : 'No reviews yet',
            'highlights' => $reviews
                ->map(fn (MarketingReviewHistory $review): array => $this->reviewHighlightPayload($review, $context))
                ->all(),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    protected function approvedReviewsQuery(array $context)
    {
        $storeKey = $this->nullableString($context['store_key'] ?? null);
        $tenantId = is_numeric($context['tenant_id'] ?? null) ? (int) $context['tenant_id'] : null;
        $productId = $this->nullableString($context['product_id'] ?? null);
        $productHandle = $this->nullableString($context['product_handle'] ?? null);

        return MarketingReviewHistory::query()
            ->when($tenantId !== null, fn ($query) => $query->where(function ($builder) use ($tenantId): void {
                $builder->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            }))
            ->when($storeKey !== null, fn ($query) => $query->where('store_key', $storeKey))
            ->where('status', 'approved')
            ->where('is_published', true)
            ->where(function ($query) use ($productId, $productHandle): void {
                if ($productId !== null) {
                    $query->where('product_id', $productId)
                        ->orWhere('raw_payload->product->id', $productId)
                        ->orWhere('raw_payload->product->shopifyProductId', $productId)
                        ->orWhere('raw_payload->product->productId', $productId)
                        ->orWhere('raw_payload->import->product->id', $productId)
                        ->orWhere('raw_payload->import->product->shopifyProductId', $productId)
                        ->orWhere('raw_payload->import->product->productId', $productId);
                }

                if ($productHandle !== null) {
                    $query->orWhere('product_handle', $productHandle)
                        ->orWhere('raw_payload->product->handle', $productHandle)
                        ->orWhere('raw_payload->import->product->handle', $productHandle);
                }
            });
    }

    /**
     * @param MarketingReviewHistory $review
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    protected function reviewHighlightPayload(MarketingReviewHistory $review, array $context): array
    {
        $canonicalUrl = $this->canonicalProductUrl([
            'product_id' => $this->nullableString($context['product_id'] ?? null),
            'product_handle' => $this->nullableString($context['product_handle'] ?? null),
            'product_url' => $this->nullableString($context['product_url'] ?? null),
        ]);

        return array_filter([
            'id' => (int) $review->id,
            'rating' => (int) $review->rating,
            'title' => $review->title ? (string) $review->title : null,
            'body' => $review->body ? (string) $review->body : null,
            'reviewer_name' => $review->displayReviewerName(),
            'approved_at' => optional($review->approved_at ?: $review->reviewed_at)->toIso8601String(),
            'published_at' => optional($review->published_at)->toIso8601String(),
            'product_id' => $this->nullableString($review->product_id) ?: $this->nullableString($context['product_id'] ?? null),
            'product_handle' => $this->nullableString($review->product_handle) ?: $this->nullableString($context['product_handle'] ?? null),
            'product_title' => $this->nullableString($review->product_title) ?: $this->nullableString($context['product_title'] ?? null),
            'product_url' => $this->nullableString($review->product_url) ?: $canonicalUrl,
            'is_verified_buyer' => (bool) $review->is_verified_buyer,
            'verified_purchase' => (bool) $review->is_verified_buyer,
            'helpful_count' => (int) ($review->votes ?? 0),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $summary
     * @return array<int,array{ownerId:string,namespace:string,key:string,type:string,value:string}>
     */
    protected function buildMetafieldsPayload(array $context, array $summary): array
    {
        $ownerId = 'gid://shopify/Product/' . (string) $context['product_id'];
        $productUrl = $this->canonicalProductUrl($context);

        $summaryPayload = [
            'product_id' => (string) $context['product_id'],
            'product_handle' => $this->nullableString($context['product_handle'] ?? null),
            'product_title' => $this->nullableString($context['product_title'] ?? null),
            'product_url' => $productUrl,
            'review_count' => (int) ($summary['review_count'] ?? 0),
            'average_rating' => (float) ($summary['average_rating'] ?? 0),
            'rating_label' => (string) ($summary['rating_label'] ?? 'No reviews yet'),
            'updated_at' => (string) ($summary['updated_at'] ?? now()->toIso8601String()),
        ];

        $rows = [
            [
                'ownerId' => $ownerId,
                'namespace' => self::METAFIELD_NAMESPACE,
                'key' => 'review_count',
                'type' => 'number_integer',
                'value' => (string) max(0, (int) ($summary['review_count'] ?? 0)),
            ],
            [
                'ownerId' => $ownerId,
                'namespace' => self::METAFIELD_NAMESPACE,
                'key' => 'average_rating',
                'type' => 'number_decimal',
                'value' => number_format(max(0, (float) ($summary['average_rating'] ?? 0)), 1, '.', ''),
            ],
            [
                'ownerId' => $ownerId,
                'namespace' => self::METAFIELD_NAMESPACE,
                'key' => 'review_summary',
                'type' => 'json',
                'value' => json_encode(array_merge($summaryPayload, [
                    'highlights' => array_values((array) ($summary['highlights'] ?? [])),
                ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
            [
                'ownerId' => $ownerId,
                'namespace' => self::METAFIELD_NAMESPACE,
                'key' => 'review_highlights',
                'type' => 'json',
                'value' => json_encode(array_values((array) ($summary['highlights'] ?? [])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
        ];

        return array_values(array_filter($rows, function (array $row): bool {
            return trim((string) ($row['value'] ?? '')) !== '';
        }));
    }

    /**
     * @param array<string,mixed> $context
     */
    protected function canonicalProductUrl(array $context): ?string
    {
        $url = $this->nullableString($context['product_url'] ?? null);
        if ($url) {
            if (Str::startsWith($url, ['http://', 'https://'])) {
                return $url;
            }

            return rtrim((string) config('marketing.candle_cash.storefront_base_url', 'https://theforestrystudio.com'), '/') . '/' . ltrim($url, '/');
        }

        if (filled($this->nullableString($context['product_handle'] ?? null))) {
            return rtrim((string) config('marketing.candle_cash.storefront_base_url', 'https://theforestrystudio.com'), '/') . '/products/' . ltrim((string) $context['product_handle'], '/');
        }

        return null;
    }

    protected function productHandleFromUrl(?string $url): ?string
    {
        $url = $this->nullableString($url);
        if ($url === null) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $url;

        if (preg_match('~/(?:products)/([^/?#]+)~', $path, $matches)) {
            return urldecode((string) $matches[1]);
        }

        if (preg_match('~^products/([^/?#]+)~', ltrim($path, '/'), $matches)) {
            return urldecode((string) $matches[1]);
        }

        return null;
    }

    protected function resolveProductIdByHandle(ShopifyGraphqlClient $client, string $handle): ?string
    {
        $query = $client->query(self::PRODUCT_LOOKUP_QUERY, [
            'query' => 'handle:' . $handle,
        ]);

        $nodes = data_get($query, 'products.nodes');
        if (! is_array($nodes) || $nodes === []) {
            return null;
        }

        $product = $nodes[0];
        if (! is_array($product)) {
            return null;
        }

        $gid = $this->nullableString($product['id'] ?? null);
        if ($gid === null || ! preg_match('#^gid://shopify/Product/(\d+)$#', $gid, $matches)) {
            return null;
        }

        return (string) $matches[1];
    }

    /**
     * @param mixed $errors
     * @return array<int,string>
     */
    protected function collectUserErrors(mixed $errors): array
    {
        if (! is_array($errors)) {
            return [];
        }

        $messages = [];
        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $message = trim((string) ($error['message'] ?? 'unknown_error'));
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * @param array<int,string> $stores
     * @param array<int,string> $errors
     * @param array<string,mixed> $summary
     * @return array{updated:int,stores:array<int,string>,errors:array<int,string>,summary:array<string,mixed>}
     */
    protected function result(int $updated, array $stores, array $errors, array $summary): array
    {
        return [
            'updated' => $updated,
            'stores' => array_values(array_unique(array_filter($stores, fn (string $value): bool => trim($value) !== ''))),
            'errors' => array_values(array_unique(array_filter($errors, fn (string $value): bool => trim($value) !== ''))),
            'summary' => $summary,
        ];
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
