<?php

use App\Models\ShopifyStore;
use App\Models\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('mobile_catalog.fake_enabled', false);
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.stores.retail.shop', 'modernforestry-test.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'mobile-test-client');
    config()->set('services.shopify.stores.retail.client_secret', 'mobile-test-secret');
    config()->set('services.shopify.allow_env_token_fallback', false);

    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'modernforestry-test.myshopify.com',
        'access_token' => 'shpat_mobile_test_token',
        'scopes' => 'read_products',
        'installed_at' => now(),
    ]);
});

test('fake mobile catalog returns 200 in local or testing when enabled', function (): void {
    config()->set('mobile_catalog.fake_enabled', true);
    ShopifyStore::query()->delete();
    Tenant::query()->delete();

    $response = $this->getJson('/api/mobile/v1/modern-forestry/products?limit=6');

    $response
        ->assertOk()
        ->assertJsonPath('meta.tenant', 'modern-forestry')
        ->assertJsonPath('meta.count', 6)
        ->assertJsonPath('meta.source', 'fake')
        ->assertJsonPath('data.0.title', 'Fraser Fir')
        ->assertJsonPath('data.1.title', 'Oakmoss + Amber')
        ->assertJsonPath('data.2.title', 'Lavender Woods')
        ->assertJsonPath('data.3.title', 'Hearthside')
        ->assertJsonPath('data.4.title', 'Citrus Grove')
        ->assertJsonPath('data.5.title', 'Vanilla Birch');
});

test('fake mobile catalog response has expected data and meta shape', function (): void {
    config()->set('mobile_catalog.fake_enabled', true);
    ShopifyStore::query()->delete();
    Tenant::query()->delete();

    $this->getJson('/api/mobile/v1/modern-forestry/products?limit=1')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'title',
                    'handle',
                    'url',
                    'imageUrl',
                    'price',
                    'compareAtPrice',
                    'available',
                    'productType',
                    'tags',
                ],
            ],
            'meta' => [
                'tenant',
                'count',
                'source',
            ],
        ])
        ->assertJsonPath('data.0.url', 'https://theforestrystudio.com/products/fraser-fir')
        ->assertJsonPath('data.0.imageUrl', null)
        ->assertJsonPath('data.0.price', '24.00')
        ->assertJsonPath('data.0.compareAtPrice', null)
        ->assertJsonPath('data.0.available', true)
        ->assertJsonPath('data.0.productType', 'Candle');
});

test('fake mobile catalog respects limit and caps at 50', function (): void {
    config()->set('mobile_catalog.fake_enabled', true);
    ShopifyStore::query()->delete();
    Tenant::query()->delete();

    $this->getJson('/api/mobile/v1/modern-forestry/products?limit=3')
        ->assertOk()
        ->assertJsonPath('meta.count', 3);

    $this->getJson('/api/mobile/v1/modern-forestry/products?limit=500')
        ->assertOk()
        ->assertJsonPath('meta.count', 50);
});

test('fake mobile collections list returns testing collections', function (): void {
    config()->set('mobile_catalog.fake_enabled', true);
    ShopifyStore::query()->delete();
    Tenant::query()->delete();

    $this->getJson('/api/mobile/v1/modern-forestry/collections')
        ->assertOk()
        ->assertJsonStructure([
            'collections' => [
                '*' => [
                    'handle',
                    'title',
                    'description',
                    'imageUrl',
                ],
            ],
        ])
        ->assertJsonPath('collections.0.handle', 'spring')
        ->assertJsonPath('collections.0.title', 'Spring')
        ->assertJsonPath('collections.1.handle', 'classic')
        ->assertJsonPath('collections.2.handle', 'summer')
        ->assertJsonPath('collections.3.handle', 'holiday')
        ->assertJsonPath('collections.4.handle', 'fall')
        ->assertJsonPath('collections.5.handle', 'bundles');
});

test('fake mobile collection products returns products for known handle', function (): void {
    config()->set('mobile_catalog.fake_enabled', true);
    ShopifyStore::query()->delete();
    Tenant::query()->delete();

    $this->getJson('/api/mobile/v1/modern-forestry/collections/spring/products?limit=2')
        ->assertOk()
        ->assertJsonPath('collection.handle', 'spring')
        ->assertJsonPath('collection.title', 'Spring')
        ->assertJsonPath('products.0.title', 'Citrus Grove')
        ->assertJsonPath('products.0.handle', 'citrus-grove')
        ->assertJsonPath('products.0.url', 'https://theforestrystudio.com/products/citrus-grove')
        ->assertJsonPath('products.0.imageUrl', null)
        ->assertJsonPath('products.0.price', '24.00')
        ->assertJsonPath('products.0.available', true)
        ->assertJsonPath('products.1.title', 'Lavender Woods')
        ->assertJsonCount(2, 'products');
});

test('fake mobile collection products returns 404 for unknown handle', function (): void {
    config()->set('mobile_catalog.fake_enabled', true);
    ShopifyStore::query()->delete();
    Tenant::query()->delete();

    $this->getJson('/api/mobile/v1/modern-forestry/collections/unknown/products')
        ->assertNotFound()
        ->assertJsonPath('collection', null)
        ->assertJsonPath('products', [])
        ->assertJsonPath('error.code', 'collection_not_found');
});

