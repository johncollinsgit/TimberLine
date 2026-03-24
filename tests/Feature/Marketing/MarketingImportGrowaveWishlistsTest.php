<?php

use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileWishlistItem;
use App\Models\MarketingSegment;
use App\Models\User;
use App\Services\Marketing\MarketingSegmentEvaluator;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('marketing.growave.enabled', true);
    config()->set('marketing.growave.base_url', 'https://api.growave.io');
    config()->set('marketing.growave.client_id', 'test-client');
    config()->set('marketing.growave.client_secret', 'test-secret');
    config()->set('marketing.growave.scope', 'read_customer read_review read_reward read_wishlist');
});

test('growave wishlist backfill imports canonical rows with provenance and remains idempotent', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Legacy',
        'last_name' => 'Wishlist',
        'email' => 'legacy.wishlist@example.com',
        'normalized_email' => 'legacy.wishlist@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
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
        ->expectsOutputToContain('created=2')
        ->expectsOutputToContain('skipped_unmappable_product=0')
        ->assertExitCode(0);

    $this->artisan('marketing:import-growave-wishlists --store=retail --limit=10')
        ->expectsOutputToContain('status=completed')
        ->expectsOutputToContain('created=0')
        ->expectsOutputToContain('updated=0')
        ->expectsOutputToContain('unchanged=2')
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
        'first_name' => 'Native',
        'last_name' => 'Priority',
        'email' => 'native.priority@example.com',
        'normalized_email' => 'native.priority@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '5002',
        'email' => 'native.priority@example.com',
        'normalized_email' => 'native.priority@example.com',
        'synced_at' => now(),
    ]);

    MarketingProfileWishlistItem::query()->create([
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
        ->expectsOutputToContain('skipped_native_authoritative=1')
        ->expectsOutputToContain('skipped_unmappable_product=1')
        ->expectsOutputToContain('reason_native_authoritative=1')
        ->expectsOutputToContain('reason_missing_product_id=1')
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
        'first_name' => 'Projected',
        'last_name' => 'Legacy',
        'email' => 'projected.legacy@example.com',
        'normalized_email' => 'projected.legacy@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
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

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', $profile))
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

