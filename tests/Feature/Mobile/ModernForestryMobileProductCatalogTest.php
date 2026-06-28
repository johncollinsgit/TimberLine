<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashReward;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingProfileScentQuizResult;
use App\Models\MarketingReviewHistory;
use App\Models\MessagingConversation;
use App\Models\MessagingConversationMessage;
use App\Models\MobilePushDevice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Marketing\MarketingWishlistService;
use App\Services\Marketing\TwilioSmsService;
use App\Services\Mobile\ModernForestryMobileProductCatalogService;
use App\Services\Shopify\ShopifyAppContentService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Cache::flush();
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
        ->assertJsonPath('collections.4.handle', 'autumn')
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

test('mobile home uses published Shopify app content for native hero and slides', function (): void {
    config()->set('mobile_catalog.fake_enabled', true);
    ShopifyStore::query()->delete();

    $tenant = Tenant::query()->where('slug', 'modern-forestry')->firstOrFail();
    $content = app(ShopifyAppContentService::class)->defaults();
    $content['brand_name'] = 'Modern Forestry Test';
    $content['mobile_home_eyebrow'] = 'Fresh from Shopify Admin';
    $content['mobile_home_title'] = 'A native home anyone can update';
    $content['mobile_home_subtitle'] = 'Published content flows into the app.';
    $content['mobile_slide_1_title'] = 'Updated hero slide';
    $content['mobile_slide_1_subtitle'] = 'No rebuild needed.';
    $content['mobile_slide_1_image_url'] = 'https://theforestrystudio.com/cdn/shop/files/mobile-admin-slide.jpg?v=1';
    $content['mobile_slide_1_mobile_image_url'] = 'https://theforestrystudio.com/cdn/shop/files/mobile-admin-slide-phone.jpg?v=1';
    $content['mobile_slide_1_cta_label'] = 'Shop the edit';
    $content['mobile_slide_1_cta_url'] = 'https://theforestrystudio.com/collections/summer';

    TenantMarketingSetting::query()->create([
        'tenant_id' => $tenant->id,
        'key' => ShopifyAppContentService::SETTING_KEY,
        'value' => [
            'draft' => app(ShopifyAppContentService::class)->defaults(),
            'published' => $content,
            'published_at' => now()->toIso8601String(),
            'published_by' => 'shopify-admin',
        ],
        'description' => 'Modern Forestry customer dashboard copy.',
    ]);

    $this->getJson('/api/mobile/v1/modern-forestry/home')
        ->assertOk()
        ->assertJsonPath('brand.wordmark', 'Modern Forestry Test')
        ->assertJsonPath('hero.eyebrow', 'Fresh from Shopify Admin')
        ->assertJsonPath('hero.title', 'A native home anyone can update')
        ->assertJsonPath('hero.subtitle', 'Published content flows into the app.')
        ->assertJsonPath('hero.slides.0.title', 'Updated hero slide')
        ->assertJsonPath('hero.slides.0.subtitle', 'No rebuild needed.')
        ->assertJsonPath('hero.slides.0.ctaTitle', 'Shop the edit')
        ->assertJsonPath('hero.slides.0.ctaUrl', 'https://theforestrystudio.com/collections/summer')
        ->assertJsonPath('hero.slides.0.imageUrl', 'https://theforestrystudio.com/cdn/shop/files/mobile-admin-slide.jpg?v=1&width=1200')
        ->assertJsonPath('hero.slides.0.mobileImageUrl', 'https://theforestrystudio.com/cdn/shop/files/mobile-admin-slide-phone.jpg?v=1&width=1200');
});

test('fake mobile home response references valid known collection and product handles', function (): void {
    config()->set('mobile_catalog.fake_enabled', true);
    ShopifyStore::query()->delete();
    Tenant::query()->delete();

    $payload = $this->getJson('/api/mobile/v1/modern-forestry/home')
        ->assertOk()
        ->json();

    $knownCollectionHandles = ['spring', 'classic', 'summer', 'holiday', 'autumn', 'bundles'];
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
        ->assertJsonPath('state', 'signed_out');

    $profile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'customer@example.com',
        'normalized_email' => 'customer@example.com',
    ]);

    $this->withToken('mf-test-profile:'.$profile->id)
        ->getJson('/api/mobile/v1/modern-forestry/session-status')
        ->assertOk()
        ->assertJsonPath('authenticated', true)
        ->assertJsonPath('state', 'authenticated')
        ->assertJsonPath('customer.email', 'customer@example.com');
});

