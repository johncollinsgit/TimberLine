<?php

use App\Models\MarketingIdentityReview;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Services\Marketing\MarketingProfileSyncService;

test('creates new profile when no exact match exists', function () {
    $order = Order::factory()->create();

    $result = app(MarketingProfileSyncService::class)->syncOrder($order, [
        'identity_context' => [
            'email' => 'Alice@example.com',
            'phone' => '(555) 123-4567',
            'first_name' => 'Alice',
            'last_name' => 'Zephyr',
            'source_channels' => ['shopify', 'online'],
        ],
    ]);

    expect($result['profiles_created'])->toBe(1)
        ->and($result['records_skipped'])->toBe(0)
        ->and(MarketingProfile::query()->count())->toBe(1);

    $profile = MarketingProfile::query()->firstOrFail();
    expect($profile->normalized_email)->toBe('alice@example.com')
        ->and($profile->normalized_phone)->toBe('+15551234567')
        ->and(MarketingProfileLink::query()->where('marketing_profile_id', $profile->id)->count())->toBe(1);
});

test('reuses profile on exact normalized email match', function () {
    $existing = MarketingProfile::query()->create([
        'first_name' => 'Casey',
        'email' => 'casey@example.com',
        'normalized_email' => 'casey@example.com',
    ]);

    $order = Order::factory()->create();

    $result = app(MarketingProfileSyncService::class)->syncOrder($order, [
        'identity_context' => [
            'email' => 'CASEY@EXAMPLE.COM',
            'phone' => null,
        ],
    ]);

    expect($result['profile_id'])->toBe($existing->id)
        ->and(MarketingProfile::query()->count())->toBe(1)
        ->and(MarketingProfileLink::query()->where('marketing_profile_id', $existing->id)->exists())->toBeTrue();
});

test('reuses profile on exact normalized phone match', function () {
    $existing = MarketingProfile::query()->create([
        'first_name' => 'Jordan',
        'phone' => '555-333-4444',
        'normalized_phone' => '+15553334444',
    ]);

    $order = Order::factory()->create();

    $result = app(MarketingProfileSyncService::class)->syncOrder($order, [
        'identity_context' => [
            'phone' => '+1 (555) 333-4444',
        ],
    ]);

    expect($result['profile_id'])->toBe($existing->id)
        ->and(MarketingProfile::query()->count())->toBe(1)
        ->and(MarketingProfileLink::query()->where('marketing_profile_id', $existing->id)->exists())->toBeTrue();
});

test('creates identity review on email phone conflict', function () {
    $emailProfile = MarketingProfile::query()->create([
        'first_name' => 'Email',
        'email' => 'conflict@example.com',
        'normalized_email' => 'conflict@example.com',
    ]);
    $phoneProfile = MarketingProfile::query()->create([
        'first_name' => 'Phone',
        'phone' => '555-777-8888',
        'normalized_phone' => '+15557778888',
    ]);

    $order = Order::factory()->create();

    $result = app(MarketingProfileSyncService::class)->syncOrder($order, [
        'identity_context' => [
            'email' => 'conflict@example.com',
            'phone' => '5557778888',
        ],
    ]);

    expect($result['reviews_created'])->toBe(1)
        ->and($result['profile_id'])->toBeNull()
        ->and(MarketingIdentityReview::query()->count())->toBe(1)
        ->and(MarketingIdentityReview::query()->firstOrFail()->conflict_reasons)->toContain('email_phone_conflict')
        ->and($emailProfile->id)->not->toBe($phoneProfile->id);
});

test('skips records with no usable email and no usable phone', function () {
    $order = Order::factory()->create();

    $result = app(MarketingProfileSyncService::class)->syncOrder($order);

    expect($result['records_skipped'])->toBe(1)
        ->and($result['reason'])->toBe('missing_email_phone')
        ->and(MarketingProfile::query()->count())->toBe(0);
});

