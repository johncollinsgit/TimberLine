<?php

use App\Models\CustomerExternalProfile;
use App\Models\MarketingIdentityReview;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Services\Marketing\MarketingProfileSyncService;

test('shopify-only candidate creates profile from operational order data', function () {
    Order::factory()->create([
        'source' => 'shopify_retail',
        'order_type' => 'retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 1001,
        'shopify_customer_id' => '2001',
        'first_name' => 'Shopify',
        'last_name' => 'Only',
        'email' => 'shopify.only@example.com',
        'phone' => '+1 (555) 123-0000',
    ]);

    $this->artisan('marketing:sync-profiles --source=shopify')
        ->assertExitCode(0);

    $profile = MarketingProfile::query()->sole();

    expect($profile->normalized_email)->toBe('shopify.only@example.com')
        ->and($profile->normalized_phone)->toBe('5551230000')
        ->and(MarketingProfileLink::query()->where('source_type', 'order')->count())->toBe(1)
        ->and(MarketingProfileLink::query()->where('source_type', 'shopify_order')->count())->toBe(1)
        ->and(MarketingProfileLink::query()->where('source_type', 'shopify_customer')->count())->toBe(1);
});

test('growave-only candidate creates canonical marketing profile', function () {
    CustomerExternalProfile::query()->create([
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '3001',
        'external_customer_gid' => 'gid://shopify/Customer/3001',
        'first_name' => 'Growave',
        'last_name' => 'Only',
        'email' => 'growave.only@example.com',
        'normalized_email' => 'growave.only@example.com',
        'phone' => '(555) 222-1111',
        'normalized_phone' => '5552221111',
        'raw_metafields' => [
            ['namespace' => 'ssw', 'key' => 'loyalty_points', 'value' => '120', 'type' => 'number_integer'],
        ],
        'points_balance' => 120,
        'vip_tier' => 'Gold',
        'source_channels' => ['shopify', 'growave'],
        'synced_at' => now(),
    ]);

    $this->artisan('marketing:sync-profiles --source=growave')
        ->assertExitCode(0);

    expect(MarketingProfile::query()->count())->toBe(1)
        ->and(MarketingProfileLink::query()->where('source_type', 'growave_customer')->count())->toBe(1)
        ->and(CustomerExternalProfile::query()->whereNotNull('marketing_profile_id')->count())->toBe(1);
});

test('square-only customer candidate creates canonical marketing profile', function () {
    SquareCustomer::query()->create([
        'square_customer_id' => 'SQ-CUST-PIPE-1',
        'given_name' => 'Square',
        'family_name' => 'Only',
        'email' => 'square.only@example.com',
        'phone' => '+1 (555) 801-9000',
        'synced_at' => now(),
    ]);

    $this->artisan('marketing:sync-profiles --source=square')
        ->assertExitCode(0);

    $profile = MarketingProfile::query()->sole();

    expect($profile->normalized_email)->toBe('square.only@example.com')
        ->and($profile->normalized_phone)->toBe('5558019000')
        ->and(MarketingProfileLink::query()->where('source_type', 'square_customer')->count())->toBe(1);
});

test('square customer without direct email or phone still creates canonical profile from source link', function () {
    SquareCustomer::query()->create([
        'square_customer_id' => 'SQ-CUST-PIPE-NOID',
        'given_name' => 'Square',
        'family_name' => 'No Identity',
        'email' => null,
        'phone' => null,
        'synced_at' => now(),
    ]);

    $this->artisan('marketing:sync-profiles --source=square')
        ->assertExitCode(0);

    $profile = MarketingProfile::query()->sole();

    expect($profile->first_name)->toBe('Square')
        ->and($profile->last_name)->toBe('No Identity')
        ->and($profile->normalized_email)->toBeNull()
        ->and($profile->normalized_phone)->toBeNull()
        ->and(MarketingProfileLink::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'square_customer')
            ->where('source_id', 'SQ-CUST-PIPE-NOID')
            ->exists())->toBeTrue();
});

test('square order and payment records attach to existing square customer profile', function () {
    SquareCustomer::query()->create([
        'square_customer_id' => 'SQ-CUST-PIPE-2',
        'given_name' => 'Square',
        'family_name' => 'Buyer',
        'email' => 'square.buyer@example.com',
        'phone' => '5557002211',
        'synced_at' => now(),
    ]);
    SquareOrder::query()->create([
        'square_order_id' => 'SQ-ORDER-PIPE-2',
        'square_customer_id' => 'SQ-CUST-PIPE-2',
        'state' => 'COMPLETED',
        'closed_at' => now()->subDay(),
        'source_name' => 'In-Person',
        'synced_at' => now(),
    ]);
    SquarePayment::query()->create([
        'square_payment_id' => 'SQ-PAY-PIPE-2',
        'square_order_id' => 'SQ-ORDER-PIPE-2',
        'square_customer_id' => 'SQ-CUST-PIPE-2',
        'status' => 'COMPLETED',
        'created_at_source' => now()->subDay(),
        'synced_at' => now(),
    ]);

    $this->artisan('marketing:sync-profiles --source=square')
        ->assertExitCode(0);

    expect(MarketingProfile::query()->count())->toBe(1)
        ->and(MarketingProfileLink::query()->where('source_type', 'square_customer')->count())->toBe(1)
        ->and(MarketingProfileLink::query()->where('source_type', 'square_order')->count())->toBe(1)
        ->and(MarketingProfileLink::query()->where('source_type', 'square_payment')->count())->toBe(1);
});