test('mobile account and rewards endpoints require a signed in customer token', function (): void {
    $this->getJson('/api/mobile/v1/modern-forestry/account')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');

    $this->getJson('/api/mobile/v1/modern-forestry/rewards')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

test('mobile auth session can create and validate a customer account token identity', function (): void {
    $response = $this->withToken('mf-test-email:native@example.com')
        ->postJson('/api/mobile/v1/modern-forestry/auth/session');

    $response
        ->assertOk()
        ->assertJsonPath('authenticated', true)
        ->assertJsonPath('state', 'authenticated')
        ->assertJsonPath('customer.email', 'native@example.com');

    $this->assertDatabaseHas('marketing_profiles', [
        'tenant_id' => 1,
        'normalized_email' => 'native@example.com',
    ]);
});

test('mobile customer auth config exposes only public oauth fields', function (): void {
    config()->set('services.shopify.customer_account.client_id', 'customer-account-client');
    config()->set('services.shopify.customer_account.client_secret', 'customer-account-secret');
    config()->set('services.shopify.customer_account.authorization_endpoint', 'https://shopify.com/authentication/20812479/oauth/authorize');
    config()->set('services.shopify.customer_account.token_endpoint', 'https://shopify.com/authentication/20812479/oauth/token');
    config()->set('services.shopify.customer_account.graphql_endpoint', 'https://shopify.com/20812479/account/customer/api/2026-01/graphql');
    config()->set('services.shopify.customer_account.redirect_uri', 'https://app.theeverbranch.com/api/mobile/v1/modern-forestry/auth/callback');
    config()->set('services.shopify.customer_account.callback_scheme', 'shop.20812479.modernforestry');
    config()->set('services.shopify.customer_account.scopes', 'openid email customer-account-api:full');

    $payload = $this->getJson('/api/mobile/v1/modern-forestry/auth/config')
        ->assertOk()
        ->assertJsonPath('data.configured', true)
        ->assertJsonPath('data.clientId', 'customer-account-client')
        ->assertJsonPath('data.authorizationEndpoint', 'https://shopify.com/authentication/20812479/oauth/authorize')
        ->assertJsonPath('data.redirectUri', 'https://app.theeverbranch.com/api/mobile/v1/modern-forestry/auth/callback')
        ->assertJsonPath('data.callbackScheme', 'shop.20812479.modernforestry')
        ->assertJsonPath('data.scopes', 'openid email customer-account-api:full')
        ->json();

    expect(json_encode($payload))->not->toContain('customer-account-secret')
        ->and(json_encode($payload))->not->toContain('graphql');
});

test('mobile customer auth callback bridges shopify https redirects back to the native app scheme', function (): void {
    config()->set('services.shopify.customer_account.callback_scheme', 'shop.20812479.modernforestry');

    $this->get('/api/mobile/v1/modern-forestry/auth/callback?code=code-123&state=state-456')
        ->assertRedirect('shop.20812479.modernforestry://shopify-customer-auth?code=code-123&state=state-456');
});

test('mobile customer auth config reports incomplete production oauth setup', function (): void {
    config()->set('services.shopify.customer_account.client_id', null);
    config()->set('services.shopify.customer_account.token_endpoint', null);
    config()->set('services.shopify.customer_account.graphql_endpoint', null);

    $this->getJson('/api/mobile/v1/modern-forestry/auth/config')
        ->assertOk()
        ->assertJsonPath('data.configured', false)
        ->assertJsonPath('data.clientId', null)
        ->assertJsonPath('data.authorizationEndpoint', null)
        ->assertJsonPath('data.callbackScheme', 'shop.20812479.modernforestry');
});

test('mobile oauth token endpoint returns not configured when customer account env is missing', function (): void {
    config()->set('services.shopify.customer_account.client_id', null);
    config()->set('services.shopify.customer_account.token_endpoint', null);
    config()->set('services.shopify.customer_account.graphql_endpoint', null);

    $this->postJson('/api/mobile/v1/modern-forestry/auth/token', [
        'code' => 'code',
        'codeVerifier' => 'verifier',
        'redirectUri' => 'shop.20812479.modernforestry://shopify-customer-auth',
    ])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'customer_auth_not_configured');
});

test('mobile oauth token exchange uses basic auth and validates the customer account token', function (): void {
    config()->set('services.shopify.customer_account.client_id', 'customer-account-client');
    config()->set('services.shopify.customer_account.client_secret', 'customer-account-secret');
    config()->set('services.shopify.customer_account.token_endpoint', 'https://shopify.com/authentication/20812479/oauth/token');
    config()->set('services.shopify.customer_account.graphql_endpoint', 'https://shopify.com/20812479/account/customer/api/2026-01/graphql');

    Http::fake(function (Request $request) {
        if ($request->url() === 'https://shopify.com/authentication/20812479/oauth/token') {
            expect($request->hasHeader('Authorization', 'Basic '.base64_encode('customer-account-client:customer-account-secret')))->toBeTrue();
            expect($request['grant_type'])->toBe('authorization_code')
                ->and($request['client_id'])->toBe('customer-account-client')
                ->and($request['code'])->toBe('valid-code')
                ->and($request['code_verifier'])->toBe('valid-verifier')
                ->and($request['redirect_uri'])->toBe('shop.20812479.modernforestry://shopify-customer-auth');

            return Http::response([
                'access_token' => 'shopify-customer-access-token',
                'refresh_token' => 'shopify-refresh-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200);
        }

        if ($request->url() === 'https://shopify.com/20812479/account/customer/api/2026-01/graphql') {
            expect($request->hasHeader('Authorization', 'shopify-customer-access-token'))->toBeTrue();

            return Http::response([
                'data' => [
                    'customer' => [
                        'id' => 'gid://shopify/Customer/12345',
                        'firstName' => 'Maple',
                        'lastName' => 'Woods',
                        'emailAddress' => [
                            'emailAddress' => 'maple@example.com',
                        ],
                        'phoneNumber' => null,
                    ],
                ],
            ], 200);
        }

        return Http::response([], 404);
    });

    $payload = $this->postJson('/api/mobile/v1/modern-forestry/auth/token', [
        'code' => 'valid-code',
        'codeVerifier' => 'valid-verifier',
        'redirectUri' => 'shop.20812479.modernforestry://shopify-customer-auth',
    ])
        ->assertOk()
        ->assertJsonPath('data.access_token', 'shopify-customer-access-token')
        ->assertJsonPath('meta.source', 'shopify_customer_account')
        ->json();

    expect(json_encode($payload))->not->toContain('customer-account-secret');
    $this->assertDatabaseHas('marketing_profiles', [
        'tenant_id' => 1,
        'normalized_email' => 'maple@example.com',
    ]);
});

test('mobile oauth token exchange maps shopify failures to safe typed errors', function (): void {
    config()->set('services.shopify.customer_account.client_id', 'customer-account-client');
    config()->set('services.shopify.customer_account.client_secret', 'customer-account-secret');
    config()->set('services.shopify.customer_account.token_endpoint', 'https://shopify.com/authentication/20812479/oauth/token');
    config()->set('services.shopify.customer_account.graphql_endpoint', 'https://shopify.com/20812479/account/customer/api/2026-01/graphql');

    Http::fake([
        'https://shopify.com/authentication/20812479/oauth/token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Code was already used.',
        ], 400),
    ]);

    $payload = $this->postJson('/api/mobile/v1/modern-forestry/auth/token', [
        'code' => 'stale-code',
        'codeVerifier' => 'valid-verifier',
        'redirectUri' => 'shop.20812479.modernforestry://shopify-customer-auth',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'customer_auth_exchange_failed')
        ->json();

    expect(json_encode($payload))->not->toContain('customer-account-secret')
        ->and(json_encode($payload))->not->toContain('Code was already used');
});

test('mobile oauth token exchange validates returned customer token before trusting it', function (): void {
    config()->set('services.shopify.customer_account.client_id', 'customer-account-client');
    config()->set('services.shopify.customer_account.client_secret', 'customer-account-secret');
    config()->set('services.shopify.customer_account.token_endpoint', 'https://shopify.com/authentication/20812479/oauth/token');
    config()->set('services.shopify.customer_account.graphql_endpoint', 'https://shopify.com/20812479/account/customer/api/2026-01/graphql');

    Http::fake([
        'https://shopify.com/authentication/20812479/oauth/token' => Http::response([
            'access_token' => 'unusable-customer-token',
            'token_type' => 'Bearer',
        ], 200),
        'https://shopify.com/20812479/account/customer/api/2026-01/graphql' => Http::response([
            'data' => [
                'customer' => null,
            ],
        ], 200),
    ]);

    $this->postJson('/api/mobile/v1/modern-forestry/auth/token', [
        'code' => 'valid-code',
        'codeVerifier' => 'valid-verifier',
        'redirectUri' => 'shop.20812479.modernforestry://shopify-customer-auth',
    ])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'customer_auth_validation_failed');
});

test('mobile oauth refresh exchanges a stored refresh token and validates the refreshed customer token', function (): void {
    config()->set('services.shopify.customer_account.client_id', 'customer-account-client');
    config()->set('services.shopify.customer_account.client_secret', 'customer-account-secret');
    config()->set('services.shopify.customer_account.token_endpoint', 'https://shopify.com/authentication/20812479/oauth/token');
    config()->set('services.shopify.customer_account.graphql_endpoint', 'https://shopify.com/20812479/account/customer/api/2026-01/graphql');

    Http::fake(function (Request $request) {
        if ($request->url() === 'https://shopify.com/authentication/20812479/oauth/token') {
            expect($request['grant_type'])->toBe('refresh_token')
                ->and($request['refresh_token'])->toBe('saved-refresh-token');

            return Http::response([
                'access_token' => 'refreshed-customer-access-token',
                'refresh_token' => 'rotated-refresh-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200);
        }

        if ($request->url() === 'https://shopify.com/20812479/account/customer/api/2026-01/graphql') {
            expect($request->hasHeader('Authorization', 'refreshed-customer-access-token'))->toBeTrue();

            return Http::response([
                'data' => [
                    'customer' => [
                        'id' => 'gid://shopify/Customer/98765',
                        'firstName' => 'Evergreen',
                        'lastName' => 'Lane',
                        'emailAddress' => [
                            'emailAddress' => 'evergreen@example.com',
                        ],
                        'phoneNumber' => null,
                    ],
                ],
            ], 200);
        }

        return Http::response([], 404);
    });

    $this->postJson('/api/mobile/v1/modern-forestry/auth/refresh', [
        'refreshToken' => 'saved-refresh-token',
    ])
        ->assertOk()
        ->assertJsonPath('data.access_token', 'refreshed-customer-access-token')
        ->assertJsonPath('data.refresh_token', 'rotated-refresh-token')
        ->assertJsonPath('meta.source', 'shopify_customer_account');
});

test('mobile account endpoint returns native safe account data only for signed in customer', function (): void {
    $profile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'first_name' => 'Ada',
        'last_name' => 'Woods',
        'email' => 'ada@example.com',
        'normalized_email' => 'ada@example.com',
        'accepts_email_marketing' => true,
    ]);
    MarketingProfileLink::query()->create([
        'tenant_id' => 1,
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:12345',
        'match_method' => 'test',
        'confidence' => 1,
    ]);
    app(MarketingWishlistService::class)->addItem($profile, [
        'store_key' => 'retail',
        'tenant_id' => 1,
        'product_id' => 'gid://shopify/Product/111',
        'product_variant_id' => 'gid://shopify/ProductVariant/9001',
        'product_handle' => 'forest-ember-candle',
        'product_title' => 'Forest Ember Candle',
        'product_url' => 'https://theforestrystudio.com/products/forest-ember-candle',
    ]);
    $order = Order::factory()->create([
        'tenant_id' => 1,
        'shopify_customer_id' => '12345',
        'order_number' => 'MF-1001',
        'total_price' => 36.50,
        'ordered_at' => now(),
    ]);
    OrderLine::query()->create([
        'order_id' => $order->id,
        'raw_title' => 'Customer Gift Candle',
        'quantity' => 1,
    ]);

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response([
            'data' => [
                'products' => [
                    'nodes' => [],
                    'pageInfo' => [
                        'hasNextPage' => false,
                        'endCursor' => null,
                    ],
                ],
            ],
        ], 200),
    ]);

    $payload = $this->withToken('mf-test-profile:'.$profile->id)
        ->getJson('/api/mobile/v1/modern-forestry/account')
        ->assertOk()
        ->assertJsonPath('data.customer.displayName', 'Ada Woods')
        ->assertJsonPath('data.orders.0.orderNumber', 'MF-1001')
        ->assertJsonPath('data.orders.0.linePreview', 'Customer Gift Candle')
        ->assertJsonPath('data.wishlist.summary.active_count', 1)
        ->assertJsonPath('data.wishlist.items.0.product_title', 'Forest Ember Candle')
        ->assertJsonPath('data.notifications.channels.0.id', 'email')
        ->assertJsonPath('data.notifications.channels.2.state', 'available_in_app')
        ->assertJsonPath('data.support.unreadCount', 0)
        ->assertJsonPath('data.customer.avatarUrl', null)
        ->assertJsonPath('data.insights.wishlistCount', 1)
        ->assertJsonPath('data.insights.topWishlistProducts.0', 'Forest Ember Candle')
        ->json();

    $encoded = json_encode($payload);
    expect($encoded)->not->toContain('shpat_mobile_test_token')
        ->and($encoded)->not->toContain('mobile-test-secret')
        ->and($encoded)->not->toContain('access_token');
});

