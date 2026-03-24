<?php

use App\Models\MarketingProfile;
use App\Models\MarketingSegment;
use App\Models\MarketingProfileWishlistItem;
use App\Models\ShopifyStore;
use App\Models\User;
use App\Services\Marketing\MarketingProfileAnalyticsService;
use App\Services\Marketing\MarketingSegmentEvaluator;
use Illuminate\Support\Str;

test('wishlist csv import command imports ready rows into canonical wishlist table', function () {
    $profile = MarketingProfile::query()->create([
        'email' => 'wishlist.ready@example.com',
        'normalized_email' => 'wishlist.ready@example.com',
    ]);

    $path = writeWishlistCsv([
        [
            'import_status' => 'ready',
            'customer_email' => $profile->email,
            'store_key' => 'retail',
            'product_id' => '5001',
            'product_variant_id' => '5001-01',
            'product_handle' => 'cedar-glow',
            'product_title' => 'Cedar Glow',
            'product_url' => '/products/cedar-glow',
            'provider' => 'growave',
            'integration' => 'csv_backfill',
            'source' => 'growave_csv_import',
            'source_surface' => 'wishlist',
            'source_ref' => 'wl-5001',
            'raw_payload_json' => '{"legacy_id":"wl-5001"}',
            'added_at' => '2026-03-01 10:00:00',
        ],
        [
            'import_status' => 'needs_customer_mapping',
            'customer_email' => 'guest@example.com',
            'store_key' => 'retail',
            'product_id' => '5002',
            'product_handle' => 'ignored-guest-row',
        ],
    ]);

    $this->artisan('marketing:import-wishlist-csv', ['file' => $path])
        ->expectsOutputToContain('imported=1')
        ->expectsOutputToContain('skipped_guest_rows=1')
        ->expectsOutputToContain('reason_breakdown:')
        ->expectsOutputToContain('guest_rows=1')
        ->assertExitCode(0);

    $item = MarketingProfileWishlistItem::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('store_key', 'retail')
        ->where('product_id', '5001')
        ->first();

    expect($item)->not->toBeNull()
        ->and($item?->status)->toBe(MarketingProfileWishlistItem::STATUS_ACTIVE)
        ->and((string) $item?->provider)->toBe('growave')
        ->and((string) $item?->integration)->toBe('csv_backfill')
        ->and((string) $item?->source)->toBe('growave_csv_import')
        ->and((string) $item?->product_handle)->toBe('cedar-glow')
        ->and((string) $item?->source_ref)->toBe('wl-5001')
        ->and($item?->raw_payload)->toMatchArray(['legacy_id' => 'wl-5001']);
});

test('wishlist csv import command prevents duplicates and updates last_added_at', function () {
    $profile = MarketingProfile::query()->create([
        'email' => 'wishlist.duplicate@example.com',
        'normalized_email' => 'wishlist.duplicate@example.com',
    ]);

    $path = writeWishlistCsv([
        [
            'import_status' => 'ready',
            'customer_email' => $profile->email,
            'store_key' => 'retail',
            'product_id' => '6001',
            'product_handle' => 'winter-flight',
            'added_at' => '2026-03-01 08:00:00',
        ],
        [
            'import_status' => 'ready',
            'customer_email' => $profile->email,
            'store_key' => 'retail',
            'product_id' => '6001',
            'product_handle' => 'winter-flight',
            'added_at' => '2026-03-02 09:30:00',
            'source_ref' => 'updated-ref',
        ],
    ]);

    $this->artisan('marketing:import-wishlist-csv', ['file' => $path])
        ->expectsOutputToContain('imported=2')
        ->assertExitCode(0);

    expect(MarketingProfileWishlistItem::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('store_key', 'retail')
        ->where('product_id', '6001')
        ->count())->toBe(1);

    $item = MarketingProfileWishlistItem::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('store_key', 'retail')
        ->where('product_id', '6001')
        ->first();

    expect($item)->not->toBeNull()
        ->and($item?->last_added_at?->toDateTimeString())->toBe('2026-03-02 09:30:00')
        ->and((string) $item?->source_ref)->toBe('updated-ref');
});

test('wishlist csv import command skips ready rows with missing profile', function () {
    $path = writeWishlistCsv([
        [
            'import_status' => 'ready',
            'customer_email' => 'missing.profile@example.com',
            'store_key' => 'retail',
            'product_id' => '7001',
            'product_handle' => 'spring-flight',
        ],
    ]);

    $this->artisan('marketing:import-wishlist-csv', ['file' => $path])
        ->expectsOutputToContain('imported=0')
        ->expectsOutputToContain('skipped_missing_profile=1')
        ->assertExitCode(0);

    expect(MarketingProfileWishlistItem::query()->count())->toBe(0);
});