test('fake mobile home endpoint returns hero featured content and cards', function (): void {
    config()->set('mobile_catalog.fake_enabled', true);
    ShopifyStore::query()->delete();
    Tenant::query()->delete();

    $this->getJson('/api/mobile/v1/modern-forestry/home')
        ->assertOk()
        ->assertJsonStructure([
            'brand' => [
                'wordmark',
                'tagline',
                'logoUrl',
            ],
            'hero' => [
                'eyebrow',
                'title',
                'subtitle',
                'logoUrl',
                'wordmark',
                'tagline',
                'slides' => [
                    '*' => [
                        'id',
                        'title',
                        'subtitle',
                        'imageUrl',
                        'mobileImageUrl',
                        'ctaTitle',
                        'ctaUrl',
                        'secondaryCtaTitle',
                        'secondaryCtaUrl',
                    ],
                ],
            ],
            'featuredCollections' => [
                '*' => [
                    'handle',
                    'title',
                    'description',
                ],
            ],
            'featuredProducts' => [
                '*' => [
                    'id',
                    'title',
                    'handle',
                    'url',
                    'imageUrl',
                    'price',
                    'compareAtPrice',
                    'available',
                    'productType',
                    'tags',
                ],
            ],
            'cards' => [
                '*' => [
                    'kind',
                    'title',
                    'body',
                    'actionTitle',
                    'url',
                ],
            ],
        ])
        ->assertJsonPath('brand.wordmark', 'Modern Forestry')
        ->assertJsonPath('hero.eyebrow', 'Modern Forestry')
        ->assertJsonPath('hero.title', 'Hand-poured candles for a slower season.')
        ->assertJsonPath('hero.slides.0.title', 'Shop our Spring Collection')
        ->assertJsonPath('featuredCollections.0.handle', 'spring')
        ->assertJsonPath('featuredProducts.0.handle', 'fraser-fir')
        ->assertJsonPath('cards.0.kind', 'candle_cash')
        ->assertJsonPath('cards.0.url', 'https://theforestrystudio.com/pages/rewards');
});

test('fake mobile home response references valid known collection and product handles', function (): void {
    config()->set('mobile_catalog.fake_enabled', true);
    ShopifyStore::query()->delete();
    Tenant::query()->delete();

    $payload = $this->getJson('/api/mobile/v1/modern-forestry/home')
        ->assertOk()
        ->json();

    $knownCollectionHandles = ['spring', 'classic', 'summer', 'holiday', 'fall', 'bundles'];
    $knownProductHandles = ['fraser-fir', 'oakmoss-amber', 'lavender-woods', 'hearthside', 'citrus-grove', 'vanilla-birch'];

    foreach ($payload['featuredCollections'] as $collection) {
        expect($collection['handle'])->toBeIn($knownCollectionHandles);
    }

    foreach ($payload['featuredProducts'] as $product) {
        expect($product['handle'])->toBeIn($knownProductHandles);
    }
});

test('mobile session status reports signed out by default and can honor a session hint', function (): void {
    $this->getJson('/api/mobile/v1/modern-forestry/session-status')
        ->assertOk()
        ->assertJsonPath('authenticated', false)
        ->assertJsonPath('state', 'signed_out')
        ->assertJsonPath('sessionHint', false);

    $this->getJson('/api/mobile/v1/modern-forestry/session-status?session_hint=1')
        ->assertOk()
        ->assertJsonPath('authenticated', true)
        ->assertJsonPath('state', 'authenticated')
        ->assertJsonPath('sessionHint', true);
});

test('fake mobile product detail returns 200 for a known handle', function (): void {
    config()->set('mobile_catalog.fake_enabled', true);
    ShopifyStore::query()->delete();
    Tenant::query()->delete();

    $this->getJson('/api/mobile/v1/modern-forestry/products/fraser-fir')
        ->assertOk()
        ->assertJsonPath('meta.tenant', 'modern-forestry')
        ->assertJsonPath('meta.source', 'fake')
        ->assertJsonPath('data.title', 'Fraser Fir')
        ->assertJsonPath('data.handle', 'fraser-fir')
        ->assertJsonPath('data.url', 'https://theforestrystudio.com/products/fraser-fir')
        ->assertJsonPath('data.description', 'A crisp evergreen candle with fresh-cut fir, cool winter air, and a soft wooded finish.')
        ->assertJsonPath('data.mobileSummary', 'A crisp evergreen candle with fresh-cut fir, cool winter air, and a soft wooded finish.')
        ->assertJsonPath('data.price', '24.00')
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.scentNotes.0', 'Fraser fir');
});

test('fake mobile product detail response has expected shape', function (): void {
    config()->set('mobile_catalog.fake_enabled', true);
    ShopifyStore::query()->delete();
    Tenant::query()->delete();

    $this->getJson('/api/mobile/v1/modern-forestry/products/hearthside')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'id',
                'title',
                'handle',
                'url',
                'description',
                'descriptionHtml',
                'mobileSummary',
                'images' => [
                    '*' => [
                        'url',
                        'altText',
                    ],
                ],
                'variants' => [
                    '*' => [
                        'id',
                        'title',
                        'price',
                        'compareAtPrice',
                        'available',
                    ],
                ],
                'price',
                'compareAtPrice',
                'available',
                'productType',
                'tags',
                'scentNotes',
                'faq',
            ],
            'meta' => [
                'tenant',
                'source',
            ],
        ])
        ->assertJsonPath('data.compareAtPrice', '32.00')
        ->assertJsonPath('data.images', [])
        ->assertJsonPath('data.variants.0.title', 'Default Title')
        ->assertJsonPath('data.productType', 'Candle');
});

test('fake mobile product detail returns 404 for unknown handle', function (): void {
    config()->set('mobile_catalog.fake_enabled', true);
    ShopifyStore::query()->delete();
    Tenant::query()->delete();

    $this->getJson('/api/mobile/v1/modern-forestry/products/unknown-candle')
        ->assertNotFound()
        ->assertJsonPath('meta.tenant', 'modern-forestry')
        ->assertJsonPath('error.code', 'product_not_found');
});