test('creates source-linked profile for shopify orders without email or phone', function () {
    $order = Order::factory()->create([
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 501001,
        'customer_name' => 'Fallback Shopper',
    ]);

    $result = app(MarketingProfileSyncService::class)->syncOrder($order);

    expect($result['profiles_created'])->toBe(1)
        ->and($result['records_skipped'])->toBe(0)
        ->and($result['reason'])->toBe('created_from_source_without_identity')
        ->and(MarketingProfile::query()->count())->toBe(1);

    $profile = MarketingProfile::query()->firstOrFail();

    expect((array) $profile->source_channels)->toContain('shopify')
        ->and(MarketingProfileLink::query()->where('source_type', 'order')->where('source_id', (string) $order->id)->exists())->toBeTrue()
        ->and(MarketingProfileLink::query()->where('source_type', 'shopify_order')->exists())->toBeTrue();
});

test('creates source-linked profile for square customer without email or phone', function () {
    $service = app(MarketingProfileSyncService::class);

    $result = $service->syncExternalIdentity([
        'first_name' => 'Square',
        'last_name' => 'No Identity',
        'source_channels' => ['square'],
        'source_links' => [[
            'source_type' => 'square_customer',
            'source_id' => 'SQ-CUST-NOID-1',
            'source_meta' => [
                'source_system' => 'square',
                'source_record_type' => 'customer',
            ],
        ]],
        'primary_source' => [
            'source_type' => 'square_customer',
            'source_id' => 'SQ-CUST-NOID-1',
        ],
    ]);

    expect($result['profiles_created'])->toBe(1)
        ->and($result['records_skipped'])->toBe(0)
        ->and($result['reason'])->toBe('created_from_source_without_identity')
        ->and(MarketingProfile::query()->count())->toBe(1);

    $profile = MarketingProfile::query()->firstOrFail();

    expect($profile->first_name)->toBe('Square')
        ->and($profile->last_name)->toBe('No Identity')
        ->and($profile->normalized_email)->toBeNull()
        ->and($profile->normalized_phone)->toBeNull()
        ->and(MarketingProfileLink::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'square_customer')
            ->where('source_id', 'SQ-CUST-NOID-1')
            ->exists())->toBeTrue();
});

test('creates source links once and reuses links on rerun', function () {
    $order = Order::factory()->create([
        'source' => 'shopify_retail',
        'order_type' => 'retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 9001,
    ]);

    $service = app(MarketingProfileSyncService::class);

    $first = $service->syncOrder($order, [
        'identity_context' => [
            'email' => 'repeat@example.com',
            'phone' => '5551112222',
        ],
    ]);
    $second = $service->syncOrder($order, [
        'identity_context' => [
            'email' => 'repeat@example.com',
            'phone' => '5551112222',
        ],
    ]);

    expect($first['links_created'])->toBe(2)
        ->and($second['links_reused'])->toBeGreaterThanOrEqual(2)
        ->and(MarketingProfileLink::query()->where('source_type', 'order')->count())->toBe(1)
        ->and(MarketingProfileLink::query()->where('source_type', 'shopify_order')->count())->toBe(1);
});

test('marketing sync command runs and reports counts', function () {
    Order::factory()->count(2)->create();

    $this->artisan('marketing:sync-profiles --limit=1 --dry-run')
        ->expectsOutputToContain('processed=1')
        ->expectsOutputToContain('profiles_created=')
        ->expectsOutputToContain('profiles_updated=')
        ->expectsOutputToContain('links_created=')
        ->expectsOutputToContain('links_reused=')
        ->expectsOutputToContain('reviews_created=')
        ->expectsOutputToContain('records_skipped=')
        ->assertExitCode(0);
});

test('marketing sync command supports shopify-only scope', function () {
    Order::factory()->create([
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 707001,
    ]);
    Order::factory()->create([
        'source' => 'manual',
        'shopify_order_id' => null,
    ]);

    $this->artisan('marketing:sync-profiles --shopify-only --dry-run')
        ->expectsOutputToContain('scope=shopify_only')
        ->expectsOutputToContain('processed=1')
        ->assertExitCode(0);
});

test('marketing rebuild customers command delegates to sync command', function () {
    Order::factory()->create([
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 808001,
    ]);

    $this->artisan('marketing:rebuild-customers --shopify-only --dry-run')
        ->expectsOutputToContain('scope=shopify_only')
        ->expectsOutputToContain('processed=1')
        ->assertExitCode(0);
});