test('wishlist csv import command skips ready rows with missing product identity', function () {
    $profile = MarketingProfile::query()->create([
        'email' => 'wishlist.missing-product@example.com',
        'normalized_email' => 'wishlist.missing-product@example.com',
    ]);

    $path = writeWishlistCsv([
        [
            'import_status' => 'ready',
            'customer_email' => $profile->email,
            'store_key' => 'retail',
            'product_id' => '',
            'product_handle' => '',
        ],
    ]);

    $this->artisan('marketing:import-wishlist-csv', ['file' => $path])
        ->expectsOutputToContain('imported=0')
        ->expectsOutputToContain('skipped_missing_product=1')
        ->assertExitCode(0);

    expect(MarketingProfileWishlistItem::query()->count())->toBe(0);
});

test('wishlist csv import supports profile-email filter in dry-run and live mode', function () {
    $target = MarketingProfile::query()->create([
        'email' => 'wishlist.target@example.com',
        'normalized_email' => 'wishlist.target@example.com',
    ]);
    $other = MarketingProfile::query()->create([
        'email' => 'wishlist.other@example.com',
        'normalized_email' => 'wishlist.other@example.com',
    ]);

    $path = writeWishlistCsv([
        [
            'import_status' => 'ready',
            'customer_email' => $target->email,
            'store_key' => 'retail',
            'product_id' => '8101',
            'product_handle' => 'target-product',
        ],
        [
            'import_status' => 'ready',
            'customer_email' => $other->email,
            'store_key' => 'retail',
            'product_id' => '8102',
            'product_handle' => 'other-product',
        ],
    ]);

    $this->artisan('marketing:import-wishlist-csv', [
        'file' => $path,
        '--profile-email' => 'wishlist.target@example.com',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('imported=1')
        ->assertExitCode(0);

    expect(MarketingProfileWishlistItem::query()->count())->toBe(0);

    $this->artisan('marketing:import-wishlist-csv', [
        'file' => $path,
        '--profile-email' => 'wishlist.target@example.com',
    ])
        ->expectsOutputToContain('imported=1')
        ->assertExitCode(0);

    expect(MarketingProfileWishlistItem::query()
        ->where('marketing_profile_id', $target->id)
        ->where('product_id', '8101')
        ->count())->toBe(1)
        ->and(MarketingProfileWishlistItem::query()
            ->where('marketing_profile_id', $other->id)
            ->count())->toBe(0);
});

test('wishlist csv imported rows appear in customer detail with provenance', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Imported',
        'last_name' => 'Wishlist',
        'email' => 'wishlist.detail@example.com',
        'normalized_email' => 'wishlist.detail@example.com',
    ]);

    $path = writeWishlistCsv([
        [
            'import_status' => 'ready',
            'customer_email' => $profile->email,
            'store_key' => 'retail',
            'product_id' => '9011',
            'product_handle' => 'imported-detail-item',
            'product_title' => 'Imported Detail Item',
            'provider' => 'growave',
            'integration' => 'csv_backfill',
            'source' => 'wishlist_csv_import',
            'source_surface' => 'wishlist',
            'source_ref' => 'legacy-wl-9011',
            'source_synced_at' => '2026-03-10 12:00:00',
        ],
    ]);

    $this->artisan('marketing:import-wishlist-csv', ['file' => $path])
        ->assertExitCode(0);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', $profile))
        ->assertOk()
        ->assertSeeText('Legacy Wishlist Rows')
        ->assertSeeText('Imported Detail Item')
        ->assertSeeText('growave/csv_backfill')
        ->assertSeeText('wishlist_csv_import');
});

