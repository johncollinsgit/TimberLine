<?php

namespace App\Services\Mobile;

use App\Models\OrderLine;
use App\Models\Tenant;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class ModernForestryMobileProductCatalogService
{
    public const TENANT_SLUG = 'modern-forestry';

    public const STOREFRONT_BASE_URL = 'https://theforestrystudio.com';

    public const MAX_LIMIT = 50;

    public const DEFAULT_LIMIT = 20;

    public const THEME_INDEX_PATH = '/Users/johncollins/projects/modernforestry-live-theme/templates/index.json';

    public const DEFAULT_SORT = 'best_selling';

    protected const PRODUCT_IMAGE_WIDTH = 640;

    protected const COLLECTION_IMAGE_WIDTH = 900;

    protected const DETAIL_IMAGE_WIDTH = 1200;

    protected const HERO_IMAGE_WIDTH = 1200;

    /**
     * @var array<int,string>
     */
    public const SUPPORTED_SORTS = [
        'best_selling',
        'newest',
        'price_low_to_high',
        'price_high_to_low',
    ];

    /**
     * @var array<int,array<string,mixed>>
     */
    protected const SEASONAL_COLLECTIONS = [
        [
            'handle' => 'spring',
            'title' => 'Spring',
            'description' => 'Fresh florals, bright greens, and softer daylight scents.',
            'aliases' => ['spring', 'spring-collection', 'wholesale-spring-collection'],
            'fallback_image' => 'https://theforestrystudio.com/cdn/shop/files/bright-fuschia-spring-blossoms_638cad68-df20-4a7b-b482-68abb3beb3bf_1000x.jpg?v=1772645457',
        ],
        [
            'handle' => 'classic',
            'title' => 'Classic',
            'description' => 'The everyday candles people keep coming back for.',
            'aliases' => ['classic', 'classic-collection-1', 'candle-collection', 'wholesale-year-round-collection'],
            'fallback_image' => 'https://theforestrystudio.com/cdn/shop/files/magnolia-bloom-opening_1000x.jpg?v=1772646113',
        ],
        [
            'handle' => 'summer',
            'title' => 'Summer',
            'description' => 'Sunlit, airy, and easygoing favorites for warm days.',
            'aliases' => ['summer', 'summer-collection', 'wholesale-summer-collection', 'summer-one-day-sale'],
            'fallback_image' => 'https://theforestrystudio.com/cdn/shop/files/easter-mini-eggs_1000x.jpg?v=1772646038',
        ],
        [
            'handle' => 'holiday',
            'title' => 'Holiday',
            'description' => 'Seasonal candles for gatherings, gifts, and winter moods.',
            'aliases' => ['holiday', 'holiday-collection', 'thanksgiving-day-sale', 'wholesale-holiday-collection'],
            'fallback_image' => 'https://theforestrystudio.com/cdn/shop/files/bright-fuschia-spring-blossoms_638cad68-df20-4a7b-b482-68abb3beb3bf_1000x.jpg?v=1772645457',
        ],
        [
            'handle' => 'fall',
            'title' => 'Fall',
            'description' => 'Warm spices, woods, and deeper comfort notes.',
            'aliases' => ['fall', 'fall-collection', 'autumn-collection', 'wholesale-fall-candle-collection'],
            'fallback_image' => 'https://theforestrystudio.com/cdn/shop/files/magnolia-bloom-opening_1000x.jpg?v=1772646113',
        ],
        [
            'handle' => 'bundles',
            'title' => 'Bundles',
            'description' => 'Curated sets and giftable groupings with a little extra value.',
            'aliases' => ['bundles', 'candle-bundles', 'bundle-collection', 'sale-items'],
            'fallback_image' => 'https://theforestrystudio.com/cdn/shop/files/easter-mini-eggs_1000x.jpg?v=1772646038',
        ],
    ];

    /**
     * @return array<int,array<string,mixed>>
     */
    public function products(int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = $this->normalizeLimit($limit);

        if ($this->fakeCatalogEnabled()) {
            return $this->fakeProducts($limit);
        }

        $client = $this->client();

        $products = [];
        $after = null;

        do {
            $variables = [
                'first' => self::MAX_LIMIT,
            ];

            if ($after !== null) {
                $variables['after'] = $after;
            }

            $data = $client->query($this->productsQuery(), $variables);

            $connection = $data['products'] ?? [];
            $nodes = is_array($connection) ? ($connection['nodes'] ?? []) : [];
            if (is_array($nodes)) {
                foreach ($nodes as $node) {
                    if (! is_array($node) || ! $this->productNodeIsCustomerVisible($node)) {
                        continue;
                    }

                    $products[] = $this->mapProduct($node);

                    if (count($products) >= $limit) {
                        break 2;
                    }
                }
            }

            $pageInfo = is_array($connection) ? ($connection['pageInfo'] ?? []) : [];
            $after = $this->nullableString($pageInfo['endCursor'] ?? null);
            $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);
        } while ($hasNextPage && count($products) < $limit && $after !== null);

        return array_slice($products, 0, $limit);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function featuredProducts(int $limit = 6): array
    {
        $limit = $this->normalizeLimit($limit);

        if ($this->fakeCatalogEnabled()) {
            return $this->fakeProducts($limit);
        }

        $products = $this->topPurchasedProducts($limit);
        $seen = array_fill_keys(array_map(
            static fn (array $product): string => (string) ($product['id'] ?? ''),
            $products
        ), true);

        if (count($products) < $limit) {
            foreach ($this->products($limit) as $fallback) {
                $id = (string) ($fallback['id'] ?? '');
                if ($id !== '' && isset($seen[$id])) {
                    continue;
                }

                $products[] = $fallback;
                if ($id !== '') {
                    $seen[$id] = true;
                }

                if (count($products) >= $limit) {
                    break;
                }
            }
        }

        return array_slice($products, 0, $limit);
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

        $client = $this->client();

        $data = $client->query($this->productDetailQuery(), [
            'query' => 'handle:'.$handle.' status:active',
        ]);

        $nodes = $data['products']['nodes'] ?? [];
        if (! is_array($nodes) || $nodes === []) {
            return $this->fallbackProductDetail($client, $handle);
        }

        $node = $nodes[0] ?? [];

        if (! is_array($node) || ! $this->productNodeIsCustomerVisible($node)) {
            return $this->fallbackProductDetail($client, $handle);
        }

        return $this->mapProductDetail($node);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function collections(): array
    {
        if ($this->fakeCatalogEnabled()) {
            return $this->fakeCollections();
        }

        $client = $this->client();

        return $this->seasonalCollectionsFromNodes($this->collectionNodes($client));
    }

    /**
     * @return array{collection:array<string,mixed>,products:array<int,array<string,mixed>>}|null
     */
    public function collectionProducts(string $handle, int $limit = self::DEFAULT_LIMIT, string $sort = self::DEFAULT_SORT): ?array
    {
        $handle = $this->normalizeHandle($handle);

        if ($handle === null) {
            return null;
        }

        $limit = $this->normalizeLimit($limit);
        $sort = $this->normalizeSort($sort);

        if ($this->fakeCatalogEnabled()) {
            return $this->fakeCollectionProducts($handle, $limit, $sort);
        }

        $client = $this->client();
        $collectionNodes = $this->collectionNodes($client);
        $resolvedCollection = $this->resolveSeasonalCollectionNode($handle, $collectionNodes);
        $targetHandle = trim((string) ($resolvedCollection['handle'] ?? $handle));

        $sortArguments = $this->collectionSortArguments($sort);

        $data = null;
        $queriedHandle = $targetHandle;

        foreach ($this->collectionHandleCandidates($handle, $targetHandle) as $candidateHandle) {
            $data = $client->query($this->collectionProductsQuery(), [
                'query' => 'handle:'.$candidateHandle,
                'first' => min(max($limit * 4, 24), 100),
                'sortKey' => $sortArguments['sortKey'],
                'reverse' => $sortArguments['reverse'],
            ]);

            $nodes = $data['collections']['nodes'] ?? [];
            if (is_array($nodes) && $nodes !== []) {
                $queriedHandle = $candidateHandle;
                break;
            }
        }

        $nodes = is_array($data) ? ($data['collections']['nodes'] ?? []) : [];
        if (! is_array($nodes) || $nodes === []) {
            return null;
        }

        $collection = $nodes[0] ?? [];
        if (! is_array($collection)) {
            return null;
        }

        $productNodes = $collection['products']['nodes'] ?? [];
        $products = is_array($productNodes)
            ? array_values(array_filter(array_map(function (mixed $node): ?array {
                if (! is_array($node)) {
                    return null;
                }

                if (! $this->productNodeIsCustomerVisible($node)) {
                    return null;
                }

                return $this->mapProduct($node);
            }, $productNodes)))
            : [];

        $products = $this->sortProducts($products, $sort);
        $products = array_slice($products, 0, $limit);

        return [
            'collection' => $this->mapCollection(
                $collection,
                $this->seasonalDefinitionForHandle($handle) ?? $this->seasonalDefinitionForHandle($queriedHandle)
            ),
            'products' => $products,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function home(): array
    {
        $collections = $this->collections();

        return [
            'brand' => [
                'wordmark' => 'Modern Forestry',
                'tagline' => 'Soy candles',
                'logoUrl' => null,
            ],
            'hero' => [
                'eyebrow' => 'Modern Forestry',
                'title' => 'Hand-poured candles for a slower season.',
                'subtitle' => 'Small-batch scents, seasonal favorites, and Candle Cash rewards.',
                'logoUrl' => null,
                'wordmark' => 'Modern Forestry',
                'tagline' => 'Soy candles',
                'slides' => $this->homeHeroSlides(),
            ],
            'featuredCollections' => $collections,
            'featuredProducts' => $this->featuredProducts(6),
            'cards' => $this->homeCards(),
        ];
    }

    public function normalizeLimit(int $limit): int
    {
        return max(1, min(self::MAX_LIMIT, $limit));
    }

    public function normalizeSort(string $sort): string
    {
        $normalized = Str::of($sort)->trim()->lower()->replace(' ', '_')->toString();

        return in_array($normalized, self::SUPPORTED_SORTS, true)
            ? $normalized
            : self::DEFAULT_SORT;
    }

    public function fakeCatalogEnabled(): bool
    {
        return app()->environment(['local', 'testing'])
            && (bool) config('mobile_catalog.fake_enabled', false);
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

    protected function productsQuery(): string
    {
        return <<<'GRAPHQL'
query MobileCatalogProducts($first: Int!, $after: String) {
  products(first: $first, after: $after, sortKey: BEST_SELLING, reverse: false, query: "status:active") {
    nodes {
      id
      title
      handle
      publishedAt
      onlineStoreUrl
      productType
      tags
      status
      featuredImage {
        url
      }
      variants(first: 10) {
        nodes {
          id
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

    protected function productDetailFallbackQuery(): string
    {
        return <<<'GRAPHQL'
query MobileCatalogActiveProductDetails($first: Int!, $after: String) {
  products(first: $first, after: $after, sortKey: BEST_SELLING, reverse: false, query: "status:active") {
    nodes {
      id
      title
      handle
      description
      descriptionHtml
      publishedAt
      onlineStoreUrl
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
    pageInfo {
      hasNextPage
      endCursor
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
      publishedAt
      onlineStoreUrl
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
  collections(first: 40, sortKey: UPDATED_AT, reverse: true) {
    nodes {
      handle
      title
      description
      image {
        url
        altText
      }
      products(first: 8, sortKey: BEST_SELLING) {
        nodes {
          status
          publishedAt
          onlineStoreUrl
          featuredImage {
            url
          }
          variants(first: 10) {
            nodes {
              id
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

    protected function collectionProductsQuery(): string
    {
        return <<<'GRAPHQL'
query MobileCatalogCollectionProducts($query: String!, $first: Int!, $sortKey: ProductCollectionSortKeys!, $reverse: Boolean!) {
  collections(first: 1, query: $query) {
    nodes {
      handle
      title
      description
      image {
        url
        altText
      }
      products(first: $first, sortKey: $sortKey, reverse: $reverse) {
        nodes {
          id
          title
          handle
          createdAt
          publishedAt
          onlineStoreUrl
          productType
          tags
          status
          featuredImage {
            url
          }
          variants(first: 10) {
            nodes {
              id
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
                if (count($catalog) >= self::MAX_LIMIT) {
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
                'mobileSummary' => $this->buildMobileSummary($product['description']),
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
                'productType' => 'Candle',
                'tags' => $product['tags'],
                'scentNotes' => $product['scentNotes'],
                'faq' => [],
            ];
        }

        return null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function fakeCollections(): array
    {
        return array_values(array_map(
            fn (array $definition): array => [
                'handle' => $definition['handle'],
                'title' => $definition['title'],
                'description' => $definition['description'],
            'imageUrl' => $this->mobileImageUrl($definition['fallback_image'], self::COLLECTION_IMAGE_WIDTH),
            ],
            self::SEASONAL_COLLECTIONS
        ));
    }

    /**
     * @return array<int,array<string,string>>
     */
    protected function homeCards(): array
    {
        return [
            [
                'kind' => 'candle_cash',
                'title' => 'Earn Candle Cash',
                'body' => 'Earn rewards when you shop, review, and celebrate your birthday.',
                'actionTitle' => 'View rewards',
                'url' => self::STOREFRONT_BASE_URL.'/pages/rewards',
            ],
            [
                'kind' => 'wishlist',
                'title' => 'Save your favorites',
                'body' => 'Keep track of scents you love and come back when you are ready.',
                'actionTitle' => 'View wishlist',
                'url' => self::STOREFRONT_BASE_URL,
            ],
            [
                'kind' => 'reviews',
                'title' => 'Reviews',
                'body' => 'See what customers love and share your own Modern Forestry favorites.',
                'actionTitle' => 'Browse products',
                'url' => self::STOREFRONT_BASE_URL.'/collections/all',
            ],
        ];
    }

    /**
     * @return array{collection:array<string,mixed>,products:array<int,array<string,mixed>>}|null
     */
    protected function fakeCollectionProducts(string $handle, int $limit, string $sort): ?array
    {
        $collection = collect($this->fakeCollections())
            ->first(fn (array $collection): bool => $collection['handle'] === $handle);

        if (! is_array($collection)) {
            return null;
        }

        $handlesByCollection = [
            'spring' => ['citrus-grove', 'lavender-woods'],
            'classic' => ['oakmoss-amber', 'vanilla-birch'],
            'summer' => ['citrus-grove', 'vanilla-birch', 'lavender-woods'],
            'holiday' => ['fraser-fir', 'hearthside'],
            'fall' => ['oakmoss-amber', 'hearthside', 'vanilla-birch'],
            'bundles' => ['fraser-fir', 'oakmoss-amber', 'hearthside', 'citrus-grove', 'vanilla-birch', 'lavender-woods'],
        ];

        $wantedHandles = $handlesByCollection[$handle] ?? [];
        $products = [];
        $catalogByHandle = [];

        foreach ($this->fakeBaseProducts() as $index => $product) {
            $catalogByHandle[$product['handle']] = $this->fakeProductSummary($product, $index + 1);
        }

        foreach ($wantedHandles as $wantedHandle) {
            if (isset($catalogByHandle[$wantedHandle])) {
                $products[] = $catalogByHandle[$wantedHandle];
            }
        }

        $products = $this->sortProducts($products, $sort);

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
            'createdAt' => now()->subDays(max($idNumber - 1, 0))->toIso8601String(),
            'price' => $product['price'],
            'compareAtPrice' => $product['compareAtPrice'],
            'available' => true,
            'variantId' => 'fake-modern-forestry-variant-'.str_pad((string) $idNumber, 3, '0', STR_PAD_LEFT),
            'productType' => 'Candle',
            'tags' => $product['tags'],
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
            ],
            [
                'title' => 'Oakmoss + Amber',
                'handle' => 'oakmoss-amber',
                'price' => '26.00',
                'compareAtPrice' => null,
                'tags' => ['oakmoss', 'amber', 'earthy'],
                'scentNotes' => ['oakmoss', 'amber', 'tonka'],
                'description' => 'An earthy, softly polished candle with oakmoss, warm amber, and a grounded studio glow.',
            ],
            [
                'title' => 'Lavender Woods',
                'handle' => 'lavender-woods',
                'price' => '24.00',
                'compareAtPrice' => null,
                'tags' => ['lavender', 'woods', 'calm'],
                'scentNotes' => ['lavender', 'cedar', 'soft herbs'],
                'description' => 'A calm, woodsy lavender candle made for slow evenings, quiet rooms, and easy unwinding.',
            ],
            [
                'title' => 'Hearthside',
                'handle' => 'hearthside',
                'price' => '28.00',
                'compareAtPrice' => '32.00',
                'tags' => ['smoke', 'spice', 'cozy'],
                'scentNotes' => ['smoked wood', 'clove', 'warm embers'],
                'description' => 'A cozy fireside candle with gentle smoke, baking spice, and the warmth of a full house.',
            ],
            [
                'title' => 'Citrus Grove',
                'handle' => 'citrus-grove',
                'price' => '24.00',
                'compareAtPrice' => null,
                'tags' => ['citrus', 'bright', 'grove'],
                'scentNotes' => ['orange peel', 'green leaves', 'sunlit wood'],
                'description' => 'A bright citrus candle with fresh peel, leafy green notes, and a clean wooded base.',
            ],
            [
                'title' => 'Vanilla Birch',
                'handle' => 'vanilla-birch',
                'price' => '26.00',
                'compareAtPrice' => null,
                'tags' => ['vanilla', 'birch', 'soft'],
                'scentNotes' => ['vanilla bean', 'white birch', 'soft musk'],
                'description' => 'A soft, creamy candle with vanilla bean, pale birch, and a gentle everyday warmth.',
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
        $variantId = $this->publicId((string) ($variant['id'] ?? ''));

        return [
            'id' => $this->publicId((string) ($node['id'] ?? '')),
            'title' => (string) ($node['title'] ?? ''),
            'handle' => $handle,
            'url' => $this->productUrl($handle),
            'imageUrl' => $this->imageUrl($node, self::PRODUCT_IMAGE_WIDTH),
            'createdAt' => $this->nullableString($node['createdAt'] ?? null),
            'price' => $this->moneyString($variant['price'] ?? null),
            'compareAtPrice' => $this->moneyString($variant['compareAtPrice'] ?? null),
            'available' => $this->productNodeIsCustomerVisible($node),
            'variantId' => $variantId !== '' ? $variantId : null,
            'productType' => $this->nullableString($node['productType'] ?? null),
            'tags' => $this->stringList($node['tags'] ?? []),
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
        $description = (string) ($node['description'] ?? '');

        return [
            'id' => $summary['id'],
            'title' => $summary['title'],
            'handle' => $summary['handle'],
            'url' => $summary['url'],
            'description' => $description,
            'descriptionHtml' => $this->nullableString($node['descriptionHtml'] ?? null),
            'mobileSummary' => $this->buildMobileSummary($description),
            'images' => $this->imageList($node),
            'variants' => $variants,
            'price' => $this->moneyString($firstVariant['price'] ?? null) ?? $summary['price'],
            'compareAtPrice' => $this->moneyString($firstVariant['compareAtPrice'] ?? null),
            'available' => $this->productNodeIsCustomerVisible($node),
            'productType' => $summary['productType'],
            'tags' => $summary['tags'],
            'scentNotes' => $this->scentNotes($summary['tags']),
            'faq' => $this->productFaq($node),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function fallbackProductDetail(ShopifyGraphqlClient $client, string $handle): ?array
    {
        $after = null;

        do {
            $variables = [
                'first' => self::MAX_LIMIT,
            ];

            if ($after !== null) {
                $variables['after'] = $after;
            }

            $data = $client->query($this->productDetailFallbackQuery(), $variables);

            $connection = $data['products'] ?? [];
            $nodes = is_array($connection) ? ($connection['nodes'] ?? []) : [];

            if (is_array($nodes)) {
                foreach ($nodes as $node) {
                    if (! is_array($node)) {
                        continue;
                    }

                    if (Str::lower(trim((string) ($node['handle'] ?? ''))) !== $handle) {
                        continue;
                    }

                    if (! $this->productNodeIsCustomerVisible($node)) {
                        continue;
                    }

                    return $this->mapProductDetail($node);
                }
            }

            $pageInfo = is_array($connection) ? ($connection['pageInfo'] ?? []) : [];
            $after = $this->nullableString($pageInfo['endCursor'] ?? null);
            $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);
        } while ($hasNextPage && $after !== null);

        return null;
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<string,mixed>
     */
    protected function mapCollection(array $node, ?array $definition = null): array
    {
        $handle = trim((string) ($node['handle'] ?? ''));

        return [
            'handle' => (string) ($definition['handle'] ?? $handle),
            'title' => (string) ($definition['title'] ?? ($node['title'] ?? '')),
            'description' => (string) ($definition['description'] ?? ($node['description'] ?? '')),
            'imageUrl' => $this->collectionImageUrl($node)
                ?? $this->mobileImageUrl($definition['fallback_image'] ?? null, self::COLLECTION_IMAGE_WIDTH),
        ];
    }

    /**
     * @param  array<string,mixed>  $node
     */
    protected function collectionImageUrl(array $node): ?string
    {
        $image = $node['image'] ?? null;
        if (is_array($image)) {
            $imageUrl = $this->nullableString($image['url'] ?? null);
            if ($imageUrl !== null) {
                return $this->mobileImageUrl($imageUrl, self::COLLECTION_IMAGE_WIDTH);
            }
        }

        $products = $node['products'] ?? null;
        $productNodes = is_array($products) ? ($products['nodes'] ?? []) : [];
        if (! is_array($productNodes) || $productNodes === []) {
            return null;
        }

        foreach ($productNodes as $productNode) {
            if (! is_array($productNode) || ! $this->productNodeIsCustomerVisible($productNode)) {
                continue;
            }

            $featuredImage = $productNode['featuredImage'] ?? null;
            if (! is_array($featuredImage)) {
                continue;
            }

            $url = $this->nullableString($featuredImage['url'] ?? null);
            if ($url !== null) {
                return $this->mobileImageUrl($url, self::COLLECTION_IMAGE_WIDTH);
            }
        }

        return null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function homeHeroSlides(): array
    {
        if (! File::exists(self::THEME_INDEX_PATH)) {
            return $this->fallbackHeroSlides();
        }

        $payload = json_decode((string) File::get(self::THEME_INDEX_PATH), true);
        if (! is_array($payload)) {
            return $this->fallbackHeroSlides();
        }

        $slideshow = $payload['sections']['slideshow'] ?? null;
        if (! is_array($slideshow)) {
            return $this->fallbackHeroSlides();
        }

        $blocks = is_array($slideshow['blocks'] ?? null) ? $slideshow['blocks'] : [];
        $order = is_array($slideshow['block_order'] ?? null) ? $slideshow['block_order'] : array_keys($blocks);

        $slides = [];

        foreach ($order as $blockId) {
            $block = $blocks[$blockId] ?? null;
            if (! is_array($block)) {
                continue;
            }

            $settings = is_array($block['settings'] ?? null) ? $block['settings'] : [];
            $title = trim((string) ($settings['title'] ?? ''));
            $imageUrl = $this->themeImageUrl($settings['image'] ?? null);

            if ($title === '' || $imageUrl === null) {
                continue;
            }

            $slides[] = [
                'id' => (string) $blockId,
                'title' => $title,
                'subtitle' => $this->nullableString($settings['subheading'] ?? null),
                'imageUrl' => $this->mobileImageUrl($imageUrl, self::HERO_IMAGE_WIDTH),
                'mobileImageUrl' => $this->mobileImageUrl($this->themeImageUrl($settings['mobile_image'] ?? null) ?? $imageUrl, self::HERO_IMAGE_WIDTH),
                'ctaTitle' => $this->nullableString($settings['button_1_text'] ?? null),
                'ctaUrl' => $this->storefrontUrl($settings['button_1_link'] ?? null),
                'secondaryCtaTitle' => $this->nullableString($settings['button_2_text'] ?? null),
                'secondaryCtaUrl' => $this->storefrontUrl($settings['button_2_link'] ?? null),
            ];
        }

        return $slides !== [] ? $slides : $this->fallbackHeroSlides();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function fallbackHeroSlides(): array
    {
        return [
            [
                'id' => 'spring-collection',
                'title' => 'Shop our Spring Collection',
                'subtitle' => null,
                'imageUrl' => $this->mobileImageUrl(self::STOREFRONT_BASE_URL.'/cdn/shop/files/bright-fuschia-spring-blossoms_638cad68-df20-4a7b-b482-68abb3beb3bf_1000x.jpg?v=1772645457', self::HERO_IMAGE_WIDTH),
                'mobileImageUrl' => $this->mobileImageUrl(self::STOREFRONT_BASE_URL.'/cdn/shop/files/bright-fuschia-spring-blossoms_638cad68-df20-4a7b-b482-68abb3beb3bf_1000x.jpg?v=1772645457', self::HERO_IMAGE_WIDTH),
                'ctaTitle' => 'Click to Shop',
                'ctaUrl' => self::STOREFRONT_BASE_URL.'/collections/spring-collection',
                'secondaryCtaTitle' => null,
                'secondaryCtaUrl' => null,
            ],
            [
                'id' => 'candle-cash',
                'title' => 'Earn $5 in Candle Cash',
                'subtitle' => null,
                'imageUrl' => $this->mobileImageUrl(self::STOREFRONT_BASE_URL.'/cdn/shop/files/easter-mini-eggs_1000x.jpg?v=1772646038', self::HERO_IMAGE_WIDTH),
                'mobileImageUrl' => $this->mobileImageUrl(self::STOREFRONT_BASE_URL.'/cdn/shop/files/easter-mini-eggs_1000x.jpg?v=1772646038', self::HERO_IMAGE_WIDTH),
                'ctaTitle' => 'Join for $5',
                'ctaUrl' => self::STOREFRONT_BASE_URL.'/pages/rewards?task=email-signup',
                'secondaryCtaTitle' => null,
                'secondaryCtaUrl' => null,
            ],
            [
                'id' => 'candle-club',
                'title' => 'Candle Club',
                'subtitle' => null,
                'imageUrl' => $this->mobileImageUrl(self::STOREFRONT_BASE_URL.'/cdn/shop/files/magnolia-bloom-opening_1000x.jpg?v=1772646113', self::HERO_IMAGE_WIDTH),
                'mobileImageUrl' => $this->mobileImageUrl(self::STOREFRONT_BASE_URL.'/cdn/shop/files/magnolia-bloom-opening_1000x.jpg?v=1772646113', self::HERO_IMAGE_WIDTH),
                'ctaTitle' => 'Join to get exclusive scents!',
                'ctaUrl' => self::STOREFRONT_BASE_URL.'/products/modern-forestry-candle-club-16oz-subscription-with-gifts?selling_plan=11300438275',
                'secondaryCtaTitle' => null,
                'secondaryCtaUrl' => null,
            ],
        ];
    }

    protected function themeImageUrl(mixed $reference): ?string
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return null;
        }

        if (Str::startsWith($reference, ['http://', 'https://'])) {
            return $reference;
        }

        if (Str::startsWith($reference, 'shopify://shop_images/')) {
            $filename = rawurlencode(Str::after($reference, 'shopify://shop_images/'));

            return self::STOREFRONT_BASE_URL.'/cdn/shop/files/'.$filename;
        }

        if (Str::startsWith($reference, '/')) {
            return self::STOREFRONT_BASE_URL.$reference;
        }

        return self::STOREFRONT_BASE_URL.'/'.ltrim($reference, '/');
    }

    protected function storefrontUrl(mixed $reference): ?string
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return null;
        }

        if (Str::startsWith($reference, ['http://', 'https://'])) {
            return $reference;
        }

        if (Str::startsWith($reference, 'shopify://collections/')) {
            return self::STOREFRONT_BASE_URL.'/collections/'.rawurlencode(Str::after($reference, 'shopify://collections/'));
        }

        if (Str::startsWith($reference, 'shopify://products/')) {
            return self::STOREFRONT_BASE_URL.'/products/'.rawurlencode(Str::after($reference, 'shopify://products/'));
        }

        if (Str::startsWith($reference, '/')) {
            return self::STOREFRONT_BASE_URL.$reference;
        }

        return self::STOREFRONT_BASE_URL.'/'.ltrim($reference, '/');
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
            return self::STOREFRONT_BASE_URL.'/collections/all';
        }

        return self::STOREFRONT_BASE_URL.'/products/'.rawurlencode($handle);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function topPurchasedProducts(int $limit): array
    {
        $tenant = $this->modernForestryTenant();
        $ids = $this->topPurchasedProductIds($tenant, min(max($limit * 4, 24), 50));

        if ($ids === []) {
            return [];
        }

        $nodes = $this->productNodesByIds($this->client(), $ids);
        $products = [];

        foreach ($ids as $id) {
            $node = $nodes[$id] ?? null;
            if (! is_array($node) || ! $this->productNodeIsCustomerVisible($node)) {
                continue;
            }

            $products[] = $this->mapProduct($node);

            if (count($products) >= $limit) {
                break;
            }
        }

        return $products;
    }

    /**
     * @return array<int,string>
     */
    protected function topPurchasedProductIds(Tenant $tenant, int $limit): array
    {
        if (! Schema::hasTable('order_lines') || ! Schema::hasTable('orders')) {
            return [];
        }

        $quantitySql = Schema::hasColumn('order_lines', 'quantity')
            ? 'coalesce(order_lines.quantity, order_lines.ordered_qty, 0)'
            : 'coalesce(order_lines.ordered_qty, 0)';

        $query = OrderLine::query()
            ->select('order_lines.shopify_product_id')
            ->selectRaw('sum('.$quantitySql.') as purchased_quantity')
            ->whereNotNull('order_lines.shopify_product_id')
            ->whereHas('order', function ($orderQuery) use ($tenant): void {
                if (Schema::hasColumn('orders', 'tenant_id')) {
                    $orderQuery->where('tenant_id', $tenant->id);
                }

                if (Schema::hasColumn('orders', 'shopify_store_key')) {
                    $orderQuery->where(function ($storeQuery): void {
                        $storeQuery->whereNull('shopify_store_key')
                            ->orWhere('shopify_store_key', 'retail');
                    });
                } elseif (Schema::hasColumn('orders', 'shopify_store')) {
                    $orderQuery->where(function ($storeQuery): void {
                        $storeQuery->whereNull('shopify_store')
                            ->orWhere('shopify_store', 'retail');
                    });
                }

                if (Schema::hasColumn('orders', 'cancelled_at')) {
                    $orderQuery->whereNull('cancelled_at');
                }
            })
            ->groupBy('order_lines.shopify_product_id')
            ->orderByDesc(DB::raw('purchased_quantity'))
            ->limit($limit);

        return $query
            ->pluck('order_lines.shopify_product_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->filter(static fn (string $id): bool => $id !== '' && ctype_digit($id))
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $ids
     * @return array<string,array<string,mixed>>
     */
    protected function productNodesByIds(ShopifyGraphqlClient $client, array $ids): array
    {
        $gids = array_values(array_unique(array_map(
            static fn (string $id): string => 'gid://shopify/Product/'.$id,
            $ids
        )));

        if ($gids === []) {
            return [];
        }

        $data = $client->query($this->productsByIdsQuery(), [
            'ids' => $gids,
        ]);

        $nodes = $data['nodes'] ?? [];
        if (! is_array($nodes)) {
            return [];
        }

        $products = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $id = $this->publicId((string) ($node['id'] ?? ''));
            if ($id !== '') {
                $products[$id] = $node;
            }
        }

        return $products;
    }

    protected function productsByIdsQuery(): string
    {
        return <<<'GRAPHQL'
query MobileCatalogProductsByIds($ids: [ID!]!) {
  nodes(ids: $ids) {
    ... on Product {
      id
      title
      handle
      createdAt
      publishedAt
      onlineStoreUrl
      productType
      tags
      status
      featuredImage {
        url
      }
      variants(first: 10) {
        nodes {
          id
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

    /**
     * @return array<int,string>
     */
    protected function collectionHandleCandidates(string $requestedHandle, string $resolvedHandle): array
    {
        $definition = $this->seasonalDefinitionForHandle($requestedHandle) ?? $this->seasonalDefinitionForHandle($resolvedHandle);
        $handles = [$resolvedHandle, $requestedHandle];

        if (is_array($definition)) {
            $handles = array_merge($handles, [$definition['handle']], (array) ($definition['aliases'] ?? []));
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $handle): string => trim((string) $handle),
            $handles
        ), static fn (string $handle): bool => $handle !== '')));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function collectionNodes(ShopifyGraphqlClient $client): array
    {
        $data = $client->query($this->collectionsQuery());
        $nodes = $data['collections']['nodes'] ?? [];

        return is_array($nodes) ? $nodes : [];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<int,array<string,mixed>>
     */
    protected function seasonalCollectionsFromNodes(array $nodes): array
    {
        $collections = [];

        foreach (self::SEASONAL_COLLECTIONS as $definition) {
            $match = $this->resolveSeasonalCollectionNode((string) $definition['handle'], $nodes);

            if (is_array($match)) {
                $collections[] = $this->mapCollection($match, $definition);
                continue;
            }

            $collections[] = [
                'handle' => $definition['handle'],
                'title' => $definition['title'],
                'description' => $definition['description'],
                'imageUrl' => $this->mobileImageUrl($definition['fallback_image'], self::COLLECTION_IMAGE_WIDTH),
            ];
        }

        return $collections;
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<string,mixed>|null
     */
    protected function resolveSeasonalCollectionNode(string $handle, array $nodes): ?array
    {
        $definition = $this->seasonalDefinitionForHandle($handle);

        if ($definition === null) {
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }

                if (Str::lower(trim((string) ($node['handle'] ?? ''))) === Str::lower($handle)) {
                    return $node;
                }
            }

            return null;
        }

        $aliases = array_map(
            static fn (mixed $alias): string => Str::lower(trim((string) $alias)),
            $definition['aliases'] ?? []
        );

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $nodeHandle = Str::lower(trim((string) ($node['handle'] ?? '')));
            $nodeTitle = Str::lower(trim((string) ($node['title'] ?? '')));

            if (in_array($nodeHandle, $aliases, true)
                || Str::contains($nodeHandle, $aliases)
                || Str::contains($nodeTitle, Str::lower((string) $definition['title']))) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function seasonalDefinitionForHandle(string $handle): ?array
    {
        $normalized = Str::lower(trim($handle));

        foreach (self::SEASONAL_COLLECTIONS as $definition) {
            $aliases = array_map(
                static fn (mixed $alias): string => Str::lower(trim((string) $alias)),
                $definition['aliases'] ?? []
            );

            if ($normalized === Str::lower((string) $definition['handle']) || in_array($normalized, $aliases, true)) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $node
     */
    protected function imageUrl(array $node, int $width = self::PRODUCT_IMAGE_WIDTH): ?string
    {
        $featuredImage = $node['featuredImage'] ?? null;
        if (! is_array($featuredImage)) {
            return null;
        }

        return $this->mobileImageUrl($featuredImage['url'] ?? null, $width);
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

        foreach ($nodes as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            if (! array_key_exists('availableForSale', $variant) || (bool) $variant['availableForSale']) {
                return $variant;
            }
        }

        $variant = $nodes[0] ?? [];

        return is_array($variant) ? $variant : [];
    }

    protected function productNodeIsActive(array $node): bool
    {
        return Str::upper((string) ($node['status'] ?? 'ACTIVE')) === 'ACTIVE';
    }

    /**
     * Shopify's admin API may expose draft/archived status, publication fields,
     * and variant sellability depending on the query. Treat present visibility
     * signals as authoritative while preserving older test payloads that omit them.
     *
     * @param  array<string,mixed>  $node
     */
    protected function productNodeIsCustomerVisible(array $node): bool
    {
        if (! $this->productNodeIsActive($node)) {
            return false;
        }

        $hasPublicationSignal = array_key_exists('publishedAt', $node)
            || array_key_exists('onlineStoreUrl', $node);

        if ($hasPublicationSignal
            && $this->nullableString($node['publishedAt'] ?? null) === null
            && $this->nullableString($node['onlineStoreUrl'] ?? null) === null) {
            return false;
        }

        $variants = $node['variants'] ?? null;
        if (! is_array($variants) || ! array_key_exists('nodes', $variants)) {
            return true;
        }

        $variantNodes = $variants['nodes'];
        if (! is_array($variantNodes) || $variantNodes === []) {
            return false;
        }

        foreach ($variantNodes as $variantNode) {
            if (! is_array($variantNode)) {
                continue;
            }

            if (! array_key_exists('availableForSale', $variantNode) || (bool) $variantNode['availableForSale']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{sortKey:string,reverse:bool}
     */
    protected function collectionSortArguments(string $sort): array
    {
        return match ($this->normalizeSort($sort)) {
            'newest' => ['sortKey' => 'CREATED', 'reverse' => true],
            'price_low_to_high' => ['sortKey' => 'PRICE', 'reverse' => false],
            'price_high_to_low' => ['sortKey' => 'PRICE', 'reverse' => true],
            default => ['sortKey' => 'BEST_SELLING', 'reverse' => false],
        };
    }

    protected function mobileImageUrl(mixed $value, int $width): ?string
    {
        $url = $this->nullableString($value);
        if ($url === null) {
            return null;
        }

        if (! Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        if (preg_match('/([?&])width=\d+/i', $url) === 1) {
            return preg_replace('/([?&])width=\d+/i', '$1width='.$width, $url) ?: $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'width='.$width;
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
                'url' => $this->mobileImageUrl($url, self::DETAIL_IMAGE_WIDTH),
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

    protected function buildMobileSummary(string $description): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($description)) ?? '');
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= 160) {
            return $text;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [];
        $summary = '';

        foreach ($sentences as $sentence) {
            $candidate = trim($summary === '' ? $sentence : $summary.' '.$sentence);
            if ($candidate === '') {
                continue;
            }

            if (mb_strlen($candidate) > 170) {
                break;
            }

            $summary = $candidate;

            if (mb_strlen($summary) >= 120) {
                break;
            }
        }

        if ($summary !== '') {
            return $summary;
        }

        return Str::limit($text, 157, '...');
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<int,array{question:string,answer:string}>
     */
    protected function productFaq(array $node): array
    {
        if (! $this->isCandleClubProduct($node)) {
            return [];
        }

        return [
            [
                'question' => 'What is Candle Club?',
                'answer' => 'Candle Club is the recurring Modern Forestry subscription with member-only candle access and storefront-linked perks.',
            ],
            [
                'question' => 'How do rewards work with Candle Club?',
                'answer' => 'Candle Cash and member benefits stay connected to the same live storefront account you use for checkout, rewards, and account access.',
            ],
            [
                'question' => 'Where do I manage my subscription?',
                'answer' => 'After checkout, use your account experience to review future orders, saved addresses, and subscription changes.',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $node
     */
    protected function isCandleClubProduct(array $node): bool
    {
        $title = Str::lower(trim((string) ($node['title'] ?? '')));
        $handle = Str::lower(trim((string) ($node['handle'] ?? '')));
        $productType = Str::lower(trim((string) ($node['productType'] ?? '')));
        $tags = Str::lower(implode(' ', $this->stringList($node['tags'] ?? [])));
        $haystack = trim($title.' '.$handle.' '.$productType.' '.$tags);

        return Str::contains($haystack, ['candle club', 'subscription']);
    }

    /**
     * @param  array<int,array<string,mixed>>  $products
     * @return array<int,array<string,mixed>>
     */
    protected function sortProducts(array $products, string $sort): array
    {
        return match ($sort) {
            'newest' => collect($products)
                ->sortByDesc(fn (array $product): string => (string) ($product['createdAt'] ?? ''))
                ->values()
                ->all(),
            'price_low_to_high' => collect($products)
                ->sortBy(fn (array $product): float => (float) ($product['price'] ?? 0))
                ->values()
                ->all(),
            'price_high_to_low' => collect($products)
                ->sortByDesc(fn (array $product): float => (float) ($product['price'] ?? 0))
                ->values()
                ->all(),
            default => array_values($products),
        };
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
                && ! str_contains($normalized, 'thanksgiving');
        }), 0, 6));
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
