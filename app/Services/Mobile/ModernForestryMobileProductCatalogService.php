<?php

namespace App\Services\Mobile;

use App\Models\Tenant;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use RuntimeException;

class ModernForestryMobileProductCatalogService
{
    public const TENANT_SLUG = 'modern-forestry';

    public const DEFAULT_LIMIT = 24;

    public const MAX_LIMIT = 250;

    public const PAGE_SIZE = 50;

    public const FEATURED_LIMIT = 6;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function products(int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = $this->normalizeLimit($limit);

        if ($this->fakeCatalogEnabled()) {
            return $this->fakeProducts($limit);
        }

        return $this->fetchAllProducts($limit);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function productDetail(string $handle): ?array
    {
        $handle = $this->normalizeHandle($handle);

        if ($handle === null) {
            return null;
        }

        if ($this->fakeCatalogEnabled()) {
            return $this->fakeProductDetail($handle);
        }

        $data = $this->client()->query($this->productDetailQuery(), [
            'query' => 'handle:'.$handle.' status:active',
        ]);

        $nodes = $data['products']['nodes'] ?? [];
        if (! is_array($nodes) || $nodes === []) {
            return null;
        }

        $node = $nodes[0] ?? [];

        return is_array($node) ? $this->mapProductDetail($node) : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function collections(): array
    {
        if ($this->fakeCatalogEnabled()) {
            return $this->fakeCollections();
        }

        $data = $this->client()->query($this->collectionsQuery());

        $nodes = $data['collections']['nodes'] ?? [];
        if (! is_array($nodes)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $node): array => $this->mapCollection(is_array($node) ? $node : []),
            $nodes
        ));
    }