test('fake mobile catalog is not used when disabled', function (): void {
    config()->set('mobile_catalog.fake_enabled', false);

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyMobileCatalogPayload(), 200),
    ]);

    $response = $this->getJson('/api/mobile/v1/modern-forestry/products');

    $response
        ->assertOk()
        ->assertJsonPath('meta.count', 2)
        ->assertJsonMissingPath('meta.source')
        ->assertJsonPath('data.0.title', 'Forest Ember Candle');
});

test('fake mobile product detail is not used outside local and testing environments', function (): void {
    config()->set('mobile_catalog.fake_enabled', true);
    $originalEnvironment = app()->environment();

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyMobileProductDetailPayload(), 200),
    ]);

    try {
        $this->app['env'] = 'production';

        $response = $this->getJson('/api/mobile/v1/modern-forestry/products/forest-ember-candle');
    } finally {
        $this->app['env'] = $originalEnvironment;
    }

    $response
        ->assertOk()
        ->assertJsonPath('meta.source', 'shopify')
        ->assertJsonPath('data.title', 'Forest Ember Candle')
        ->assertJsonPath('data.description', 'Warm amber, cedar, and a soft smoky finish.');
});

test('fake mobile catalog is not used outside local and testing environments', function (): void {
    config()->set('mobile_catalog.fake_enabled', true);
    $originalEnvironment = app()->environment();

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyMobileCatalogPayload(), 200),
    ]);

    try {
        $this->app['env'] = 'production';

        $response = $this->getJson('/api/mobile/v1/modern-forestry/products');
    } finally {
        $this->app['env'] = $originalEnvironment;
    }

    $response
        ->assertOk()
        ->assertJsonMissingPath('meta.source')
        ->assertJsonPath('data.0.title', 'Forest Ember Candle');
});

test('real mobile catalog still fails closed safely when tenant or store config is invalid', function (): void {
    config()->set('mobile_catalog.fake_enabled', false);
    ShopifyStore::query()->delete();
    Tenant::query()->delete();

    $this->getJson('/api/mobile/v1/modern-forestry/products')
        ->assertStatus(503)
        ->assertJsonPath('meta.tenant', 'modern-forestry')
        ->assertJsonPath('meta.count', 0)
        ->assertJsonPath('error.code', 'catalog_unavailable')
        ->assertJsonPath('error.message', 'Modern Forestry products are temporarily unavailable.');
});

test('real mobile collections fail closed safely when tenant or store config is invalid', function (): void {
    config()->set('mobile_catalog.fake_enabled', false);
    ShopifyStore::query()->delete();
    Tenant::query()->delete();

    $this->getJson('/api/mobile/v1/modern-forestry/collections')
        ->assertStatus(503)
        ->assertJsonPath('collections', [])
        ->assertJsonPath('error.code', 'catalog_unavailable')
        ->assertJsonPath('error.message', 'Modern Forestry collections are temporarily unavailable.');
});

test('real mobile collections use a collection or hero product image when available', function (): void {
    config()->set('mobile_catalog.fake_enabled', false);

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyMobileCollectionsPayload(), 200),
    ]);

    $this->getJson('/api/mobile/v1/modern-forestry/collections')
        ->assertOk()
        ->assertJsonCount(6, 'collections')
        ->assertJsonPath('collections.0.handle', 'spring')
        ->assertJsonPath('collections.0.title', 'Spring')
        ->assertJsonPath('collections.0.imageUrl', 'https://cdn.shopify.com/s/files/spring-hero.png?width=900')
        ->assertJsonPath('collections.1.handle', 'classic')
        ->assertJsonPath('collections.1.imageUrl', 'https://cdn.shopify.com/s/files/classic-collection.png?width=900');
});