test('mobile account payload includes the latest saved scent quiz result', function (): void {
    $profile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'first_name' => 'Ada',
        'last_name' => 'Woods',
        'email' => 'ada@example.com',
        'normalized_email' => 'ada@example.com',
    ]);

    MarketingProfileScentQuizResult::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => 1,
        'quiz_version' => 'scent-v1',
        'axis_scores' => [
            'floral' => 34,
            'woodsy' => 88,
            'smoky' => 62,
            'sweet' => 24,
            'masculine' => 58,
            'earthy' => 71,
            'clean' => 29,
            'citrus' => 18,
        ],
        'dominant_traits' => ['woodsy', 'earthy', 'smoky'],
        'headline' => 'Woodsy + Earthy',
        'personality_title' => 'The Grounded Explorer',
        'personality_body' => 'A calm, textured profile with a steady outdoorsy backbone.',
        'answers' => [
            ['question_id' => 'q01', 'option_id' => 'cabin'],
        ],
        'completed_at' => now(),
    ]);

    $this->withToken('mf-test-profile:'.$profile->id)
        ->getJson('/api/mobile/v1/modern-forestry/account')
        ->assertOk()
        ->assertJsonPath('data.scentQuiz.headline', 'Woodsy + Earthy')
        ->assertJsonPath('data.scentQuiz.personalityTitle', 'The Grounded Explorer')
        ->assertJsonPath('data.scentQuiz.axes.1.label', 'Woodsy');
});