test('shopify and growave with same normalized email resolve to one profile with two source links', function () {
    Order::factory()->create([
        'source' => 'shopify_retail',
        'order_type' => 'retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 1101,
        'shopify_customer_id' => '2101',
        'email' => 'dupe.person@example.com',
        'phone' => '555-303-4040',
    ]);

    CustomerExternalProfile::query()->create([
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '2101',
        'external_customer_gid' => 'gid://shopify/Customer/2101',
        'email' => 'DUPE.PERSON@example.com',
        'normalized_email' => 'dupe.person@example.com',
        'phone' => '+1 (555) 303-4040',
        'normalized_phone' => '5553034040',
        'raw_metafields' => [
            ['namespace' => 'ssw', 'key' => 'vip_tier', 'value' => 'Silver', 'type' => 'single_line_text_field'],
        ],
        'source_channels' => ['shopify', 'growave'],
        'synced_at' => now(),
    ]);

    $this->artisan('marketing:sync-profiles --source=all')
        ->assertExitCode(0);

    $profile = MarketingProfile::query()->sole();

    expect(MarketingProfileLink::query()
        ->where('source_type', 'shopify_order')
        ->where('marketing_profile_id', $profile->id)
        ->exists())->toBeTrue()
        ->and(MarketingProfileLink::query()
            ->where('source_type', 'growave_customer')
            ->where('marketing_profile_id', $profile->id)
            ->exists())->toBeTrue();
});

test('shopify email match enriches existing profile without creating duplicate', function () {
    $existing = MarketingProfile::query()->create([
        'first_name' => 'Existing',
        'email' => 'existing@example.com',
        'normalized_email' => 'existing@example.com',
        'phone' => '555-777-5555',
        'normalized_phone' => '5557775555',
    ]);

    Order::factory()->create([
        'source' => 'shopify_retail',
        'order_type' => 'retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 1201,
        'shopify_customer_id' => '2201',
        'email' => 'EXISTING@example.com',
        'phone' => '+1 (555) 777-5555',
    ]);

    $this->artisan('marketing:sync-profiles --source=shopify')
        ->assertExitCode(0);

    expect(MarketingProfile::query()->count())->toBe(1)
        ->and(MarketingProfileLink::query()
            ->where('source_type', 'shopify_order')
            ->where('marketing_profile_id', $existing->id)
            ->exists())->toBeTrue();
});

test('email and phone conflicts are held for identity review instead of auto merge', function () {
    MarketingProfile::query()->create([
        'email' => 'conflict@example.com',
        'normalized_email' => 'conflict@example.com',
    ]);
    MarketingProfile::query()->create([
        'phone' => '5554449999',
        'normalized_phone' => '5554449999',
    ]);

    CustomerExternalProfile::query()->create([
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '2301',
        'external_customer_gid' => 'gid://shopify/Customer/2301',
        'email' => 'conflict@example.com',
        'normalized_email' => 'conflict@example.com',
        'phone' => '555-444-9999',
        'normalized_phone' => '5554449999',
        'raw_metafields' => [
            ['namespace' => 'ssw', 'key' => 'loyalty_points', 'value' => '10', 'type' => 'number_integer'],
        ],
        'source_channels' => ['shopify', 'growave'],
        'synced_at' => now(),
    ]);

    $this->artisan('marketing:sync-profiles --source=growave')
        ->assertExitCode(0);

    expect(MarketingProfile::query()->count())->toBe(2)
        ->and(MarketingIdentityReview::query()
            ->where('source_type', 'growave_customer')
            ->where('source_id', 'retail:2301')
            ->exists())->toBeTrue();
});