test('wishlist csv imported rows feed wishlist analytics and segment evaluator metrics', function () {
    $profile = MarketingProfile::query()->create([
        'email' => 'wishlist.analytics@example.com',
        'normalized_email' => 'wishlist.analytics@example.com',
    ]);

    $path = writeWishlistCsv([
        [
            'import_status' => 'ready',
            'customer_email' => $profile->email,
            'store_key' => 'retail',
            'product_id' => '9201',
            'product_handle' => 'recent-flight',
            'product_title' => 'Recent Flight',
            'added_at' => now()->subDays(5)->toDateTimeString(),
        ],
        [
            'import_status' => 'ready',
            'customer_email' => $profile->email,
            'store_key' => 'retail',
            'product_id' => '9202',
            'product_handle' => 'archive-flight',
            'product_title' => 'Archive Flight',
            'added_at' => now()->subDays(45)->toDateTimeString(),
        ],
    ]);

    $this->artisan('marketing:import-wishlist-csv', ['file' => $path])
        ->assertExitCode(0);

    $metrics = app(MarketingProfileAnalyticsService::class)->metricsForProfile($profile->fresh());

    expect($metrics['wishlist_active_count'] ?? 0)->toBe(2)
        ->and($metrics['wishlist_product_handles'] ?? [])->toContain('recent-flight', 'archive-flight')
        ->and($metrics['wishlist_product_ids'] ?? [])->toContain('9201', '9202')
        ->and($metrics['wishlist_recent_additions_30d'] ?? 0)->toBe(1);

    $segment = MarketingSegment::query()->create([
        'name' => 'CSV wishlist metrics segment',
        'status' => 'active',
        'rules_json' => [
            'logic' => 'and',
            'conditions' => [
                ['field' => 'wishlist_active_count', 'operator' => 'gte', 'value' => 2],
                ['field' => 'wishlist_product_id', 'operator' => 'contains', 'value' => '9201'],
            ],
            'groups' => [],
        ],
    ]);

    $evaluation = app(MarketingSegmentEvaluator::class)->evaluateProfile($segment, $profile->fresh());

    expect($evaluation['matched'])->toBeTrue()
        ->and($evaluation['metrics']['wishlist_active_count'] ?? 0)->toBe(2)
        ->and($evaluation['metrics']['wishlist_product_handles'] ?? [])->toContain('recent-flight')
        ->and($evaluation['metrics']['wishlist_product_ids'] ?? [])->toContain('9201');
});

test('wishlist csv imported rows are returned by storefront wishlist status payload', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'wishlist-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'wishlist-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    configureCsvWishlistStorefrontStores();

    $profile = MarketingProfile::query()->create([
        'email' => 'wishlist.storefront@example.com',
        'normalized_email' => 'wishlist.storefront@example.com',
    ]);

    $path = writeWishlistCsv([
        [
            'import_status' => 'ready',
            'customer_email' => $profile->email,
            'store_key' => 'retail',
            'product_id' => '9301',
            'product_handle' => 'storefront-imported-item',
            'product_title' => 'Storefront Imported Item',
        ],
    ]);

    $this->artisan('marketing:import-wishlist-csv', ['file' => $path])
        ->assertExitCode(0);

    $query = csvWishlistSignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'email' => $profile->email,
        'product_id' => '9301',
        'product_handle' => 'storefront-imported-item',
    ], 'wishlist-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.wishlist.status', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.profile_id', $profile->id)
        ->assertJsonPath('data.product.in_wishlist', true)
        ->assertJsonPath('data.summary.active_count', 1)
        ->assertJsonPath('data.items.0.product_id', '9301')
        ->assertJsonPath('data.items.0.product_handle', 'storefront-imported-item');
});

/**
 * @param array<int,array<string,mixed>> $rows
 */
function writeWishlistCsv(array $rows): string
{
    $headers = [
        'import_status',
        'customer_email',
        'store_key',
        'product_id',
        'product_variant_id',
        'product_handle',
        'product_title',
        'product_url',
        'provider',
        'integration',
        'source',
        'source_surface',
        'source_ref',
        'raw_payload_json',
        'added_at',
        'source_synced_at',
    ];

    $directory = storage_path('framework/testing');
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $path = $directory . '/wishlist-import-' . Str::uuid() . '.csv';
    $handle = fopen($path, 'wb');

    if ($handle === false) {
        throw new RuntimeException('Unable to create test wishlist CSV.');
    }

    try {
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = $row[$header] ?? '';
            }

            fputcsv($handle, $line);
        }
    } finally {
        fclose($handle);
    }

    return $path;
}

function csvWishlistSignedQuery(array $params, string $secret): array
{
    $params = array_filter($params, static fn ($value) => $value !== null);
    ksort($params);

    $pairs = [];
    foreach ($params as $key => $value) {
        $pairs[] = $key . '=' . $value;
    }

    $params['signature'] = hash_hmac('sha256', implode('', $pairs), $secret);

    return $params;
}

function configureCsvWishlistStorefrontStores(): void
{
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'retail-client');
    config()->set('services.shopify.stores.wholesale.shop', 'cedar-wholesale.example.myshopify.com');
    config()->set('services.shopify.stores.wholesale.client_id', 'wholesale-client');

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'shop_domain' => 'timberline.example.myshopify.com',
            'access_token' => 'retail-token',
        ]
    );

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'wholesale'],
        [
            'shop_domain' => 'cedar-wholesale.example.myshopify.com',
            'access_token' => 'wholesale-token',
        ]
    );
}