test('mobile push device registration stores the ios device token for the signed in profile', function (): void {
    $profile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'first_name' => 'Ada',
        'last_name' => 'Woods',
        'email' => 'ada@example.com',
        'normalized_email' => 'ada@example.com',
    ]);

    $this->withToken('mf-test-profile:'.$profile->id)
        ->postJson('/api/mobile/v1/modern-forestry/notifications/push/register', [
            'deviceToken' => str_repeat('ab12', 16),
            'authorizationStatus' => 'authorized',
            'pushEnabled' => true,
            'appVersion' => '1.0',
            'appBuild' => '42',
            'deviceName' => 'Johns iPhone',
            'deviceModel' => 'iPhone 15 Pro Max',
            'locale' => 'en_US',
        ])
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.pushEnabled', true)
        ->assertJsonPath('data.deviceCount', 1);

    $device = MobilePushDevice::query()->where('marketing_profile_id', $profile->id)->first();

    expect($device)->not->toBeNull()
        ->and($device?->platform)->toBe('ios')
        ->and($device?->authorization_status)->toBe('authorized')
        ->and((bool) $device?->push_enabled)->toBeTrue();

    $this->withToken('mf-test-profile:'.$profile->id)
        ->getJson('/api/mobile/v1/modern-forestry/account')
        ->assertOk()
        ->assertJsonPath('data.notifications.summary.push', true)
        ->assertJsonPath('data.notifications.channels.2.enabled', true)
        ->assertJsonPath('data.notifications.channels.2.state', 'enabled');
});

test('mobile account endpoint still loads when shopify reorder enrichment is unavailable', function (): void {
    $profile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'first_name' => 'Ada',
        'last_name' => 'Woods',
        'email' => 'ada@example.com',
        'normalized_email' => 'ada@example.com',
    ]);
    MarketingProfileLink::query()->create([
        'tenant_id' => 1,
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:12345',
        'match_method' => 'test',
        'confidence' => 1,
    ]);

    $order = Order::factory()->create([
        'tenant_id' => 1,
        'shopify_customer_id' => '12345',
        'order_number' => 'MF-1002',
        'total_price' => 28.00,
        'ordered_at' => now(),
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'raw_title' => 'Forest Ember Candle',
        'raw_variant' => '8 oz candle',
        'quantity' => 1,
    ]);

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response([
            'errors' => 'Not Found',
        ], 404),
    ]);

    $this->withToken('mf-test-profile:'.$profile->id)
        ->getJson('/api/mobile/v1/modern-forestry/account')
        ->assertOk()
        ->assertJsonPath('data.customer.displayName', 'Ada Woods')
        ->assertJsonPath('data.orders.0.orderNumber', 'MF-1002')
        ->assertJsonPath('data.orders.0.lines.0.title', 'Forest Ember Candle')
        ->assertJsonPath('data.orders.0.lines.0.canReorder', false);
});

test('mobile account profile photo endpoint stores clears and exposes the avatar url', function (): void {
    Storage::fake('public');

    $profile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'first_name' => 'Ada',
        'last_name' => 'Woods',
        'email' => 'ada@example.com',
        'normalized_email' => 'ada@example.com',
    ]);

    $photoData = base64_encode('fake-jpeg-binary');

    $this->withToken('mf-test-profile:'.$profile->id)
        ->postJson('/api/mobile/v1/modern-forestry/account/profile-photo', [
            'photoData' => $photoData,
        ])
        ->assertOk()
        ->assertJsonPath('data.displayName', 'Ada Woods');

    $profile->refresh();

    expect($profile->mobile_avatar_path)->not->toBeNull()
        ->and($profile->mobile_avatar_uploaded_at)->not->toBeNull();

    Storage::disk('public')->assertExists((string) $profile->mobile_avatar_path);

    $this->withToken('mf-test-profile:'.$profile->id)
        ->getJson('/api/mobile/v1/modern-forestry/account')
        ->assertOk()
        ->assertJsonPath('data.customer.avatarUrl', Storage::disk('public')->url((string) $profile->mobile_avatar_path));

    $this->withToken('mf-test-profile:'.$profile->id)
        ->postJson('/api/mobile/v1/modern-forestry/account/profile-photo', [
            'clear' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.avatarUrl', null);

    Storage::disk('public')->assertMissing((string) $profile->mobile_avatar_path);

    expect($profile->fresh()?->mobile_avatar_path)->toBeNull();
});

test('mobile rewards endpoint returns balance rewards history and can redeem natively', function (): void {
    $profile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'rewards@example.com',
        'normalized_email' => 'rewards@example.com',
    ]);
    CandleCashBalance::query()->create([
        'marketing_profile_id' => $profile->id,
        'balance' => 25,
    ]);
    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'candle_cash_delta' => 25,
        'source' => 'order',
        'source_id' => 'MF-1',
        'description' => 'Earned from order MF-1.',
    ]);
    CandleCashReward::query()->delete();
    $reward = CandleCashReward::query()->create([
        'name' => '$10 coupon',
        'description' => 'Redeem for a $10 discount.',
        'candle_cash_cost' => 10,
        'reward_type' => 'coupon',
        'reward_value' => '10USD',
        'is_active' => true,
    ]);

    $this->withToken('mf-test-profile:'.$profile->id)
        ->getJson('/api/mobile/v1/modern-forestry/rewards')
        ->assertOk()
        ->assertJsonPath('data.customer.email', 'rewards@example.com')
        ->assertJsonPath('data.history.0.description', 'Earned from order MF-1.')
        ->assertJsonPath('data.rewards.0.id', $reward->id);

    $this->withToken('mf-test-profile:'.$profile->id)
        ->postJson('/api/mobile/v1/modern-forestry/rewards/redeem', [
            'rewardId' => $reward->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.redemption.status', 'issued')
        ->assertJsonPath('data.redemption.amountFormatted', '$10.00');
});

