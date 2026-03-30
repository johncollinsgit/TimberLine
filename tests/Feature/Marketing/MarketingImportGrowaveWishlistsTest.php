<?php

use App\Models\CustomerExternalProfile;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileWishlistItem;
use App\Models\MarketingSegment;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\MarketingSegmentEvaluator;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('marketing.growave.enabled', true);
    config()->set('marketing.growave.base_url', 'https://api.growave.io');
    config()->set('marketing.growave.client_id', 'test-client');
    config()->set('marketing.growave.client_secret', 'test-secret');
    config()->set('marketing.growave.scope', 'read_customer read_review read_reward read_wishlist');

    $this->tenant = Tenant::query()->create([
        'name' => 'Growave Wishlist Tenant',
        'slug' => 'growave-wishlist-tenant',
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $this->tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'growave-wishlist-retail.myshopify.com',
        'access_token' => 'growave-wishlist-token',
        'installed_at' => now(),
    ]);
});

test('growave wishlist backfill imports canonical rows with provenance and remains idempotent', function () {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Legacy',
        'last_name' => 'Wishlist',
        'email' => 'legacy.wishlist@example.com',
        'normalized_email' => 'legacy.wishlist@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => $profile->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '5001',
        'email' => 'legacy.wishlist@example.com',
        'normalized_email' => 'legacy.wishlist@example.com',
        'synced_at' => now(),
    ]);

    Http::fake([
        'https://api.growave.io/v2/oauth/getAccessToken' => Http::response([
            'accessToken' => 'growave-token',
            'tokenType' => 'Bearer',
            'expiresAt' => now()->addHour()->toIso8601String(),
        ], 200),
        'https://api.growave.io/v2/wishlists/getWishlists*' => Http::response([
            'totalCount' => 1,
            'currentOffset' => 0,
            'perPage' => 50,
            'items' => [
                [
                    'id' => 'wl-5001',
                    'createdAt' => now()->subDays(10)->toIso8601String(),
                    'updatedAt' => now()->subDays(2)->toIso8601String(),
                    'items' => [
                        [
                            'id' => 'wli-901',
                            'createdAt' => now()->subDays(9)->toIso8601String(),
                            'product' => [
                                'shopifyProductId' => '901',
                                'handle' => 'cedar-glow',
                                'title' => 'Cedar Glow',
                            ],
                        ],
                        [
                            'id' => 'wli-902',
                            'createdAt' => now()->subDays(8)->toIso8601String(),
                            'removedAt' => now()->subDay()->toIso8601String(),
                            'isRemoved' => true,
                            'product' => [
                                'shopifyProductId' => 'gid://shopify/Product/902',
                                'handle' => 'heritage-pine',
                                'title' => 'Heritage Pine',
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('marketing:import-growave-wishlists --store=retail --limit=10')
        ->expectsOutputToContain('status=completed')
        ->assertExitCode(0);

    $this->artisan('marketing:import-growave-wishlists --store=retail --limit=10')
        ->expectsOutputToContain('status=completed')
        ->assertExitCode(0);

    $rows = MarketingProfileWishlistItem::query()
        ->where('marketing_profile_id', $profile->id)
        ->orderBy('product_id')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('provider')->unique()->values()->all())->toBe(['growave'])
        ->and($rows->pluck('integration')->unique()->values()->all())->toBe(['growave'])
        ->and($rows->pluck('source')->unique()->values()->all())->toBe(['growave_wishlist_import']);

    $first = $rows->firstWhere('product_id', '901');
    $second = $rows->firstWhere('product_id', '902');

    expect($first)->not->toBeNull()
        ->and($first?->status)->toBe(MarketingProfileWishlistItem::STATUS_ACTIVE)
        ->and((string) $first?->product_handle)->toBe('cedar-glow')
        ->and((string) $first?->store_key)->toBe('retail');

    expect($second)->not->toBeNull()
        ->and($second?->status)->toBe(MarketingProfileWishlistItem::STATUS_REMOVED)
        ->and((string) $second?->product_handle)->toBe('heritage-pine')
        ->and($second?->removed_at)->not->toBeNull();
});

test('growave wishlist backfill keeps native rows authoritative and reports unmappable rows', function () {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Native',
        'last_name' => 'Priority',
        'email' => 'native.priority@example.com',
        'normalized_email' => 'native.priority@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => $profile->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '5002',
        'email' => 'native.priority@example.com',
        'normalized_email' => 'native.priority@example.com',
        'synced_at' => now(),
    ]);

    MarketingProfileWishlistItem::query()->create([
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => $profile->id,
        'provider' => 'backstage',
        'integration' => 'native',
        'store_key' => 'retail',
        'product_id' => '901',
        'product_handle' => 'cedar-glow',
        'product_title' => 'Cedar Glow',
        'status' => MarketingProfileWishlistItem::STATUS_ACTIVE,
        'source' => 'native_storefront',
        'added_at' => now()->subDay(),
        'last_added_at' => now()->subDay(),
    ]);

    Http::fake([
        'https://api.growave.io/v2/oauth/getAccessToken' => Http::response([
            'accessToken' => 'growave-token',
            'tokenType' => 'Bearer',
            'expiresAt' => now()->addHour()->toIso8601String(),
        ], 200),
        'https://api.growave.io/v2/wishlists/getWishlists*' => Http::response([
            'totalCount' => 1,
            'currentOffset' => 0,
            'perPage' => 50,
            'items' => [
                [
                    'id' => 'wl-5002',
                    'items' => [
                        [
                            'id' => 'wli-901',
                            'product' => [
                                'shopifyProductId' => '901',
                                'handle' => 'cedar-glow',
                                'title' => 'Cedar Glow',
                            ],
                        ],
                        [
                            'id' => 'wli-unmappable',
                            'product' => [
                                'handle' => 'missing-id-product',
                                'title' => 'Missing ID Product',
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('marketing:import-growave-wishlists --store=retail --limit=10')
        ->expectsOutputToContain('status=completed')
        ->assertExitCode(0);

    expect(MarketingProfileWishlistItem::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('store_key', 'retail')
        ->where('product_id', '901')
        ->count())->toBe(1);

    $native = MarketingProfileWishlistItem::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('store_key', 'retail')
        ->where('product_id', '901')
        ->first();

    expect($native)->not->toBeNull()
        ->and((string) $native?->provider)->toBe('backstage')
        ->and((string) $native?->integration)->toBe('native')
        ->and((string) $native?->status)->toBe(MarketingProfileWishlistItem::STATUS_ACTIVE);
});

test('migrated growave wishlist rows appear in customer detail and segment metrics', function () {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Projected',
        'last_name' => 'Legacy',
        'email' => 'projected.legacy@example.com',
        'normalized_email' => 'projected.legacy@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => $profile->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '5003',
        'email' => 'projected.legacy@example.com',
        'normalized_email' => 'projected.legacy@example.com',
        'synced_at' => now(),
    ]);

    Http::fake([
        'https://api.growave.io/v2/oauth/getAccessToken' => Http::response([
            'accessToken' => 'growave-token',
            'tokenType' => 'Bearer',
            'expiresAt' => now()->addHour()->toIso8601String(),
        ], 200),
        'https://api.growave.io/v2/wishlists/getWishlists*' => Http::response([
            'totalCount' => 1,
            'currentOffset' => 0,
            'perPage' => 50,
            'items' => [
                [
                    'id' => 'wl-5003',
                    'items' => [
                        [
                            'id' => 'wli-903',
                            'createdAt' => now()->subDays(5)->toIso8601String(),
                            'product' => [
                                'shopifyProductId' => '903',
                                'handle' => 'winter-flight',
                                'title' => 'Winter Flight',
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('marketing:import-growave-wishlists --store=retail --limit=10')
        ->assertExitCode(0);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $admin->tenants()->syncWithoutDetaching([$this->tenant->id]);

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', ['marketingProfile' => $profile->id, 'tenant' => $this->tenant->slug]))
        ->assertOk()
        ->assertSeeText('Legacy Wishlist Rows')
        ->assertSeeText('Winter Flight');

    $segment = MarketingSegment::query()->create([
        'name' => 'Migrated wishlist segment',
        'status' => 'active',
        'rules_json' => [
            'logic' => 'and',
            'conditions' => [
                ['field' => 'wishlist_active_count', 'operator' => 'gte', 'value' => 1],
                ['field' => 'wishlist_product_handle', 'operator' => 'contains', 'value' => 'winter-flight'],
            ],
            'groups' => [],
        ],
    ]);

    $result = app(MarketingSegmentEvaluator::class)->evaluateProfile($segment, $profile->fresh());

    expect($result['matched'])->toBeTrue()
        ->and($result['metrics']['wishlist_active_count'] ?? 0)->toBe(1)
        ->and($result['metrics']['wishlist_product_handles'] ?? [])->toContain('winter-flight');
});

test('growave wishlist backfill binds one tenant owner per run and does not sweep other tenants', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'Wishlist Tenant A',
        'slug' => 'wishlist-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Wishlist Tenant B',
        'slug' => 'wishlist-tenant-b',
    ]);

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'tenant_id' => $tenantA->id,
            'shop_domain' => 'wishlist-tenant-a.myshopify.com',
            'access_token' => 'wishlist-tenant-a-token',
            'installed_at' => now(),
        ]
    );

    $profileA = MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Tenant',
        'last_name' => 'A',
        'email' => 'tenant-a-wishlist@example.com',
        'normalized_email' => 'tenant-a-wishlist@example.com',
    ]);
    $profileB = MarketingProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'first_name' => 'Tenant',
        'last_name' => 'B',
        'email' => 'tenant-b-wishlist@example.com',
        'normalized_email' => 'tenant-b-wishlist@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'marketing_profile_id' => $profileA->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '7001',
        'email' => 'tenant-a-wishlist@example.com',
        'normalized_email' => 'tenant-a-wishlist@example.com',
        'synced_at' => now(),
    ]);
    CustomerExternalProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'marketing_profile_id' => $profileB->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '8001',
        'email' => 'tenant-b-wishlist@example.com',
        'normalized_email' => 'tenant-b-wishlist@example.com',
        'synced_at' => now(),
    ]);

    Http::fake([
        'https://api.growave.io/v2/oauth/getAccessToken' => Http::response([
            'accessToken' => 'growave-token',
            'tokenType' => 'Bearer',
            'expiresAt' => now()->addHour()->toIso8601String(),
        ], 200),
        'https://api.growave.io/v2/wishlists/getWishlists*customerIdentifier=7001*' => Http::response([
            'totalCount' => 1,
            'currentOffset' => 0,
            'perPage' => 50,
            'items' => [
                [
                    'id' => 'wl-7001',
                    'items' => [
                        [
                            'id' => 'wli-7001',
                            'createdAt' => now()->subDay()->toIso8601String(),
                            'product' => [
                                'shopifyProductId' => '77001',
                                'handle' => 'tenant-a-item',
                                'title' => 'Tenant A Item',
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('marketing:import-growave-wishlists --tenant-id=' . $tenantA->id . ' --limit=10')
        ->expectsOutputToContain('status=completed')
        ->expectsOutputToContain('processed_candidates=1')
        ->assertExitCode(0);

    $run = MarketingImportRun::query()
        ->where('type', 'growave_wishlist_backfill')
        ->latest('id')
        ->firstOrFail();

    expect((int) $run->tenant_id)->toBe($tenantA->id)
        ->and(MarketingProfileWishlistItem::query()
            ->where('tenant_id', $tenantA->id)
            ->where('marketing_profile_id', $profileA->id)
            ->where('product_id', '77001')
            ->count())->toBe(1)
        ->and(MarketingProfileWishlistItem::query()
            ->where('tenant_id', $tenantB->id)
            ->where('marketing_profile_id', $profileB->id)
            ->count())->toBe(0);
});

test('growave wishlist backfill fails closed when explicit tenant conflicts with store owner', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'Wishlist Store Tenant A',
        'slug' => 'wishlist-store-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Wishlist Store Tenant B',
        'slug' => 'wishlist-store-tenant-b',
    ]);

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'tenant_id' => $tenantA->id,
            'shop_domain' => 'retail-tenant-a.myshopify.com',
            'access_token' => 'token-a',
            'installed_at' => now(),
        ]
    );

    Http::fake([
        'https://api.growave.io/v2/oauth/getAccessToken' => Http::response([
            'accessToken' => 'growave-token',
            'tokenType' => 'Bearer',
            'expiresAt' => now()->addHour()->toIso8601String(),
        ], 200),
    ]);

    $this->artisan('marketing:import-growave-wishlists --store=retail --tenant-id=' . $tenantB->id . ' --limit=10')
        ->expectsOutputToContain('conflicts with provided tenant context')
        ->assertExitCode(1);
});

test('growave wishlist backfill skips candidates when store ownership cannot be proven', function () {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Unowned',
        'last_name' => 'Store',
        'email' => 'unowned-store@example.com',
        'normalized_email' => 'unowned-store@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => $profile->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'unknown-store',
        'external_customer_id' => 'missing-owner-9001',
        'email' => 'unowned-store@example.com',
        'normalized_email' => 'unowned-store@example.com',
        'synced_at' => now(),
    ]);

    Http::fake([
        'https://api.growave.io/v2/oauth/getAccessToken' => Http::response([
            'accessToken' => 'growave-token',
            'tokenType' => 'Bearer',
            'expiresAt' => now()->addHour()->toIso8601String(),
        ], 200),
        'https://api.growave.io/v2/wishlists/getWishlists*' => Http::response([
            'totalCount' => 1,
            'currentOffset' => 0,
            'perPage' => 50,
            'items' => [
                [
                    'id' => 'wl-missing-owner',
                    'items' => [
                        [
                            'id' => 'wli-missing-owner',
                            'product' => [
                                'shopifyProductId' => '99001',
                                'handle' => 'unowned-store-item',
                                'title' => 'Unowned Store Item',
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('marketing:import-growave-wishlists --tenant-id=' . $this->tenant->id . ' --limit=10')
        ->expectsOutputToContain('errors=1')
        ->assertExitCode(1);

    expect(MarketingProfileWishlistItem::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('marketing_profile_id', $profile->id)
        ->count())->toBe(0);
});