test('real mobile home always returns canonical seasonal collections with image urls', function (): void {
    config()->set('mobile_catalog.fake_enabled', false);

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => function (Request $request) {
            $body = json_decode($request->body(), true);
            $query = (string) ($body['query'] ?? '');

            if (str_contains($query, 'query MobileCatalogCollections')) {
                return Http::response([
                    'data' => [
                        'collections' => [
                            'nodes' => [
                                [
                                    'handle' => 'holiday-collection',
                                    'title' => 'Holiday Collection',
                                    'description' => 'Winter gifts.',
                                    'image' => null,
                                    'products' => ['nodes' => []],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            if (str_contains($query, 'query MobileCatalogProducts')) {
                return Http::response(shopifyMobileCatalogPayload(), 200);
            }

            return Http::response([], 404);
        },
    ]);

    $payload = $this->getJson('/api/mobile/v1/modern-forestry/home')
        ->assertOk()
        ->assertJsonCount(6, 'featuredCollections')
        ->json();

    expect(collect($payload['featuredCollections'])->pluck('handle')->all())
        ->toBe(['spring', 'classic', 'summer', 'holiday', 'fall', 'bundles']);

    foreach ($payload['featuredCollections'] as $collection) {
        expect($collection['imageUrl'] ?? null)->toBeString()->not->toBe('');
    }
});

test('real mobile collection products return only active products and support sorting', function (): void {
    config()->set('mobile_catalog.fake_enabled', false);

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => function (Request $request) {
            $body = json_decode($request->body(), true);
            $query = (string) ($body['query'] ?? '');
            $variables = $body['variables'] ?? [];

            if (str_contains($query, 'query MobileCatalogCollections')) {
                return Http::response(shopifyMobileCollectionsPayload(), 200);
            }

            if (str_contains($query, 'query MobileCatalogCollectionProducts')) {
                expect($variables['query'] ?? null)->toBe('handle:fall-collection');
                expect($variables['sortKey'] ?? null)->toBe('PRICE');
                expect($variables['reverse'] ?? null)->toBeFalse();

                return Http::response(shopifyMobileCollectionProductsPayload(), 200);
            }

            return Http::response([], 404);
        },
    ]);

    $this->getJson('/api/mobile/v1/modern-forestry/collections/fall/products?sort=price_low_to_high')
        ->assertOk()
        ->assertJsonPath('collection.handle', 'fall')
        ->assertJsonPath('products.0.handle', 'apple-harvest')
        ->assertJsonPath('products.0.price', '18.00')
        ->assertJsonPath('products.1.handle', 'forest-ember-candle')
        ->assertJsonPath('products.2.handle', 'spiced-cider')
        ->assertJsonCount(3, 'products');
});

test('real mobile collection product sorting maps to shopify sort variables', function (string $sort, string $sortKey, bool $reverse): void {
    config()->set('mobile_catalog.fake_enabled', false);

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => function (Request $request) use ($sortKey, $reverse) {
            $body = json_decode($request->body(), true);
            $query = (string) ($body['query'] ?? '');
            $variables = $body['variables'] ?? [];

            if (str_contains($query, 'query MobileCatalogCollections')) {
                return Http::response(shopifyMobileCollectionsPayload(), 200);
            }

            if (str_contains($query, 'query MobileCatalogCollectionProducts')) {
                expect($variables['sortKey'] ?? null)->toBe($sortKey);
                expect($variables['reverse'] ?? null)->toBe($reverse);

                return Http::response(shopifyMobileCollectionProductsPayload(), 200);
            }

            return Http::response([], 404);
        },
    ]);

    $this->getJson('/api/mobile/v1/modern-forestry/collections/fall/products?sort='.$sort)
        ->assertOk()
        ->assertJsonPath('collection.handle', 'fall');
})->with([
    'best selling' => ['best_selling', 'BEST_SELLING', false],
    'newest' => ['newest', 'CREATED', true],
    'price low to high' => ['price_low_to_high', 'PRICE', false],
    'price high to low' => ['price_high_to_low', 'PRICE', true],
]);

test('real mobile collection products fail closed safely when tenant or store config is invalid', function (): void {
    config()->set('mobile_catalog.fake_enabled', false);
    ShopifyStore::query()->delete();
    Tenant::query()->delete();

    $this->getJson('/api/mobile/v1/modern-forestry/collections/winter/products')
        ->assertStatus(503)
        ->assertJsonPath('collection', null)
        ->assertJsonPath('products', [])
        ->assertJsonPath('error.code', 'catalog_unavailable')
        ->assertJsonPath('error.message', 'Modern Forestry collection products are temporarily unavailable.');
});

test('real mobile home fails closed safely when tenant or store config is invalid', function (): void {
    config()->set('mobile_catalog.fake_enabled', false);
    ShopifyStore::query()->delete();
    Tenant::query()->delete();

    $this->getJson('/api/mobile/v1/modern-forestry/home')
        ->assertStatus(503)
        ->assertJsonPath('hero', null)
        ->assertJsonPath('featuredCollections', [])
        ->assertJsonPath('featuredProducts', [])
        ->assertJsonPath('cards', [])
        ->assertJsonPath('error.code', 'catalog_unavailable')
        ->assertJsonPath('error.message', 'Modern Forestry home content is temporarily unavailable.');
});

test('real mobile product detail fails closed safely when tenant or store config is invalid', function (): void {
    config()->set('mobile_catalog.fake_enabled', false);
    ShopifyStore::query()->delete();
    Tenant::query()->delete();

    $this->getJson('/api/mobile/v1/modern-forestry/products/fraser-fir')
        ->assertStatus(503)
        ->assertJsonPath('meta.tenant', 'modern-forestry')
        ->assertJsonPath('error.code', 'catalog_unavailable')
        ->assertJsonPath('error.message', 'Modern Forestry product details are temporarily unavailable.');
});

test('mobile catalog endpoint returns 200 with public product data', function (): void {
    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyMobileCatalogPayload(), 200),
    ]);

    $response = $this->getJson('/api/mobile/v1/modern-forestry/products');

    $response
        ->assertOk()
        ->assertJsonPath('meta.tenant', 'modern-forestry')
        ->assertJsonPath('meta.count', 2)
        ->assertJsonPath('data.0.id', '111')
        ->assertJsonPath('data.0.title', 'Forest Ember Candle')
        ->assertJsonPath('data.0.handle', 'forest-ember-candle')
        ->assertJsonPath('data.0.url', 'https://theforestrystudio.com/products/forest-ember-candle')
        ->assertJsonPath('data.0.imageUrl', 'https://cdn.shopify.com/s/files/forest-ember.png?width=640')
        ->assertJsonPath('data.0.price', '24.00')
        ->assertJsonPath('data.0.compareAtPrice', null)
        ->assertJsonPath('data.0.available', true)
        ->assertJsonPath('data.0.productType', 'Candle')
        ->assertJsonPath('data.0.tags.0', 'amber');
});

test('mobile catalog response has expected data and meta shape', function (): void {
    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyMobileCatalogPayload(), 200),
    ]);

    $this->getJson('/api/mobile/v1/modern-forestry/products')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'title',
                    'handle',
                    'url',
                    'imageUrl',
                    'price',
                    'compareAtPrice',
                    'available',
                    'productType',
                    'tags',
                ],
            ],
            'meta' => [
                'tenant',
                'count',
            ],
        ]);
});

test('mobile catalog limit query parameter is capped at 50', function (): void {
    $requestedFirst = null;

    Http::fake(function (Request $request) use (&$requestedFirst) {
        $body = json_decode($request->body(), true);
        $requestedFirst = $body['variables']['first'] ?? null;

        return Http::response(shopifyMobileCatalogPayload(productCount: 1), 200);
    });

    $this->getJson('/api/mobile/v1/modern-forestry/products?limit=500')
        ->assertOk()
        ->assertJsonPath('meta.count', 1);

    expect($requestedFirst)->toBe(50);
});