test('mobile account message endpoint accepts signed in native support messages', function (): void {
    $profile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'support@example.com',
        'normalized_email' => 'support@example.com',
    ]);
    TenantMarketingSetting::query()->updateOrCreate(
        [
            'tenant_id' => 1,
            'key' => 'modern_forestry_mobile_support_alerts',
        ],
        [
            'value' => [
                'support_alert_phone' => '+18645550123',
            ],
            'description' => 'Test mobile support alert routing.',
        ]
    );

    $twilio = \Mockery::mock(TwilioSmsService::class);
    $twilio->shouldReceive('sendSms')
        ->once()
        ->withArgs(function (string $toPhone, string $message, array $options): bool {
            return $toPhone === '+18645550123'
                && str_contains($message, 'Modern Forestry app support message')
                && str_contains($message, 'support@example.com')
                && str_contains($message, 'Can you help with my order?')
                && array_key_exists('status_callback_url', $options)
                && $options['status_callback_url'] === null;
        })
        ->andReturn([
            'success' => true,
            'provider' => 'twilio',
            'provider_message_id' => 'sms-support-alert-1',
            'status' => 'sent',
            'error_code' => null,
            'error_message' => null,
            'sender_key' => 'primary',
            'sender_label' => 'Primary Sender',
            'from_identifier' => '+15553330000',
            'delivery_mode' => 'sms',
            'requested_delivery_mode' => 'sms',
            'payload' => [],
            'dry_run' => false,
        ]);
    app()->instance(TwilioSmsService::class, $twilio);

    $this->withToken('mf-test-profile:'.$profile->id)
        ->postJson('/api/mobile/v1/modern-forestry/account/message', [
            'message' => 'Can you help with my order?',
        ])
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.state', 'received')
        ->assertJsonPath('data.support.messages.0.body', 'Can you help with my order?');

    $conversation = MessagingConversation::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('source_type', 'modern_forestry_app')
        ->first();

    expect($conversation)->not->toBeNull()
        ->and($conversation?->channel)->toBe('email')
        ->and((int) ($conversation?->unread_count ?? 0))->toBe(1);

    $message = MessagingConversationMessage::query()
        ->where('conversation_id', $conversation?->id)
        ->latest('id')
        ->first();

    expect($message)->not->toBeNull()
        ->and($message?->direction)->toBe('inbound')
        ->and($message?->message_type)->toBe('app_message');
});

test('mobile support payload exposes unread app replies and can mark them read', function (): void {
    $profile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'phone' => '+15555550123',
        'normalized_phone' => '+15555550123',
        'accepts_sms_marketing' => true,
    ]);

    $conversation = MessagingConversation::query()->create([
        'tenant_id' => 1,
        'store_key' => 'retail',
        'channel' => 'sms',
        'marketing_profile_id' => $profile->id,
        'phone' => '+15555550123',
        'status' => 'open',
        'source_type' => 'modern_forestry_app',
        'source_context' => [
            'thread_kind' => 'support',
            'reply_via' => 'app',
        ],
    ]);

    MessagingConversationMessage::query()->create([
        'conversation_id' => $conversation->id,
        'tenant_id' => 1,
        'store_key' => 'retail',
        'marketing_profile_id' => $profile->id,
        'channel' => 'sms',
        'direction' => 'outbound',
        'provider' => 'modern_forestry_app',
        'body' => 'We can help with that order.',
        'sent_at' => now(),
        'delivery_status' => 'queued_for_app',
        'message_type' => 'app_message',
    ]);

    $this->withToken('mf-test-profile:'.$profile->id)
        ->getJson('/api/mobile/v1/modern-forestry/account')
        ->assertOk()
        ->assertJsonPath('data.support.unreadCount', 1)
        ->assertJsonPath('data.support.messages.0.isUnread', true)
        ->assertJsonPath('data.support.messages.0.direction', 'outbound');

    $this->withToken('mf-test-profile:'.$profile->id)
        ->postJson('/api/mobile/v1/modern-forestry/account/messages/read')
        ->assertOk()
        ->assertJsonPath('data.unreadCount', 0);

    expect(MessagingConversationMessage::query()->first()?->customer_read_at)->not->toBeNull();
});

test('mobile wishlist endpoints reuse the native laravel wishlist state', function (): void {
    $profile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'wishlist@example.com',
        'normalized_email' => 'wishlist@example.com',
    ]);

    $product = [
        'product_id' => 'gid://shopify/Product/111',
        'product_variant_id' => 'gid://shopify/ProductVariant/9001',
        'product_handle' => 'forest-ember-candle',
        'product_title' => 'Forest Ember Candle',
        'product_url' => 'https://theforestrystudio.com/products/forest-ember-candle',
    ];

    $this->withToken('mf-test-profile:'.$profile->id)
        ->postJson('/api/mobile/v1/modern-forestry/wishlist/add', [
            ...$product,
            'list_name' => 'Favorites',
        ])
        ->assertOk()
        ->assertJsonPath('data.state', 'wishlist_added')
        ->assertJsonPath('data.payload.summary.active_count', 1)
        ->assertJsonPath('data.item.product_title', 'Forest Ember Candle')
        ->assertJsonPath('data.item.status', 'active');

    $this->withToken('mf-test-profile:'.$profile->id)
        ->getJson('/api/mobile/v1/modern-forestry/wishlist/status?'.http_build_query($product + ['limit' => 1]))
        ->assertOk()
        ->assertJsonPath('data.summary.active_count', 1)
        ->assertJsonPath('data.product.in_wishlist', true);

    $this->withToken('mf-test-profile:'.$profile->id)
        ->postJson('/api/mobile/v1/modern-forestry/wishlist/remove', $product)
        ->assertOk()
        ->assertJsonPath('data.state', 'wishlist_removed')
        ->assertJsonPath('data.payload.summary.active_count', 0)
        ->assertJsonPath('data.payload.product.in_wishlist', false);
});