test('square email and phone conflicts are held for identity review instead of auto merge', function () {
    MarketingProfile::query()->create([
        'email' => 'square-conflict@example.com',
        'normalized_email' => 'square-conflict@example.com',
    ]);
    MarketingProfile::query()->create([
        'phone' => '5559996666',
        'normalized_phone' => '5559996666',
    ]);

    SquareCustomer::query()->create([
        'square_customer_id' => 'SQ-CUST-CONFLICT-1',
        'given_name' => 'Square',
        'family_name' => 'Conflict',
        'email' => 'square-conflict@example.com',
        'phone' => '(555) 999-6666',
        'synced_at' => now(),
    ]);

    $this->artisan('marketing:sync-profiles --source=square')
        ->assertExitCode(0);

    expect(MarketingProfile::query()->count())->toBe(2)
        ->and(MarketingIdentityReview::query()
            ->where('source_type', 'square_customer')
            ->where('source_id', 'SQ-CUST-CONFLICT-1')
            ->exists())->toBeTrue();
});

test('rerunning sync is idempotent for profiles and links', function () {
    Order::factory()->create([
        'source' => 'shopify_retail',
        'order_type' => 'retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 1301,
        'shopify_customer_id' => '2401',
        'email' => 'rerun@example.com',
        'phone' => '+1 (555) 111-2222',
    ]);

    CustomerExternalProfile::query()->create([
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '2401',
        'external_customer_gid' => 'gid://shopify/Customer/2401',
        'email' => 'rerun@example.com',
        'normalized_email' => 'rerun@example.com',
        'phone' => '5551112222',
        'normalized_phone' => '5551112222',
        'raw_metafields' => [
            ['namespace' => 'ssw', 'key' => 'vip_tier', 'value' => 'Bronze', 'type' => 'single_line_text_field'],
        ],
        'source_channels' => ['shopify', 'growave'],
        'synced_at' => now(),
    ]);

    $this->artisan('marketing:sync-profiles --source=all')->assertExitCode(0);
    $this->artisan('marketing:sync-profiles --source=all')->assertExitCode(0);

    expect(MarketingProfile::query()->count())->toBe(1)
        ->and(MarketingProfileLink::query()->where('source_type', 'order')->count())->toBe(1)
        ->and(MarketingProfileLink::query()->where('source_type', 'shopify_order')->count())->toBe(1)
        ->and(MarketingProfileLink::query()->where('source_type', 'shopify_customer')->count())->toBe(1)
        ->and(MarketingProfileLink::query()->where('source_type', 'growave_customer')->count())->toBe(1);
});

test('rerunning square sync is idempotent for profiles and links', function () {
    SquareCustomer::query()->create([
        'square_customer_id' => 'SQ-CUST-RERUN',
        'given_name' => 'Square',
        'family_name' => 'Rerun',
        'email' => 'square-rerun@example.com',
        'phone' => '5552223000',
        'synced_at' => now(),
    ]);
    SquareOrder::query()->create([
        'square_order_id' => 'SQ-ORDER-RERUN',
        'square_customer_id' => 'SQ-CUST-RERUN',
        'state' => 'COMPLETED',
        'closed_at' => now()->subHours(5),
        'source_name' => 'In-Person',
        'synced_at' => now(),
    ]);

    $this->artisan('marketing:sync-profiles --source=square')->assertExitCode(0);
    $this->artisan('marketing:sync-profiles --source=square')->assertExitCode(0);

    expect(MarketingProfile::query()->count())->toBe(1)
        ->and(MarketingProfileLink::query()->where('source_type', 'square_customer')->count())->toBe(1)
        ->and(MarketingProfileLink::query()->where('source_type', 'square_order')->count())->toBe(1);
});

test('profile sync normalizes US phone identities to ten digits', function () {
    $order = Order::factory()->create();

    $result = app(MarketingProfileSyncService::class)->syncOrder($order, [
        'identity_context' => [
            'email' => 'normalizer@example.com',
            'phone' => '+1 (555) 987-6543',
        ],
    ]);

    expect($result['profiles_created'])->toBe(1);

    $profile = MarketingProfile::query()->sole();
    expect($profile->normalized_phone)->toBe('5559876543');
});

test('marketing sync command reports required counters', function () {
    Order::factory()->create([
        'source' => 'shopify_retail',
        'order_type' => 'retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 1401,
        'shopify_customer_id' => '2501',
        'email' => 'dryrun@example.com',
    ]);

    $this->artisan('marketing:sync-profiles --source=shopify --limit=1 --dry-run')
        ->expectsOutputToContain('candidates_scanned=1')
        ->expectsOutputToContain('matched_existing=')
        ->expectsOutputToContain('profiles_created=')
        ->expectsOutputToContain('profiles_updated=')
        ->expectsOutputToContain('links_created=')
        ->expectsOutputToContain('ambiguous_collisions=')
        ->expectsOutputToContain('skipped_no_identity=')
        ->expectsOutputToContain('square_customer_candidates=')
        ->expectsOutputToContain('square_order_candidates=')
        ->expectsOutputToContain('square_payment_candidates=')
        ->assertExitCode(0);
});