test('mobile product detail endpoint returns 200 with public product data', function (): void {
    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyMobileProductDetailPayload(), 200),
    ]);

    $response = $this->getJson('/api/mobile/v1/modern-forestry/products/forest-ember-candle');

    $response
        ->assertOk()
        ->assertJsonPath('meta.tenant', 'modern-forestry')
        ->assertJsonPath('meta.source', 'shopify')
        ->assertJsonPath('data.id', '111')
        ->assertJsonPath('data.title', 'Forest Ember Candle')
        ->assertJsonPath('data.handle', 'forest-ember-candle')
        ->assertJsonPath('data.url', 'https://theforestrystudio.com/products/forest-ember-candle')
        ->assertJsonPath('data.description', 'Warm amber, cedar, and a soft smoky finish.')
        ->assertJsonPath('data.descriptionHtml', '<p>Warm amber, cedar, and a soft smoky finish.</p>')
        ->assertJsonPath('data.mobileSummary', 'Warm amber, cedar, and a soft smoky finish.')
        ->assertJsonPath('data.images.0.url', 'https://cdn.shopify.com/s/files/forest-ember-detail.png?width=1200')
        ->assertJsonPath('data.images.0.altText', 'Forest Ember candle jar')
        ->assertJsonPath('data.variants.0.id', '9001')
        ->assertJsonPath('data.variants.0.title', '8 oz candle')
        ->assertJsonPath('data.variants.0.price', '24.00')
        ->assertJsonPath('data.variants.0.compareAtPrice', null)
        ->assertJsonPath('data.variants.0.available', true)
        ->assertJsonPath('data.price', '24.00')
        ->assertJsonPath('data.compareAtPrice', null)
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.productType', 'Candle')
        ->assertJsonPath('data.tags.0', 'amber')
        ->assertJsonPath('data.scentNotes.0', 'amber')
        ->assertJsonPath('data.faq', []);
});

test('mobile product detail includes candle club faq for subscription products', function (): void {
    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyMobileCandleClubProductDetailPayload(), 200),
    ]);

    $this->getJson('/api/mobile/v1/modern-forestry/products/modern-forestry-candle-club-16oz-subscription-with-gifts')
        ->assertOk()
        ->assertJsonPath('data.handle', 'modern-forestry-candle-club-16oz-subscription-with-gifts')
        ->assertJsonPath('data.faq.0.question', 'What is Candle Club?')
        ->assertJsonPath('data.faq.1.question', 'How do rewards work with Candle Club?');
});

test('mobile product detail endpoint returns 404 when shopify has no matching product', function (): void {
    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response([
            'data' => [
                'products' => [
                    'nodes' => [],
                ],
            ],
        ], 200),
    ]);

    $this->getJson('/api/mobile/v1/modern-forestry/products/not-real')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'product_not_found');
});

test('mobile catalog response does not expose secrets or private shopify fields', function (): void {
    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyMobileCatalogPayload(), 200),
    ]);

    $payload = $this->getJson('/api/mobile/v1/modern-forestry/products')
        ->assertOk()
        ->json();

    $encoded = json_encode($payload);

    foreach ([
        'shpat_mobile_test_token',
        'mobile-test-secret',
        'mobile-test-client',
        'modernforestry-test.myshopify.com',
        'gid://shopify',
        'access_token',
        'accessToken',
        'client_id',
        'secret',
        'token',
        'admin_graphql_api_id',
        'variants',
        'customer',
        'orders',
    ] as $privateValue) {
        expect($encoded)->not->toContain($privateValue);
    }
});

test('mobile product detail response does not expose secrets or private shopify fields', function (): void {
    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyMobileProductDetailPayload(), 200),
    ]);

    $payload = $this->getJson('/api/mobile/v1/modern-forestry/products/forest-ember-candle')
        ->assertOk()
        ->json();

    $encoded = json_encode($payload);

    foreach ([
        'shpat_mobile_test_token',
        'mobile-test-secret',
        'mobile-test-client',
        'modernforestry-test.myshopify.com',
        'gid://shopify',
        'access_token',
        'accessToken',
        'client_id',
        'secret',
        'token',
        'admin_graphql_api_id',
        'customer',
        'orders',
    ] as $privateValue) {
        expect($encoded)->not->toContain($privateValue);
    }
});

test('mobile checkout falls back to a validated shopify cart permalink when storefront token is missing', function (): void {
    config()->set('services.shopify.stores.retail.storefront_access_token', null);

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyMobileProductDetailPayload(), 200),
    ]);

    $this->postJson('/api/mobile/v1/modern-forestry/checkout', [
        'items' => [
            [
                'productHandle' => 'forest-ember-candle',
                'variantId' => '9001',
                'quantity' => 1,
            ],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('meta.tenant', 'modern-forestry')
        ->assertJsonPath('data.checkoutUrl', 'https://modernforestry-test.myshopify.com/cart/9001:1?attributes%5Bsource%5D=modern_forestry_ios&attributes%5Btenant_id%5D=1')
        ->assertJsonPath('data.cartId', 'cart-permalink')
        ->assertJsonPath('data.subtotal.amount', '24.00');
});

test('mobile checkout requires modern forestry tenant one', function (): void {
    config()->set('services.shopify.stores.retail.storefront_access_token', 'storefront-test-token');
    Tenant::query()->whereKey(1)->update(['slug' => 'other-tenant']);

    $this->postJson('/api/mobile/v1/modern-forestry/checkout', [
        'items' => [
            [
                'productHandle' => 'forest-ember-candle',
                'variantId' => '9001',
                'quantity' => 1,
            ],
        ],
    ])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'tenant_unavailable');
});