test('mobile scents endpoint returns active scents for bundle builders', function (): void {
    Scent::query()->create([
        'name' => 'Forest Ember',
        'display_name' => 'Forest Ember',
        'abbreviation' => 'F',
        'is_active' => true,
        'sort_order' => 2,
    ]);
    Scent::query()->create([
        'name' => 'Oakmoss Amber',
        'display_name' => 'Oakmoss Amber',
        'abbreviation' => 'O',
        'is_active' => true,
        'sort_order' => 1,
    ]);
    Scent::query()->create([
        'name' => 'Hidden Scent',
        'display_name' => 'Hidden Scent',
        'abbreviation' => 'H',
        'is_active' => false,
        'sort_order' => 3,
    ]);

    $payload = $this->getJson('/api/mobile/v1/modern-forestry/scents')
        ->assertOk()
        ->json();
    expect(collect($payload['data'])->pluck('name')->all())->toContain('Forest Ember', 'Oakmoss Amber')
        ->and(collect($payload['data'])->pluck('name')->all())->not->toContain('Hidden Scent');
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
                        'imageUrl',
                        'selectedOptions',
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

test('mobile product detail exposes bundle scent requirements and active scent options', function (): void {
    Scent::query()->create([
        'name' => 'Forest Ember',
        'display_name' => 'Forest Ember',
        'abbreviation' => 'F',
        'is_active' => true,
        'sort_order' => 1,
    ]);
    Scent::query()->create([
        'name' => 'Oakmoss Amber',
        'display_name' => 'Oakmoss Amber',
        'abbreviation' => 'O',
        'is_active' => true,
        'sort_order' => 2,
    ]);

    $payload = shopifyMobileProductDetailPayload();
    $payload['data']['products']['nodes'][0]['title'] = '3 16oz Soy Candle Bundle';
    $payload['data']['products']['nodes'][0]['handle'] = '3-16oz-soy-candle-bundle';
    $payload['data']['products']['nodes'][0]['productType'] = 'Bundle';
    $payload['data']['products']['nodes'][0]['tags'] = ['bundle', 'gift set'];

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response($payload, 200),
    ]);

    $payload = $this->getJson('/api/mobile/v1/modern-forestry/products/3-16oz-soy-candle-bundle')
        ->assertOk()
        ->json();

    expect(data_get($payload, 'data.bundle.requiredScentCount'))->toBe(3)
        ->and(data_get($payload, 'data.bundle.qtyPerScent'))->toBe(1)
        ->and(data_get($payload, 'data.bundle.selectionLabels'))->toBe(['Scent 1', 'Scent 2', 'Scent 3'])
        ->and(collect(data_get($payload, 'data.bundle.availableScents', []))->pluck('displayName')->all())
        ->toContain('Forest Ember', 'Oakmoss Amber');
});

test('mobile product detail includes laravel-backed review summary and approved reviews', function (): void {
    $profile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'first_name' => 'Ada',
        'last_name' => 'Woods',
        'email' => 'ada@example.com',
        'normalized_email' => 'ada@example.com',
    ]);

    MarketingReviewHistory::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => 1,
        'provider' => 'native_storefront',
        'integration' => 'shopify_product_reviews',
        'store_key' => 'retail',
        'external_customer_id' => 'reviewer-1',
        'external_review_id' => 'review-forest-ember-1',
        'rating' => 5,
        'title' => 'Coffeehouse level cozy',
        'body' => 'Strong throw, warm woods, and a little smoke in the best way.',
        'reviewer_name' => 'Ada Woods',
        'status' => 'approved',
        'is_published' => true,
        'is_verified_buyer' => true,
        'product_id' => 'gid://shopify/Product/111',
        'product_handle' => 'forest-ember-candle',
        'product_title' => 'Forest Ember Candle',
        'product_url' => 'https://theforestrystudio.com/products/forest-ember-candle',
        'submitted_at' => now()->subDay(),
        'approved_at' => now(),
        'reviewed_at' => now(),
    ]);

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyMobileProductDetailPayload(), 200),
    ]);

    $this->getJson('/api/mobile/v1/modern-forestry/products/forest-ember-candle')
        ->assertOk()
        ->assertJsonPath('data.reviews.summary.review_count', 1)
        ->assertJsonPath('data.reviews.summary.rating_label', '5.0 out of 5')
        ->assertJsonPath('data.reviews.reviews.0.title', 'Coffeehouse level cozy')
        ->assertJsonPath('data.reviews.reviews.0.reviewer_name', 'Ada Woods');
});

test('mobile scent quiz endpoints return the authored quiz and persist results to the profile', function (): void {
    $profile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'first_name' => 'Ada',
        'last_name' => 'Woods',
        'email' => 'ada@example.com',
        'normalized_email' => 'ada@example.com',
    ]);

    $definition = $this->withToken('mf-test-profile:'.$profile->id)
        ->getJson('/api/mobile/v1/modern-forestry/scent-quiz')
        ->assertOk()
        ->assertJsonPath('data.version', 'scent-v1')
        ->assertJsonCount(15, 'data.questions')
        ->json('data');

    $answers = collect($definition['questions'])
        ->map(fn (array $question): array => [
            'question_id' => $question['id'],
            'option_id' => $question['options'][0]['id'],
        ])
        ->values()
        ->all();

    $this->withToken('mf-test-profile:'.$profile->id)
        ->postJson('/api/mobile/v1/modern-forestry/scent-quiz/results', [
            'answers' => $answers,
        ])
        ->assertOk()
        ->assertJsonPath('data.version', 'scent-v1')
        ->assertJsonPath('data.axes.0.label', 'Floral')
        ->assertJsonCount(8, 'data.axes');

    $saved = MarketingProfileScentQuizResult::query()
        ->where('marketing_profile_id', $profile->id)
        ->first();

    expect($saved)->not->toBeNull()
        ->and($saved?->quiz_version)->toBe('scent-v1')
        ->and($saved?->headline)->not->toBeNull();
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
        ->toBe(['spring', 'classic', 'summer', 'holiday', 'autumn', 'bundles']);

    foreach ($payload['featuredCollections'] as $collection) {
        expect($collection['imageUrl'] ?? null)->toBeString()->not->toBe('');
    }
});

test('real mobile home featured products use actual purchase history before shopify fallback', function (): void {
    config()->set('mobile_catalog.fake_enabled', false);

    $order = Order::factory()->create([
        'tenant_id' => 1,
        'shopify_store_key' => 'retail',
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'shopify_product_id' => 222,
        'shopify_variant_id' => 9003,
        'quantity' => 8,
        'ordered_qty' => 8,
        'raw_title' => 'Pine Ridge Candle',
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'shopify_product_id' => 111,
        'shopify_variant_id' => 9001,
        'quantity' => 1,
        'ordered_qty' => 1,
        'raw_title' => 'Forest Ember Candle',
    ]);

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => function (Request $request) {
            $body = json_decode($request->body(), true);
            $query = (string) ($body['query'] ?? '');
            $variables = $body['variables'] ?? [];

            if (str_contains($query, 'query MobileCatalogCollections')) {
                return Http::response(shopifyMobileCollectionsPayload(), 200);
            }

            if (str_contains($query, 'query MobileCatalogProductsByIds')) {
                expect($variables['ids'] ?? [])->toBe([
                    'gid://shopify/Product/222',
                    'gid://shopify/Product/111',
                ]);

                return Http::response(shopifyMobileProductsByIdsPayload(), 200);
            }

            if (str_contains($query, 'query MobileCatalogProducts')) {
                expect($variables['first'] ?? null)->toBe(50);
                expect($query)->toContain('sortKey: UPDATED_AT');
                expect($query)->toContain('reverse: true');

                return Http::response(shopifyMobileCatalogPayload(), 200);
            }

            return Http::response([], 404);
        },
    ]);

    $payload = $this->getJson('/api/mobile/v1/modern-forestry/home')
        ->assertOk()
        ->json();

    expect($payload['featuredProducts'][0]['handle'] ?? null)->toBe('pine-ridge-candle');
    expect($payload['featuredProducts'][0]['variantId'] ?? null)->toBe('9003');
    expect($payload['featuredProducts'][1]['handle'] ?? null)->toBe('forest-ember-candle');
    expect($payload['featuredProducts'][1]['variantId'] ?? null)->toBe('9001');
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
        ->assertJsonPath('collection.handle', 'autumn')
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
        ->assertJsonPath('collection.handle', 'autumn');
})->with([
    'best selling' => ['best_selling', 'BEST_SELLING', false],
    'newest' => ['newest', 'CREATED', true],
    'price low to high' => ['price_low_to_high', 'PRICE', false],
    'price high to low' => ['price_high_to_low', 'PRICE', true],
]);