    /**
     * @return array{collection:array<string,mixed>,products:array<int,array<string,mixed>>}|null
     */
    public function collectionProducts(string $handle, int $limit = self::DEFAULT_LIMIT): ?array
    {
        $handle = $this->normalizeHandle($handle);

        if ($handle === null) {
            return null;
        }

        $limit = $this->normalizeLimit($limit);

        if ($this->fakeCatalogEnabled()) {
            return $this->fakeCollectionProducts($handle, $limit);
        }

        $data = $this->client()->query($this->collectionProductsQuery(), [
            'query' => 'handle:'.$handle,
            'first' => $limit,
        ]);

        $nodes = $data['collections']['nodes'] ?? [];
        if (! is_array($nodes) || $nodes === []) {
            return null;
        }

        $collection = $nodes[0] ?? [];
        if (! is_array($collection)) {
            return null;
        }

        $productNodes = $collection['products']['nodes'] ?? [];
        $products = is_array($productNodes)
            ? array_values(array_map(
                fn (mixed $node): array => $this->mapProduct(is_array($node) ? $node : []),
                $productNodes
            ))
            : [];

        return [
            'collection' => $this->mapCollection($collection),
            'products' => $products,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function home(): array
    {
        $featuredCollections = array_slice($this->collections(), 0, 4);
        $featuredProducts = $this->featuredProducts();
        $featuredCandleClubProduct = $this->featuredCandleClubProduct();

        return [
            'hero' => [
                'eyebrow' => 'Modern Forestry',
                'title' => 'Candles, Candle Club, and rewards in one calm native home.',
                'subtitle' => 'Browse the retail catalog, discover Candle Club rituals, and jump into Candle Cash when you are ready.',
            ],
            'featuredCollections' => $featuredCollections,
            'featuredProducts' => $featuredProducts,
            'cards' => $this->homeCards(),
            'candleClub' => $this->candleClubPayload($featuredCandleClubProduct),
        ];
    }

    public function normalizeLimit(int $limit): int
    {
        return max(1, min($this->catalogMaxLimit(), $limit));
    }

    public function fakeCatalogEnabled(): bool
    {
        return app()->environment(['local', 'testing'])
            && (bool) config('mobile_catalog.fake_enabled', false);
    }

    protected function catalogDefaultLimit(): int
    {
        return max(1, (int) config('mobile_catalog.catalog.default_limit', self::DEFAULT_LIMIT));
    }

    protected function catalogMaxLimit(): int
    {
        return max($this->catalogDefaultLimit(), (int) config('mobile_catalog.catalog.max_limit', self::MAX_LIMIT));
    }

    protected function catalogPageSize(): int
    {
        return max(1, min(100, (int) config('mobile_catalog.catalog.page_size', self::PAGE_SIZE)));
    }

    protected function featuredLimit(): int
    {
        return max(1, min($this->catalogMaxLimit(), (int) config('mobile_catalog.catalog.featured_limit', self::FEATURED_LIMIT)));
    }

    protected function modernForestryTenant(): Tenant
    {
        $tenant = Tenant::query()
            ->where('slug', self::TENANT_SLUG)
            ->first();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException('Modern Forestry tenant is not configured.');
        }

        return $tenant;
    }

    /**
     * @return array<string,mixed>
     */
    protected function modernForestryRetailStore(Tenant $tenant): array
    {
        $store = ShopifyStores::find('retail');

        if (! is_array($store)) {
            throw new RuntimeException('Modern Forestry retail store is not installed.');
        }

        if ((int) ($store['tenant_id'] ?? 0) !== (int) $tenant->id) {
            throw new RuntimeException('Modern Forestry retail store is not configured for this tenant.');
        }

        if (trim((string) ($store['shop'] ?? '')) === '' || trim((string) ($store['token'] ?? '')) === '') {
            throw new RuntimeException('Modern Forestry retail store credentials are incomplete.');
        }

        return $store;
    }

    protected function client(): ShopifyGraphqlClient
    {
        $tenant = $this->modernForestryTenant();
        $store = $this->modernForestryRetailStore($tenant);

        return new ShopifyGraphqlClient(
            (string) $store['shop'],
            (string) $store['token'],
            (string) ($store['api_version'] ?? config('services.shopify.api_version', '2026-01'))
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function fetchAllProducts(int $limit): array
    {
        $client = $this->client();
        $products = [];
        $after = null;

        do {
            $remaining = $limit - count($products);
            if ($remaining <= 0) {
                break;
            }

            $batchSize = min($this->catalogPageSize(), $remaining);
            $data = $client->query($this->productsQuery(), [
                'first' => $batchSize,
                'after' => $after,
                'query' => 'status:active',
            ]);

            $productNodes = $data['products']['nodes'] ?? [];
            $pageInfo = $data['products']['pageInfo'] ?? [];

            if (! is_array($productNodes) || $productNodes === []) {
                break;
            }

            foreach ($productNodes as $node) {
                if (! is_array($node)) {
                    continue;
                }

                $products[] = $this->mapProduct($node);
            }

            $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);
            $after = is_scalar($pageInfo['endCursor'] ?? null)
                ? (string) $pageInfo['endCursor']
                : null;
        } while ($hasNextPage && $after !== null && count($products) < $limit);

        return array_slice($products, 0, $limit);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function featuredProducts(): array
    {
        if ($this->fakeCatalogEnabled()) {
            return array_slice($this->fakeProducts($this->featuredLimit()), 0, $this->featuredLimit());
        }

        $data = $this->client()->query($this->featuredProductsQuery(), [
            'first' => $this->featuredLimit(),
            'query' => 'status:active',
        ]);

        $nodes = $data['products']['nodes'] ?? [];
        if (! is_array($nodes)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $node): array => $this->mapProduct(is_array($node) ? $node : []),
            $nodes
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function featuredCandleClubProduct(): ?array
    {
        $handle = $this->candleClubProductHandle();

        if ($handle === '') {
            return null;
        }

        if ($this->fakeCatalogEnabled()) {
            return $this->fakeProductDetail($handle);
        }

        return $this->productDetail($handle);
    }

    protected function productsQuery(): string
    {
        return <<<'GRAPHQL'
query MobileCatalogProducts($first: Int!, $after: String, $query: String!) {
  products(first: $first, after: $after, sortKey: TITLE, reverse: false, query: $query) {
    nodes {
      id
      title
      handle
      productType
      tags
      status
      featuredImage {
        url
      }
      variants(first: 1) {
        nodes {
          price
          compareAtPrice
          availableForSale
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
    }

    protected function featuredProductsQuery(): string
    {
        return <<<'GRAPHQL'
query MobileCatalogFeaturedProducts($first: Int!, $query: String!) {
  products(first: $first, sortKey: BEST_SELLING, reverse: false, query: $query) {
    nodes {
      id
      title
      handle
      productType
      tags
      status
      featuredImage {
        url
      }
      variants(first: 1) {
        nodes {
          price
          compareAtPrice
          availableForSale
        }
      }
    }
  }
}
GRAPHQL;
    }

    protected function productDetailQuery(): string
    {
        return <<<'GRAPHQL'
query MobileCatalogProductDetail($query: String!) {
  products(first: 1, query: $query) {
    nodes {
      id
      title
      handle
      description
      descriptionHtml
      productType
      tags
      status
      images(first: 8) {
        nodes {
          url
          altText
        }
      }
      variants(first: 20) {
        nodes {
          id
          title
          price
          compareAtPrice
          availableForSale
        }
      }
    }
  }
}
GRAPHQL;
    }

    protected function collectionsQuery(): string
    {
        return <<<'GRAPHQL'
query MobileCatalogCollections {
  collections(first: 30, sortKey: TITLE, reverse: false) {
    nodes {
      handle
      title
      description
    }
  }
}
GRAPHQL;
    }

    protected function collectionProductsQuery(): string
    {
        return <<<'GRAPHQL'
query MobileCatalogCollectionProducts($query: String!, $first: Int!) {
  collections(first: 1, query: $query) {
    nodes {
      handle
      title
      description
      products(first: $first, sortKey: BEST_SELLING) {
        nodes {
          id
          title
          handle
          productType
          tags
          status
          featuredImage {
            url
          }
          variants(first: 1) {
            nodes {
              price
              compareAtPrice
              availableForSale
            }
          }
        }
      }
    }
  }
}
GRAPHQL;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function fakeProducts(int $limit): array
    {
        return array_slice($this->fakeCatalog(), 0, $limit);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function fakeCatalog(): array
    {
        $baseProducts = $this->fakeBaseProducts();

        $catalog = [];
        $formats = [
            ['suffix' => '', 'handle_suffix' => '', 'price_offset' => 0.00],
            ['suffix' => ' Travel Tin', 'handle_suffix' => '-travel-tin', 'price_offset' => -8.00],
            ['suffix' => ' Large Candle', 'handle_suffix' => '-large-candle', 'price_offset' => 10.00],
            ['suffix' => ' Gift Set', 'handle_suffix' => '-gift-set', 'price_offset' => 18.00],
            ['suffix' => ' Seasonal Batch', 'handle_suffix' => '-seasonal-batch', 'price_offset' => 4.00],
            ['suffix' => ' Studio Reserve', 'handle_suffix' => '-studio-reserve', 'price_offset' => 14.00],
            ['suffix' => ' Market Favorite', 'handle_suffix' => '-market-favorite', 'price_offset' => 2.00],
            ['suffix' => ' Refill Candle', 'handle_suffix' => '-refill-candle', 'price_offset' => -4.00],
            ['suffix' => ' Discovery Candle', 'handle_suffix' => '-discovery-candle', 'price_offset' => -6.00],
        ];

        foreach ($formats as $formatIndex => $format) {
            foreach ($baseProducts as $product) {
                if (count($catalog) >= $this->catalogMaxLimit()) {
                    break 2;
                }

                $price = (float) $product['price'] + (float) $format['price_offset'];
                $handle = $product['handle'].$format['handle_suffix'];

                $catalog[] = [
                    ...$this->fakeProductSummary($product, count($catalog) + 1),
                    'title' => $product['title'].$format['suffix'],
                    'handle' => $handle,
                    'url' => $this->productUrl($handle),
                    'price' => number_format(max(1, $price), 2, '.', ''),
                    'compareAtPrice' => $formatIndex === 0 ? $product['compareAtPrice'] : null,
                ];
            }
        }

        return $catalog;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function fakeProductDetail(string $handle): ?array
    {
        foreach ($this->fakeBaseProducts() as $index => $product) {
            if ($product['handle'] !== $handle) {
                continue;
            }

            $variantId = 'fake-modern-forestry-variant-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);

            return [
                'id' => 'fake-modern-forestry-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'title' => $product['title'],
                'handle' => $product['handle'],
                'url' => $this->productUrl($product['handle']),
                'description' => $product['description'],
                'descriptionHtml' => null,
                'images' => [],
                'variants' => [
                    [
                        'id' => $variantId,
                        'title' => 'Default Title',
                        'price' => $product['price'],
                        'compareAtPrice' => $product['compareAtPrice'],
                        'available' => true,
                    ],
                ],
                'price' => $product['price'],
                'compareAtPrice' => $product['compareAtPrice'],
                'available' => true,
                'productType' => $product['productType'],
                'tags' => $product['tags'],
                'scentNotes' => $product['scentNotes'],
                'isCandleClub' => (bool) ($product['isCandleClub'] ?? false),
            ];
        }

        return null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function fakeCollections(): array
    {
        return [
            [
                'handle' => 'bright-and-fresh',
                'title' => 'Bright + Fresh',
                'description' => 'Clean citrus, herbs, and gentle woods for a fresh lift.',
            ],
            [
                'handle' => 'candle-club',
                'title' => 'Candle Club',
                'description' => 'Monthly ritual candles, member perks, and a standing seasonal delivery.',
            ],
            [
                'handle' => 'cozy-home',
                'title' => 'Cozy Home',
                'description' => 'Warm, comforting scents for full tables, quiet corners, and everyday rituals.',
            ],
            [
                'handle' => 'winter',
                'title' => 'Winter Collection',
                'description' => 'Evergreen, ember, and soft wooded candles for cold nights and slow mornings.',
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function homeCards(): array
    {
        return [
            [
                'kind' => 'candle_club',
                'title' => 'Candle Club',
                'body' => 'Explore the monthly subscription ritual, member-only drops, and extra reward momentum.',
                'actionTitle' => 'Explore Candle Club',
                'url' => $this->candleClubJoinUrl(),
            ],
            [
                'kind' => 'candle_cash',
                'title' => 'Candle Cash',
                'body' => 'Earn rewards when you shop, review, refer a friend, and celebrate your birthday month.',
                'actionTitle' => 'View rewards',
                'url' => $this->rewardsUrl(),
            ],
            [
                'kind' => 'account',
                'title' => 'Account and orders',
                'body' => 'Jump to your website account for order history, saved perks, and subscription details.',
                'actionTitle' => 'Open account',
                'url' => $this->accountUrl(),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function candleClubPayload(?array $featuredProduct): array
    {
        return [
            'eyebrow' => 'Candle Club',
            'title' => 'Bring the monthly ritual home.',
            'body' => 'Candle Club pairs member-only scents with a steady delivery rhythm and bonus Candle Cash momentum.',
            'benefits' => [
                'Member-only scents and seasonal curation',
                'A standing subscription with simple web account management',
                'Bonus Candle Cash perks layered onto the rewards program',
            ],
            'joinTitle' => 'Explore Candle Club',
            'joinUrl' => $this->candleClubJoinUrl(),
            'rewardsTitle' => 'Open Candle Cash',
            'rewardsUrl' => $this->rewardsUrl(),
            'featuredProduct' => $featuredProduct,
        ];
    }

    /**
     * @return array{collection:array<string,mixed>,products:array<int,array<string,mixed>>}|null
     */
    protected function fakeCollectionProducts(string $handle, int $limit): ?array
    {
        $collection = collect($this->fakeCollections())
            ->first(fn (array $collection): bool => $collection['handle'] === $handle);

        if (! is_array($collection)) {
            return null;
        }

        $handlesByCollection = [
            'winter' => ['fraser-fir', 'hearthside', 'vanilla-birch'],
            'cozy-home' => ['oakmoss-amber', 'lavender-woods', 'hearthside', 'vanilla-birch'],
            'bright-and-fresh' => ['citrus-grove', 'lavender-woods', 'fraser-fir'],
            'candle-club' => ['modern-forestry-candle-club-16oz-subscription-with-gifts', 'hearthside', 'vanilla-birch'],
        ];

        $wantedHandles = $handlesByCollection[$handle] ?? [];
        $products = [];

        foreach ($this->fakeBaseProducts() as $index => $product) {
            if (! in_array($product['handle'], $wantedHandles, true)) {
                continue;
            }

            $products[] = $this->fakeProductSummary($product, $index + 1);
        }

        return [
            'collection' => $collection,
            'products' => array_slice($products, 0, $limit),
        ];
    }

    /**
     * @param  array<string,mixed>  $product
     * @return array<string,mixed>
     */
    protected function fakeProductSummary(array $product, int $idNumber): array
    {
        return [
            'id' => 'fake-modern-forestry-'.str_pad((string) $idNumber, 3, '0', STR_PAD_LEFT),
            'title' => $product['title'],
            'handle' => $product['handle'],
            'url' => $this->productUrl($product['handle']),
            'imageUrl' => null,
            'price' => $product['price'],
            'compareAtPrice' => $product['compareAtPrice'],
            'available' => true,
            'productType' => $product['productType'],
            'tags' => $product['tags'],
            'isCandleClub' => (bool) ($product['isCandleClub'] ?? false),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function fakeBaseProducts(): array
    {
        return [
            [
                'title' => 'Fraser Fir',
                'handle' => 'fraser-fir',
                'price' => '24.00',
                'compareAtPrice' => null,
                'tags' => ['evergreen', 'winter', 'fir'],
                'scentNotes' => ['Fraser fir', 'cedarwood', 'fresh snow'],
                'description' => 'A crisp evergreen candle with fresh-cut fir, cool winter air, and a soft wooded finish.',
                'productType' => 'Candle',
                'isCandleClub' => false,
            ],
            [
                'title' => 'Oakmoss + Amber',
                'handle' => 'oakmoss-amber',
                'price' => '26.00',
                'compareAtPrice' => null,
                'tags' => ['oakmoss', 'amber', 'earthy'],
                'scentNotes' => ['oakmoss', 'amber', 'tonka'],
                'description' => 'An earthy, softly polished candle with oakmoss, warm amber, and a grounded studio glow.',
                'productType' => 'Candle',
                'isCandleClub' => false,
            ],
            [
                'title' => 'Lavender Woods',
                'handle' => 'lavender-woods',
                'price' => '24.00',
                'compareAtPrice' => null,
                'tags' => ['lavender', 'woods', 'calm'],
                'scentNotes' => ['lavender', 'cedar', 'soft herbs'],
                'description' => 'A calm, woodsy lavender candle made for slow evenings, quiet rooms, and easy unwinding.',
                'productType' => 'Candle',
                'isCandleClub' => false,
            ],
            [
                'title' => 'Hearthside',
                'handle' => 'hearthside',
                'price' => '28.00',
                'compareAtPrice' => '32.00',
                'tags' => ['smoke', 'spice', 'cozy'],
                'scentNotes' => ['smoked wood', 'clove', 'warm embers'],
                'description' => 'A cozy fireside candle with gentle smoke, baking spice, and the warmth of a full house.',
                'productType' => 'Candle',
                'isCandleClub' => false,
            ],
            [
                'title' => 'Citrus Grove',
                'handle' => 'citrus-grove',
                'price' => '24.00',
                'compareAtPrice' => null,
                'tags' => ['citrus', 'bright', 'grove'],
                'scentNotes' => ['orange peel', 'green leaves', 'sunlit wood'],
                'description' => 'A bright citrus candle with fresh peel, leafy green notes, and a clean wooded base.',
                'productType' => 'Candle',
                'isCandleClub' => false,
            ],
            [
                'title' => 'Vanilla Birch',
                'handle' => 'vanilla-birch',
                'price' => '26.00',
                'compareAtPrice' => null,
                'tags' => ['vanilla', 'birch', 'soft'],
                'scentNotes' => ['vanilla bean', 'white birch', 'soft musk'],
                'description' => 'A soft, creamy candle with vanilla bean, pale birch, and a gentle everyday warmth.',
                'productType' => 'Candle',
                'isCandleClub' => false,
            ],
            [
                'title' => 'Modern Forestry Candle Club',
                'handle' => 'modern-forestry-candle-club-16oz-subscription-with-gifts',
                'price' => '36.00',
                'compareAtPrice' => null,
                'tags' => ['candle club', 'subscription', 'member favorite'],
                'scentNotes' => ['exclusive scent', 'monthly ritual', 'seasonal curation'],
                'description' => 'A standing Candle Club subscription with member-only scents, recurring deliveries, and extra perks.',
                'productType' => 'Subscription',
                'isCandleClub' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<string,mixed>
     */
    protected function mapProduct(array $node): array
    {
        $handle = trim((string) ($node['handle'] ?? ''));
        $variant = $this->firstVariant($node);
        $tags = $this->stringList($node['tags'] ?? []);
        $isCandleClub = $this->isCandleClubProduct($handle, $node['title'] ?? null, $node['productType'] ?? null, $tags);

        return [
            'id' => $this->publicId((string) ($node['id'] ?? '')),
            'title' => (string) ($node['title'] ?? ''),
            'handle' => $handle,
            'url' => $this->productUrl($handle),
            'imageUrl' => $this->imageUrl($node),
            'price' => $this->moneyString($variant['price'] ?? null),
            'compareAtPrice' => $this->moneyString($variant['compareAtPrice'] ?? null),
            'available' => array_key_exists('availableForSale', $variant)
                ? (bool) $variant['availableForSale']
                : strtoupper((string) ($node['status'] ?? 'ACTIVE')) === 'ACTIVE',
            'productType' => $this->nullableString($node['productType'] ?? null),
            'tags' => $tags,
            'isCandleClub' => $isCandleClub,
        ];
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<string,mixed>
     */
    protected function mapProductDetail(array $node): array
    {
        $summary = $this->mapProduct($node);
        $variants = $this->variantList($node);
        $firstVariant = $variants[0] ?? [];

        return [
            'id' => $summary['id'],
            'title' => $summary['title'],
            'handle' => $summary['handle'],
            'url' => $summary['url'],
            'description' => (string) ($node['description'] ?? ''),
            'descriptionHtml' => $this->nullableString($node['descriptionHtml'] ?? null),
            'images' => $this->imageList($node),
            'variants' => $variants,
            'price' => $this->moneyString($firstVariant['price'] ?? null) ?? $summary['price'],
            'compareAtPrice' => $this->moneyString($firstVariant['compareAtPrice'] ?? null),
            'available' => $summary['available'],
            'productType' => $summary['productType'],
            'tags' => $summary['tags'],
            'scentNotes' => $this->scentNotes($summary['tags']),
            'isCandleClub' => $summary['isCandleClub'],
        ];
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<string,mixed>
     */
    protected function mapCollection(array $node): array
    {
        return [
            'handle' => trim((string) ($node['handle'] ?? '')),
            'title' => (string) ($node['title'] ?? ''),
            'description' => (string) ($node['description'] ?? ''),
        ];
    }

    protected function publicId(string $gid): string
    {
        $parts = explode('/', trim($gid));
        $id = trim((string) end($parts));

        return $id !== '' ? $id : trim($gid);
    }

    protected function productUrl(string $handle): string
    {
        if ($handle === '') {
            return $this->storefrontBaseUrl().'/collections/all';
        }

        return $this->storefrontBaseUrl().'/products/'.rawurlencode($handle);
    }

    protected function rewardsUrl(): string
    {
        return $this->storefrontBaseUrl().'/pages/rewards';
    }

    protected function accountUrl(): string
    {
        return $this->storefrontBaseUrl().'/account';
    }

    protected function storefrontBaseUrl(): string
    {
        return rtrim((string) config('mobile_catalog.storefront_base_url', 'https://theforestrystudio.com'), '/');
    }

    protected function candleClubProductHandle(): string
    {
        return strtolower(trim((string) config('mobile_catalog.candle_club.product_handle', '')));
    }

    protected function candleClubJoinUrl(): string
    {
        $configured = trim((string) config('mobile_catalog.candle_club.join_path', ''));

        if ($configured === '') {
            return $this->productUrl($this->candleClubProductHandle());
        }

        if (preg_match('/\Ahttps?:\/\//i', $configured) === 1) {
            return $configured;
        }

        return $this->storefrontBaseUrl().'/'.ltrim($configured, '/');
    }

    /**
     * @param  array<string,mixed>  $node
     */
    protected function imageUrl(array $node): ?string
    {
        $featuredImage = $node['featuredImage'] ?? null;
        if (! is_array($featuredImage)) {
            return null;
        }

        return $this->nullableString($featuredImage['url'] ?? null);
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<string,mixed>
     */
    protected function firstVariant(array $node): array
    {
        $variants = $node['variants'] ?? null;
        $nodes = is_array($variants) ? ($variants['nodes'] ?? []) : [];

        if (! is_array($nodes)) {
            return [];
        }

        $variant = $nodes[0] ?? [];

        return is_array($variant) ? $variant : [];
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<int,array<string,mixed>>
     */
    protected function imageList(array $node): array
    {
        $images = $node['images'] ?? null;
        $nodes = is_array($images) ? ($images['nodes'] ?? []) : [];

        if (! is_array($nodes)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $image): ?array {
            if (! is_array($image)) {
                return null;
            }

            $url = $this->nullableString($image['url'] ?? null);
            if ($url === null) {
                return null;
            }

            return [
                'url' => $url,
                'altText' => $this->nullableString($image['altText'] ?? null),
            ];
        }, $nodes)));
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<int,array<string,mixed>>
     */
    protected function variantList(array $node): array
    {
        $variants = $node['variants'] ?? null;
        $nodes = is_array($variants) ? ($variants['nodes'] ?? []) : [];

        if (! is_array($nodes)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $variant): ?array {
            if (! is_array($variant)) {
                return null;
            }

            return [
                'id' => $this->publicId((string) ($variant['id'] ?? '')),
                'title' => (string) ($variant['title'] ?? ''),
                'price' => $this->moneyString($variant['price'] ?? null),
                'compareAtPrice' => $this->moneyString($variant['compareAtPrice'] ?? null),
                'available' => (bool) ($variant['availableForSale'] ?? true),
            ];
        }, $nodes)));
    }

    protected function moneyString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    protected function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value) && $value !== null) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    /**
     * @return array<int,string>
     */
    protected function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): ?string => is_scalar($value) && trim((string) $value) !== ''
                ? trim((string) $value)
                : null,
            $values
        )));
    }

    /**
     * @param  array<int,string>  $tags
     * @return array<int,string>
     */
    protected function scentNotes(array $tags): array
    {
        return array_values(array_slice(array_filter($tags, function (string $tag): bool {
            $normalized = strtolower($tag);

            return ! str_contains($normalized, 'sale')
                && ! str_contains($normalized, 'collection')
                && ! str_contains($normalized, 'thanksgiving')
                && ! str_contains($normalized, 'subscription')
                && ! str_contains($normalized, 'candle club');
        }), 0, 6));
    }

    /**
     * @param  array<int,string>  $tags
     */
    protected function isCandleClubProduct(string $handle, mixed $title, mixed $productType, array $tags): bool
    {
        $normalizedHandle = strtolower(trim($handle));
        $normalizedTitle = strtolower(trim((string) $title));
        $normalizedType = strtolower(trim((string) $productType));

        if ($normalizedHandle === $this->candleClubProductHandle()) {
            return true;
        }

        if (str_contains($normalizedHandle, 'candle-club')) {
            return true;
        }

        if (str_contains($normalizedTitle, 'candle club')) {
            return true;
        }

        if (str_contains($normalizedType, 'subscription')) {
            return true;
        }

        foreach ($tags as $tag) {
            $normalizedTag = strtolower($tag);

            if (str_contains($normalizedTag, 'candle club') || str_contains($normalizedTag, 'subscription')) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeHandle(string $handle): ?string
    {
        $normalized = strtolower(trim($handle));

        if ($normalized === '' || preg_match('/\A[a-z0-9][a-z0-9-]*\z/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }
}