test('mobile checkout rejects invalid or stale variants', function (): void {
    config()->set('services.shopify.stores.retail.storefront_access_token', 'storefront-test-token');

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyMobileProductDetailPayload(), 200),
    ]);

    $this->postJson('/api/mobile/v1/modern-forestry/checkout', [
        'items' => [
            [
                'productHandle' => 'forest-ember-candle',
                'variantId' => '9999',
                'quantity' => 1,
            ],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'variant_unavailable');
});

test('mobile checkout rejects sold out variants before creating a cart', function (): void {
    config()->set('services.shopify.stores.retail.storefront_access_token', 'storefront-test-token');

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyMobileProductDetailPayload(), 200),
        'https://modernforestry-test.myshopify.com/api/2026-01/graphql.json' => Http::response(shopifyStorefrontCartCreatePayload(), 200),
    ]);

    $this->postJson('/api/mobile/v1/modern-forestry/checkout', [
        'items' => [
            [
                'productHandle' => 'forest-ember-candle',
                'variantId' => '9002',
                'quantity' => 1,
            ],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'variant_unavailable');

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://modernforestry-test.myshopify.com/api/2026-01/graphql.json');
});

test('mobile checkout maps shopify user errors to a safe response', function (): void {
    config()->set('services.shopify.stores.retail.storefront_access_token', 'storefront-test-token');

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyMobileProductDetailPayload(), 200),
        'https://modernforestry-test.myshopify.com/api/2026-01/graphql.json' => Http::response([
            'data' => [
                'cartCreate' => [
                    'cart' => null,
                    'userErrors' => [
                        [
                            'field' => ['input', 'lines', '0', 'merchandiseId'],
                            'message' => 'This item is no longer available.',
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->postJson('/api/mobile/v1/modern-forestry/checkout', [
        'items' => [
            [
                'productHandle' => 'forest-ember-candle',
                'variantId' => '9001',
                'quantity' => 1,
            ],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'shopify_user_error')
        ->assertJsonPath('error.message', 'This item is no longer available.');
});

test('mobile checkout creates a shopify storefront cart and returns checkout url', function (): void {
    config()->set('services.shopify.stores.retail.storefront_access_token', 'storefront-test-token');

    $storefrontRequest = null;

    Http::fake(function (Request $request) use (&$storefrontRequest) {
        if (str_contains($request->url(), '/admin/api/2026-01/graphql.json')) {
            return Http::response(shopifyMobileProductDetailPayload(), 200);
        }

        if (str_contains($request->url(), '/api/2026-01/graphql.json')) {
            $storefrontRequest = $request;

            return Http::response(shopifyStorefrontCartCreatePayload(), 200);
        }

        return Http::response([], 404);
    });

    $response = $this->postJson('/api/mobile/v1/modern-forestry/checkout', [
        'items' => [
            [
                'productHandle' => 'forest-ember-candle',
                'variantId' => '9001',
                'quantity' => 2,
            ],
        ],
        'discountCode' => ' candlecash10 ',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('meta.tenant', 'modern-forestry')
        ->assertJsonPath('meta.source', 'shopify')
        ->assertJsonPath('data.checkoutUrl', 'https://modernforestry-test.myshopify.com/cart/c/test-checkout')
        ->assertJsonPath('data.cartId', 'cart-123')
        ->assertJsonPath('data.lines.0.productHandle', 'forest-ember-candle')
        ->assertJsonPath('data.lines.0.productTitle', 'Forest Ember Candle')
        ->assertJsonPath('data.lines.0.variantId', '9001')
        ->assertJsonPath('data.lines.0.quantity', 2)
        ->assertJsonPath('data.subtotal.amount', '48.00')
        ->assertJsonPath('data.total.currencyCode', 'USD');

    expect($storefrontRequest)->not->toBeNull();
    expect($storefrontRequest->hasHeader('X-Shopify-Storefront-Access-Token', 'storefront-test-token'))->toBeTrue();

    $body = json_decode($storefrontRequest->body(), true);

    expect($body['variables']['input']['lines'][0]['merchandiseId'])->toBe('gid://shopify/ProductVariant/9001');
    expect($body['variables']['input']['lines'][0]['quantity'])->toBe(2);
    expect($body['variables']['input']['discountCodes'])->toBe(['CANDLECASH10']);
});

test('mobile checkout response does not expose storefront token or shopify gids', function (): void {
    config()->set('services.shopify.stores.retail.storefront_access_token', 'storefront-test-token');

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyMobileProductDetailPayload(), 200),
        'https://modernforestry-test.myshopify.com/api/2026-01/graphql.json' => Http::response(shopifyStorefrontCartCreatePayload(), 200),
    ]);

    $payload = $this->postJson('/api/mobile/v1/modern-forestry/checkout', [
        'items' => [
            [
                'productHandle' => 'forest-ember-candle',
                'variantId' => '9001',
                'quantity' => 1,
            ],
        ],
    ])
        ->assertOk()
        ->json();

    $encoded = json_encode($payload);

    expect($encoded)->not->toContain('storefront-test-token');
    expect($encoded)->not->toContain('gid://shopify/ProductVariant/9001');
    expect($encoded)->not->toContain('shpat_mobile_test_token');
});

/**
 * @return array<string,mixed>
 */
function shopifyMobileCatalogPayload(int $productCount = 2): array
{
    $products = [
        [
            'id' => 'gid://shopify/Product/111',
            'title' => 'Forest Ember Candle',
            'handle' => 'forest-ember-candle',
            'onlineStoreUrl' => 'https://example.myshopify.com/products/forest-ember-candle',
            'productType' => 'Candle',
            'tags' => ['amber', 'soy'],
            'status' => 'ACTIVE',
            'featuredImage' => [
                'url' => 'https://cdn.shopify.com/s/files/forest-ember.png',
            ],
            'variants' => [
                'nodes' => [
                    [
                        'price' => '24.00',
                        'compareAtPrice' => null,
                    ],
                ],
            ],
        ],
        [
            'id' => 'gid://shopify/Product/222',
            'title' => 'Pine Ridge Candle',
            'handle' => 'pine-ridge-candle',
            'onlineStoreUrl' => 'https://example.myshopify.com/products/pine-ridge-candle',
            'productType' => 'Candle',
            'tags' => ['pine'],
            'status' => 'ACTIVE',
            'featuredImage' => [
                'url' => 'https://cdn.shopify.com/s/files/pine-ridge.png',
            ],
            'variants' => [
                'nodes' => [
                    [
                        'price' => '28.00',
                        'compareAtPrice' => '32.00',
                    ],
                ],
            ],
        ],
    ];

    return [
        'data' => [
            'products' => [
                'nodes' => array_slice($products, 0, $productCount),
            ],
        ],
    ];
}

/**
 * @return array<string,mixed>
 */
function shopifyMobileCollectionsPayload(): array
{
    return [
        'data' => [
            'collections' => [
                'nodes' => [
                    [
                        'handle' => 'spring-collection',
                        'title' => 'Spring Collection',
                        'description' => 'Fresh florals and brighter daylight scents.',
                        'image' => null,
                        'products' => [
                            'nodes' => [
                                [
                                    'status' => 'ACTIVE',
                                    'featuredImage' => [
                                        'url' => 'https://cdn.shopify.com/s/files/spring-hero.png',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'handle' => 'classic-collection-1',
                        'title' => 'Classic Collection',
                        'description' => 'The year-round scents people keep coming back for.',
                        'image' => [
                            'url' => 'https://cdn.shopify.com/s/files/classic-collection.png',
                        ],
                        'products' => [
                            'nodes' => [
                                [
                                    'status' => 'ACTIVE',
                                    'featuredImage' => [
                                        'url' => 'https://cdn.shopify.com/s/files/classic-hero.png',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'handle' => 'summer-collection',
                        'title' => 'Summer Collection',
                        'description' => 'Sunlit favorites.',
                        'image' => [
                            'url' => 'https://cdn.shopify.com/s/files/summer-collection.png',
                        ],
                        'products' => [
                            'nodes' => [
                                [
                                    'status' => 'ACTIVE',
                                    'featuredImage' => [
                                        'url' => 'https://cdn.shopify.com/s/files/summer-hero.png',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'handle' => 'holiday-collection',
                        'title' => 'Holiday Collection',
                        'description' => 'Winter gifts and gatherings.',
                        'image' => null,
                        'products' => [
                            'nodes' => [
                                [
                                    'status' => 'ACTIVE',
                                    'featuredImage' => [
                                        'url' => 'https://cdn.shopify.com/s/files/holiday-hero.png',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'handle' => 'fall-collection',
                        'title' => 'Fall Collection',
                        'description' => 'Warm spice and woods.',
                        'image' => null,
                        'products' => [
                            'nodes' => [
                                [
                                    'status' => 'ACTIVE',
                                    'featuredImage' => [
                                        'url' => 'https://cdn.shopify.com/s/files/fall-hero.png',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'handle' => 'bundle-collection',
                        'title' => 'Bundle Collection',
                        'description' => 'Giftable sets.',
                        'image' => null,
                        'products' => [
                            'nodes' => [
                                [
                                    'status' => 'ACTIVE',
                                    'featuredImage' => [
                                        'url' => 'https://cdn.shopify.com/s/files/bundles-hero.png',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * @return array<string,mixed>
 */
function shopifyMobileCollectionProductsPayload(): array
{
    return [
        'data' => [
            'collections' => [
                'nodes' => [
                    [
                        'handle' => 'fall-collection',
                        'title' => 'Fall Collection',
                        'description' => 'Warm spice and woods.',
                        'image' => null,
                        'products' => [
                            'nodes' => [
                                [
                                    'id' => 'gid://shopify/Product/111',
                                    'title' => 'Forest Ember Candle',
                                    'handle' => 'forest-ember-candle',
                                    'createdAt' => '2026-06-15T12:00:00Z',
                                    'productType' => 'Candle',
                                    'tags' => ['amber', 'cedar'],
                                    'status' => 'ACTIVE',
                                    'featuredImage' => [
                                        'url' => 'https://cdn.shopify.com/s/files/forest-ember.png',
                                    ],
                                    'variants' => [
                                        'nodes' => [
                                            [
                                                'price' => '24.00',
                                                'compareAtPrice' => null,
                                            ],
                                        ],
                                    ],
                                ],
                                [
                                    'id' => 'gid://shopify/Product/222',
                                    'title' => 'Draft Candle',
                                    'handle' => 'draft-candle',
                                    'createdAt' => '2026-06-18T12:00:00Z',
                                    'productType' => 'Candle',
                                    'tags' => ['draft'],
                                    'status' => 'DRAFT',
                                    'featuredImage' => [
                                        'url' => 'https://cdn.shopify.com/s/files/draft-candle.png',
                                    ],
                                    'variants' => [
                                        'nodes' => [
                                            [
                                                'price' => '12.00',
                                                'compareAtPrice' => null,
                                            ],
                                        ],
                                    ],
                                ],
                                [
                                    'id' => 'gid://shopify/Product/333',
                                    'title' => 'Apple Harvest',
                                    'handle' => 'apple-harvest',
                                    'createdAt' => '2026-06-20T12:00:00Z',
                                    'publishedAt' => '2026-06-20T12:00:00Z',
                                    'onlineStoreUrl' => 'https://example.myshopify.com/products/apple-harvest',
                                    'productType' => 'Candle',
                                    'tags' => ['apple'],
                                    'status' => 'ACTIVE',
                                    'featuredImage' => [
                                        'url' => 'https://cdn.shopify.com/s/files/apple-harvest.png',
                                    ],
                                    'variants' => [
                                        'nodes' => [
                                            [
                                                'price' => '18.00',
                                                'compareAtPrice' => null,
                                            ],
                                        ],
                                    ],
                                ],
                                [
                                    'id' => 'gid://shopify/Product/555',
                                    'title' => 'Hidden Active Candle',
                                    'handle' => 'hidden-active-candle',
                                    'createdAt' => '2026-06-22T12:00:00Z',
                                    'publishedAt' => null,
                                    'onlineStoreUrl' => null,
                                    'productType' => 'Candle',
                                    'tags' => ['hidden'],
                                    'status' => 'ACTIVE',
                                    'featuredImage' => [
                                        'url' => 'https://cdn.shopify.com/s/files/hidden-active-candle.png',
                                    ],
                                    'variants' => [
                                        'nodes' => [
                                            [
                                                'price' => '10.00',
                                                'compareAtPrice' => null,
                                                'availableForSale' => true,
                                            ],
                                        ],
                                    ],
                                ],
                                [
                                    'id' => 'gid://shopify/Product/444',
                                    'title' => 'Spiced Cider',
                                    'handle' => 'spiced-cider',
                                    'createdAt' => '2026-06-10T12:00:00Z',
                                    'publishedAt' => '2026-06-10T12:00:00Z',
                                    'onlineStoreUrl' => 'https://example.myshopify.com/products/spiced-cider',
                                    'productType' => 'Candle',
                                    'tags' => ['cider'],
                                    'status' => 'ACTIVE',
                                    'featuredImage' => [
                                        'url' => 'https://cdn.shopify.com/s/files/spiced-cider.png',
                                    ],
                                    'variants' => [
                                        'nodes' => [
                                            [
                                                'price' => '29.00',
                                                'compareAtPrice' => '34.00',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * @return array<string,mixed>
 */
function shopifyMobileProductDetailPayload(): array
{
    return [
        'data' => [
            'products' => [
                'nodes' => [
                    [
                        'id' => 'gid://shopify/Product/111',
                        'title' => 'Forest Ember Candle',
                        'handle' => 'forest-ember-candle',
                        'description' => 'Warm amber, cedar, and a soft smoky finish.',
                        'descriptionHtml' => '<p>Warm amber, cedar, and a soft smoky finish.</p>',
                        'onlineStoreUrl' => 'https://modernforestry-test.myshopify.com/products/forest-ember-candle',
                        'admin_graphql_api_id' => 'gid://shopify/Product/111',
                        'productType' => 'Candle',
                        'tags' => ['amber', 'cedar', 'smoke'],
                        'status' => 'ACTIVE',
                        'images' => [
                            'nodes' => [
                                [
                                    'url' => 'https://cdn.shopify.com/s/files/forest-ember-detail.png',
                                    'altText' => 'Forest Ember candle jar',
                                ],
                            ],
                        ],
                        'variants' => [
                            'nodes' => [
                                [
                                    'id' => 'gid://shopify/ProductVariant/9001',
                                    'title' => '8 oz candle',
                                    'price' => '24.00',
                                    'compareAtPrice' => null,
                                    'availableForSale' => true,
                                ],
                                [
                                    'id' => 'gid://shopify/ProductVariant/9002',
                                    'title' => '12 oz candle',
                                    'price' => '32.00',
                                    'compareAtPrice' => '36.00',
                                    'availableForSale' => false,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * @return array<string,mixed>
 */
function shopifyMobileCandleClubProductDetailPayload(): array
{
    return [
        'data' => [
            'products' => [
                'nodes' => [
                    [
                        'id' => 'gid://shopify/Product/999',
                        'title' => 'Modern Forestry Candle Club 16oz Subscription with Gifts',
                        'handle' => 'modern-forestry-candle-club-16oz-subscription-with-gifts',
                        'description' => 'A recurring Candle Club subscription with member-only access and seasonal extras.',
                        'descriptionHtml' => '<p>A recurring Candle Club subscription with member-only access and seasonal extras.</p>',
                        'productType' => 'Subscription',
                        'tags' => ['candle club', 'subscription'],
                        'status' => 'ACTIVE',
                        'images' => [
                            'nodes' => [
                                [
                                    'url' => 'https://cdn.shopify.com/s/files/candle-club.png',
                                    'altText' => 'Candle Club product',
                                ],
                            ],
                        ],
                        'variants' => [
                            'nodes' => [
                                [
                                    'id' => 'gid://shopify/ProductVariant/9901',
                                    'title' => 'Default Title',
                                    'price' => '30.00',
                                    'compareAtPrice' => null,
                                    'availableForSale' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * @return array<string,mixed>
 */
function shopifyStorefrontCartCreatePayload(): array
{
    return [
        'data' => [
            'cartCreate' => [
                'cart' => [
                    'id' => 'gid://shopify/Cart/cart-123',
                    'checkoutUrl' => 'https://modernforestry-test.myshopify.com/cart/c/test-checkout',
                    'cost' => [
                        'subtotalAmount' => [
                            'amount' => '48.00',
                            'currencyCode' => 'USD',
                        ],
                        'totalAmount' => [
                            'amount' => '52.41',
                            'currencyCode' => 'USD',
                        ],
                    ],
                ],
                'userErrors' => [],
            ],
        ],
    ];
}