test('real mobile collection products reuse cached seasonal payloads for repeat requests', function (): void {
    config()->set('mobile_catalog.fake_enabled', false);

    $requests = 0;

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => function (Request $request) use (&$requests) {
            $requests++;

            $body = json_decode($request->body(), true);
            $query = (string) ($body['query'] ?? '');

            if (str_contains($query, 'query MobileCatalogCollections')) {
                return Http::response(shopifyMobileCollectionsPayload(), 200);
            }

            if (str_contains($query, 'query MobileCatalogCollectionProducts')) {
                return Http::response(shopifyMobileCollectionProductsPayload(), 200);
            }

            return Http::response([], 404);
        },
    ]);

    $this->getJson('/api/mobile/v1/modern-forestry/collections/fall/products?sort=best_selling')
        ->assertOk()
        ->assertJsonPath('collection.handle', 'autumn');

    $this->getJson('/api/mobile/v1/modern-forestry/collections/fall/products?sort=best_selling')
        ->assertOk()
        ->assertJsonPath('collection.handle', 'autumn');

    expect($requests)->toBe(2);
});

test('seasonal collection resolver prefers canonical summer holiday and autumn collections over sale collections', function (): void {
    $service = app(ModernForestryMobileProductCatalogService::class);
    $reflection = new \ReflectionClass($service);
    $resolveMethod = $reflection->getMethod('resolveSeasonalCollectionNode');

    $nodes = [
        [
            'handle' => 'summer-one-day-sale',
            'title' => 'Summer One Day Sale',
        ],
        [
            'handle' => 'summer-collection',
            'title' => 'Summer Collection',
        ],
        [
            'handle' => 'thanksgiving-day-sale',
            'title' => 'Thanksgiving Day Sale',
        ],
        [
            'handle' => 'holiday-collection',
            'title' => 'Holiday Collection',
        ],
        [
            'handle' => 'fall-collection',
            'title' => 'Fall Collection',
        ],
        [
            'handle' => 'autumn-collection',
            'title' => 'Autumn Collection',
        ],
    ];

    $summerMatch = $resolveMethod->invoke($service, 'summer', $nodes);
    $holidayMatch = $resolveMethod->invoke($service, 'holiday', $nodes);
    $fallMatch = $resolveMethod->invoke($service, 'fall', $nodes);

    expect($summerMatch['handle'] ?? null)->toBe('summer-collection')
        ->and($holidayMatch['handle'] ?? null)->toBe('holiday-collection')
        ->and($fallMatch['handle'] ?? null)->toBe('autumn-collection');
});

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
        ->assertJsonPath('data.variants.0.imageUrl', 'https://cdn.shopify.com/s/files/8oz-reference.png?width=1200')
        ->assertJsonPath('data.variants.0.selectedOptions.0.name', 'Size')
        ->assertJsonPath('data.variants.0.selectedOptions.0.value', '8 oz candle')
        ->assertJsonPath('data.variants.1.imageUrl', 'https://cdn.shopify.com/s/files/16oz-reference.png?width=1200')
        ->assertJsonPath('data.variants.1.selectedOptions.0.value', '16 oz candle')
        ->assertJsonPath('data.price', '24.00')
        ->assertJsonPath('data.compareAtPrice', null)
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.productType', 'Candle')
        ->assertJsonPath('data.tags.0', 'amber')
        ->assertJsonPath('data.scentNotes.0', 'amber')
        ->assertJsonPath('data.faq', []);
});

test('mobile product detail prefers canonical product media for variant images when Shopify keeps an older variant image first', function (): void {
    $payload = shopifyMobileProductDetailPayload();
    $payload['data']['products']['nodes'][0]['media'] = [
        'nodes' => [
            [
                'id' => 'gid://shopify/MediaImage/4001',
                'alt' => 'mf-app-variant-size:4oz Modern Forestry 4 oz size reference',
                'image' => [
                    'url' => 'https://cdn.shopify.com/s/files/4oz-reference.png',
                    'altText' => 'mf-app-variant-size:4oz Modern Forestry 4 oz size reference',
                ],
            ],
            [
                'id' => 'gid://shopify/MediaImage/8001',
                'alt' => 'mf-app-variant-size:8oz Modern Forestry 8 oz size reference',
                'image' => [
                    'url' => 'https://cdn.shopify.com/s/files/8oz-reference.png',
                    'altText' => 'mf-app-variant-size:8oz Modern Forestry 8 oz size reference',
                ],
            ],
            [
                'id' => 'gid://shopify/MediaImage/16001',
                'alt' => 'mf-app-variant-size:16oz Modern Forestry 16 oz size reference',
                'image' => [
                    'url' => 'https://cdn.shopify.com/s/files/16oz-reference.png',
                    'altText' => 'mf-app-variant-size:16oz Modern Forestry 16 oz size reference',
                ],
            ],
        ],
    ];
    $payload['data']['products']['nodes'][0]['variants']['nodes'][0]['title'] = '4 oz candle';
    $payload['data']['products']['nodes'][0]['variants']['nodes'][0]['selectedOptions'][0]['value'] = '4 oz candle';
    $payload['data']['products']['nodes'][0]['variants']['nodes'][0]['media'] = [
        'nodes' => [
            [
                'id' => 'gid://shopify/MediaImage/legacy-4001',
                'image' => [
                    'url' => 'https://cdn.shopify.com/s/files/legacy-product-image.png',
                    'altText' => '',
                ],
            ],
        ],
    ];

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response($payload, 200),
    ]);

    $this->getJson('/api/mobile/v1/modern-forestry/products/forest-ember-candle')
        ->assertOk()
        ->assertJsonPath('data.variants.0.imageUrl', 'https://cdn.shopify.com/s/files/4oz-reference.png?width=1200');
});

