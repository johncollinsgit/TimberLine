<?php

namespace App\Services\Mobile;

use App\Models\Tenant;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use RuntimeException;

class ModernForestryMobileProductCatalogService
{
    public const TENANT_SLUG = 'modern-forestry';

    public const STOREFRONT_BASE_URL = 'https://modernforestry.theforestrystudio.com';

    public const MAX_LIMIT = 50;

    public const DEFAULT_LIMIT = 20;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function products(int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = $this->normalizeLimit($limit);

        if ($this->fakeCatalogEnabled()) {
            return $this->fakeProducts($limit);
        }

        $tenant = $this->modernForestryTenant();
        $store = $this->modernForestryRetailStore($tenant);

        $client = new ShopifyGraphqlClient(
            (string) $store['shop'],
            (string) $store['token'],
            (string) ($store['api_version'] ?? config('services.shopify.api_version', '2026-01'))
        );

        $data = $client->query($this->productsQuery(), [
            'first' => $limit,
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
    public function productDetail(string $handle): ?array
    {
        $handle = $this->normalizeHandle($handle);

        if ($handle === null) {
            return null;
        }

        if ($this->fakeCatalogEnabled()) {
            return $this->fakeProductDetail($handle);
        }

        $tenant = $this->modernForestryTenant();
        $store = $this->modernForestryRetailStore($tenant);

        $client = new ShopifyGraphqlClient(
            (string) $store['shop'],
            (string) $store['token'],
            (string) ($store['api_version'] ?? config('services.shopify.api_version', '2026-01'))
        );

        $data = $client->query($this->productDetailQuery(), [
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

        $tenant = $this->modernForestryTenant();
        $store = $this->modernForestryRetailStore($tenant);

        $client = new ShopifyGraphqlClient(
            (string) $store['shop'],
            (string) $store['token'],
            (string) ($store['api_version'] ?? config('services.shopify.api_version', '2026-01'))
        );

        $data = $client->query($this->collectionsQuery());

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

        $tenant = $this->modernForestryTenant();
        $store = $this->modernForestryRetailStore($tenant);

        $client = new ShopifyGraphqlClient(
            (string) $store['shop'],
            (string) $store['token'],
            (string) ($store['api_version'] ?? config('services.shopify.api_version', '2026-01'))
        );

        $data = $client->query($this->collectionProductsQuery(), [
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
        return [
            'hero' => [
                'eyebrow' => 'Modern Forestry',
                'title' => 'Hand-poured candles for a slower season.',
                'subtitle' => 'Small-batch scents, seasonal favorites, and Candle Cash rewards.',
            ],
            'featuredCollections' => array_slice($this->collections(), 0, 3),
            'featuredProducts' => $this->products(6),
            'cards' => $this->homeCards(),
        ];
    }

    public function normalizeLimit(int $limit): int
    {
        return max(1, min(self::MAX_LIMIT, $limit));
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

    protected function productsQuery(): string
    {
        return <<<'GRAPHQL'
query MobileCatalogProducts($first: Int!) {
  products(first: $first, sortKey: UPDATED_AT, reverse: true, query: "status:active") {
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
  collections(first: 20, sortKey: UPDATED_AT, reverse: true) {
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
                'handle' => 'winter',
                'title' => 'Winter Collection',
                'description' => 'Evergreen, ember, and soft wooded candles for cold nights and slow mornings.',
            ],
            [
                'handle' => 'cozy-home',
                'title' => 'Cozy Home',
                'description' => 'Warm, comforting scents for full tables, quiet corners, and everyday rituals.',
            ],
            [
                'handle' => 'bright-and-fresh',
                'title' => 'Bright + Fresh',
                'description' => 'Clean citrus, herbs, and gentle woods for a fresh lift.',
            ],
        ];
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

        return [
            'id' => $this->publicId((string) ($node['id'] ?? '')),
            'title' => (string) ($node['title'] ?? ''),
            'handle' => $handle,
            'url' => $this->productUrl($handle),
            'imageUrl' => $this->imageUrl($node),
            'price' => $this->moneyString($variant['price'] ?? null),
            'compareAtPrice' => $this->moneyString($variant['compareAtPrice'] ?? null),
            'available' => strtoupper((string) ($node['status'] ?? 'ACTIVE')) === 'ACTIVE',
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
            'available' => strtoupper((string) ($node['status'] ?? 'ACTIVE')) === 'ACTIVE',
            'productType' => $summary['productType'],
            'tags' => $summary['tags'],
            'scentNotes' => $this->scentNotes($summary['tags']),
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
            return self::STOREFRONT_BASE_URL.'/collections/all';
        }

        return self::STOREFRONT_BASE_URL.'/products/'.rawurlencode($handle);
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