test('mobile product detail endpoint falls back to a broader active catalog lookup when the exact handle search misses', function (): void {
    $requests = [];

    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => function (Request $request) use (&$requests) {
            $body = json_decode($request->body(), true);
            $requests[] = $body;

            if (count($requests) === 1) {
                return Http::response([
                    'data' => [
                        'products' => [
                            'nodes' => [],
                        ],
                    ],
                ], 200);
            }

            if (count($requests) === 2) {
                return Http::response([
                    'data' => [
                        'products' => [
                            'nodes' => [
                                [
                                    'id' => 'gid://shopify/Product/222',
                                    'title' => 'Summer Linen',
                                    'handle' => 'summer-linen',
                                    'description' => 'A different visible product.',
                                    'descriptionHtml' => '<p>A different visible product.</p>',
                                    'onlineStoreUrl' => 'https://modernforestry-test.myshopify.com/products/summer-linen',
                                    'productType' => 'Candle',
                                    'tags' => ['linen'],
                                    'status' => 'ACTIVE',
                                    'images' => [
                                        'nodes' => [
                                            [
                                                'url' => 'https://cdn.shopify.com/s/files/summer-linen.png',
                                                'altText' => 'Summer Linen candle jar',
                                            ],
                                        ],
                                    ],
                                    'variants' => [
                                        'nodes' => [
                                            [
                                                'id' => 'gid://shopify/ProductVariant/9003',
                                                'title' => '8 oz candle',
                                                'price' => '30.00',
                                                'compareAtPrice' => null,
                                                'availableForSale' => true,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'pageInfo' => [
                                'hasNextPage' => true,
                                'endCursor' => 'cursor-1',
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(shopifyMobileProductDetailPayload(), 200);
        },
    ]);

    $this->getJson('/api/mobile/v1/modern-forestry/products/forest-ember-candle')
        ->assertOk()
        ->assertJsonPath('meta.source', 'shopify')
        ->assertJsonPath('data.handle', 'forest-ember-candle')
        ->assertJsonPath('data.title', 'Forest Ember Candle');

    expect($requests)->toHaveCount(3);
    expect((string) ($requests[0]['query'] ?? ''))->toContain('query MobileCatalogProductDetail');
    expect((string) ($requests[1]['query'] ?? ''))->toContain('query MobileCatalogActiveProductDetails');
    expect($requests[1]['variables']['first'] ?? null)->toBe(50);
    expect($requests[2]['variables']['after'] ?? null)->toBe('cursor-1');
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
                'attributes' => [
                    [
                        'key' => 'Scent 1',
                        'value' => 'Forest Ember',
                    ],
                ],
            ],
        ],
        'discountCode' => ' candlecash10 ',
        'customerAccessToken' => 'customer-account-test-token',
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
    expect($body['variables']['input']['lines'][0]['attributes'])->toBe([
        [
            'key' => 'Scent 1',
            'value' => 'Forest Ember',
        ],
    ]);
    expect($body['variables']['input']['discountCodes'])->toBe(['CANDLECASH10']);
    expect($body['variables']['input']['buyerIdentity']['customerAccessToken'])->toBe('customer-account-test-token');
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
                        'id' => 'gid://shopify/ProductVariant/9001',
                        'price' => '24.00',
                        'compareAtPrice' => null,
                        'availableForSale' => true,
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
                        'id' => 'gid://shopify/ProductVariant/9003',
                        'price' => '28.00',
                        'compareAtPrice' => '32.00',
                        'availableForSale' => true,
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
function shopifyMobileProductsByIdsPayload(): array
{
    return [
        'data' => [
            'nodes' => shopifyMobileCatalogPayload()['data']['products']['nodes'],
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
                                                'id' => 'gid://shopify/ProductVariant/9001',
                                                'price' => '24.00',
                                                'compareAtPrice' => null,
                                                'availableForSale' => true,
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
                                                'id' => 'gid://shopify/ProductVariant/9002',
                                                'price' => '12.00',
                                                'compareAtPrice' => null,
                                                'availableForSale' => true,
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
                                                'id' => 'gid://shopify/ProductVariant/9004',
                                                'price' => '18.00',
                                                'compareAtPrice' => null,
                                                'availableForSale' => true,
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
                                                'id' => 'gid://shopify/ProductVariant/9005',
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
                                                'id' => 'gid://shopify/ProductVariant/9006',
                                                'price' => '29.00',
                                                'compareAtPrice' => '34.00',
                                                'availableForSale' => true,
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
                        'media' => [
                            'nodes' => [],
                        ],
                        'variants' => [
                            'nodes' => [
                                [
                                    'id' => 'gid://shopify/ProductVariant/9001',
                                    'title' => '8 oz candle',
                                    'price' => '24.00',
                                    'compareAtPrice' => null,
                                    'availableForSale' => true,
                                    'selectedOptions' => [
                                        [
                                            'name' => 'Size',
                                            'value' => '8 oz candle',
                                        ],
                                    ],
                                    'media' => [
                                        'nodes' => [
                                            [
                                                'id' => 'gid://shopify/MediaImage/8001',
                                                'image' => [
                                                    'url' => 'https://cdn.shopify.com/s/files/8oz-reference.png',
                                                    'altText' => 'Modern Forestry 8 oz size reference',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                [
                                    'id' => 'gid://shopify/ProductVariant/9002',
                                    'title' => '16 oz candle',
                                    'price' => '32.00',
                                    'compareAtPrice' => '36.00',
                                    'availableForSale' => false,
                                    'selectedOptions' => [
                                        [
                                            'name' => 'Size',
                                            'value' => '16 oz candle',
                                        ],
                                    ],
                                    'media' => [
                                        'nodes' => [
                                            [
                                                'id' => 'gid://shopify/MediaImage/16001',
                                                'image' => [
                                                    'url' => 'https://cdn.shopify.com/s/files/16oz-reference.png',
                                                    'altText' => 'Modern Forestry 16 oz size reference',
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
