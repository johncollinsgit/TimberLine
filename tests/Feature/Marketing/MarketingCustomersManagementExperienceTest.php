<?php

use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingReviewSummary;
use App\Models\Order;
use App\Models\SquareCustomer;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

test('customers index renders canonical customer rows with loyalty enrichment and add action', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Avery',
        'last_name' => 'Lane',
        'email' => 'avery.lane@example.com',
        'normalized_email' => 'avery.lane@example.com',
        'phone' => '(555) 400-9191',
        'normalized_phone' => '5554009191',
        'source_channels' => ['shopify', 'online'],
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:9011',
        'source_meta' => [],
        'match_method' => 'seed',
        'confidence' => 1.00,
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '9011',
        'points_balance' => 135,
        'vip_tier' => 'Platinum',
        'referral_link' => 'https://example.test/ref/avery',
        'synced_at' => now(),
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers'))
        ->assertOk()
        ->assertSeeText('Customers')
        ->assertSeeText('Manage Customers')
        ->assertSeeText('Add Customer')
        ->assertSeeText('Avery Lane')
        ->assertSeeText('Platinum')
        ->assertSeeText('135');
});

test('customers projections prefer data-rich Growave rows over newer empty duplicates', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Projection',
        'last_name' => 'Target',
        'email' => 'projection.target@example.com',
        'normalized_email' => 'projection.target@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'GRO-RICH-7001',
        'points_balance' => 4321,
        'vip_tier' => 'Gold',
        'referral_link' => 'https://refrr.app/example/preferred',
        'raw_metafields' => [
            ['namespace' => 'growave', 'key' => 'review_count', 'value' => '7', 'type' => 'number_integer'],
            ['namespace' => 'growave', 'key' => 'published_review_count', 'value' => '7', 'type' => 'number_integer'],
            ['namespace' => 'growave', 'key' => 'activity_total', 'value' => '11', 'type' => 'number_integer'],
        ],
        'synced_at' => now()->subHour(),
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'GRO-EMPTY-7002',
        'points_balance' => 0,
        'vip_tier' => null,
        'referral_link' => null,
        'raw_metafields' => [
            ['namespace' => 'growave', 'key' => 'review_count', 'value' => '0', 'type' => 'number_integer'],
            ['namespace' => 'growave', 'key' => 'published_review_count', 'value' => '0', 'type' => 'number_integer'],
            ['namespace' => 'growave', 'key' => 'activity_total', 'value' => '0', 'type' => 'number_integer'],
        ],
        'synced_at' => now(),
    ]);

    MarketingReviewSummary::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'GRO-RICH-7001',
        'external_customer_email' => $profile->email,
        'review_count' => 7,
        'published_review_count' => 7,
        'average_rating' => 4.75,
        'source_synced_at' => now()->subHour(),
        'raw_payload' => [],
    ]);

    MarketingReviewSummary::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'GRO-EMPTY-7002',
        'external_customer_email' => $profile->email,
        'review_count' => 0,
        'published_review_count' => 0,
        'average_rating' => null,
        'source_synced_at' => now(),
        'raw_payload' => [],
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers'))
        ->assertOk()
        ->assertSeeText('Projection Target')
        ->assertSeeText('4,321')
        ->assertSeeText('7')
        ->assertSeeText('4.75 avg');

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', $profile))
        ->assertOk()
        ->assertSeeText('4,321')
        ->assertSeeText('7')
        ->assertSeeText('4.75')
        ->assertSeeText('Open Referral Link');
});

test('customers search matches external source ids through canonical profile query', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Search',
        'last_name' => 'Target',
        'email' => 'search.target@example.com',
        'normalized_email' => 'search.target@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'EXT-LOOKUP-7788',
        'points_balance' => 25,
        'synced_at' => now(),
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers', ['search' => 'LOOKUP-7788']))
        ->assertOk()
        ->assertSeeText('Search Target')
        ->assertSeeText('search.target@example.com');
});

test('customers index includes shopify and square-only canonical profiles without requiring growave', function () {
    Order::factory()->create([
        'source' => 'shopify_retail',
        'order_type' => 'retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 7101,
        'shopify_customer_id' => '8101',
        'first_name' => 'Shopify',
        'last_name' => 'Only',
        'email' => 'shopify.only.index@example.com',
        'phone' => '+1 (555) 440-0101',
    ]);

    SquareCustomer::query()->create([
        'square_customer_id' => 'SQ-CUST-IDX-1',
        'given_name' => 'Square',
        'family_name' => 'Only',
        'email' => 'square.only.index@example.com',
        'phone' => '+1 (555) 440-0202',
        'synced_at' => now(),
    ]);

    $this->artisan('marketing:sync-profiles --source=shopify')->assertExitCode(0);
    $this->artisan('marketing:sync-profiles --source=square')->assertExitCode(0);

    $shopifyProfile = MarketingProfile::query()->where('normalized_email', 'shopify.only.index@example.com')->first();
    $squareProfile = MarketingProfile::query()->where('normalized_email', 'square.only.index@example.com')->first();

    expect($shopifyProfile)->not->toBeNull()
        ->and($squareProfile)->not->toBeNull();

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers'))
        ->assertOk()
        ->assertSeeText('Shopify Only')
        ->assertSeeText('shopify.only.index@example.com')
        ->assertSeeText('Square Only')
        ->assertSeeText('square.only.index@example.com');
});

test('customers index row links to detail page and detail renders canonical identity fields', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Riley',
        'last_name' => 'Carter',
        'email' => 'riley.carter@example.com',
        'normalized_email' => 'riley.carter@example.com',
        'phone' => '555-321-0000',
        'normalized_phone' => '5553210000',
        'notes' => 'Existing internal notes',
        'source_channels' => ['manual'],
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'manual_customer',
        'source_id' => 'manual_profile:'.$profile->id,
        'source_meta' => [],
        'match_method' => 'seed',
        'confidence' => 1.00,
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $showUrl = route('marketing.customers.show', $profile);

    $this->actingAs($admin)
        ->get(route('marketing.customers'))
        ->assertOk()
        ->assertSee($showUrl, false)
        ->assertSeeText('Riley Carter');

    $this->actingAs($admin)
        ->get($showUrl)
        ->assertOk()
        ->assertSeeText('Identity + Address Update')
        ->assertSeeText('External Enrichment (Read-Only)')
        ->assertSeeText('riley.carter@example.com')
        ->assertSeeText('555-321-0000')
        ->assertSee('name="first_name"', false)
        ->assertSee('name="last_name"', false)
        ->assertSee('name="email"', false)
        ->assertSee('name="phone"', false);
});

test('customer detail update saves canonical fields and keeps growave enrichment read-only', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Melissa',
        'last_name' => 'Orr',
        'email' => 'melissa.orr@example.com',
        'normalized_email' => 'melissa.orr@example.com',
        'phone' => '555-100-2000',
        'normalized_phone' => '5551002000',
        'notes' => 'Before update',
        'source_channels' => ['shopify'],
    ]);

    $external = CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'GRO-2001',
        'points_balance' => 240,
        'vip_tier' => 'Gold',
        'referral_link' => 'https://example.test/ref/melissa',
        'synced_at' => now(),
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->patch(route('marketing.customers.update', $profile), [
            'first_name' => 'Mel',
            'last_name' => 'Orr-Updated',
            'email' => 'mel.updated@example.com',
            'phone' => '(555) 777-8888',
            'notes' => 'Updated internal profile note',
            'points_balance' => 9999,
            'vip_tier' => 'Diamond',
            'referral_link' => 'https://malicious.example/ref',
        ])
        ->assertRedirect(route('marketing.customers.show', $profile));

    $profile->refresh();
    $external->refresh();

    expect($profile->first_name)->toBe('Mel')
        ->and($profile->last_name)->toBe('Orr-Updated')
        ->and($profile->normalized_email)->toBe('mel.updated@example.com')
        ->and($profile->normalized_phone)->toBe('5557778888')
        ->and($profile->notes)->toBe('Updated internal profile note')
        ->and((int) $external->points_balance)->toBe(240)
        ->and((string) $external->vip_tier)->toBe('Gold')
        ->and((string) $external->referral_link)->toBe('https://example.test/ref/melissa');
});

test('add customer wizard creates canonical customer and manual source link', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 1,
            'direction' => 'next',
            'first_name' => 'Morgan',
            'last_name' => 'Reed',
            'email' => 'morgan.reed@example.com',
            'phone' => '555-555-1212',
        ])->assertRedirect(route('marketing.customers.create', ['step' => 2]));

    $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 2,
            'direction' => 'next',
            'customer_context' => 'wholesale',
        ])->assertRedirect(route('marketing.customers.create', ['step' => 3]));

    $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 3,
            'direction' => 'next',
            'decision' => 'continue',
        ])->assertRedirect(route('marketing.customers.create', ['step' => 4]));

    $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 4,
            'direction' => 'next',
            'notes' => 'High-priority wholesale buyer',
            'company_store_name' => 'North Pine Mercantile',
            'tags' => 'wholesale,vip',
            'accepts_email_marketing' => '1',
            'accepts_sms_marketing' => '1',
        ])->assertRedirect(route('marketing.customers.create', ['step' => 5]));

    $response = $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 5,
            'direction' => 'next',
            'confirm_create' => '1',
        ]);

    $profile = MarketingProfile::query()->where('normalized_email', 'morgan.reed@example.com')->first();

    expect($profile)->not->toBeNull()
        ->and((bool) $profile->accepts_email_marketing)->toBeTrue()
        ->and((bool) $profile->accepts_sms_marketing)->toBeTrue()
        ->and((array) $profile->source_channels)->toContain('manual', 'wholesale')
        ->and((string) ($profile->notes ?? ''))->toContain('North Pine Mercantile')
        ->and(MarketingProfileLink::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'manual_customer')
            ->exists())->toBeTrue();

    $response->assertRedirect(route('marketing.customers.show', $profile));
});

test('add customer wizard can select an existing canonical profile instead of creating duplicate', function () {
    $existing = MarketingProfile::query()->create([
        'first_name' => 'Denise',
        'last_name' => 'Wohlford',
        'email' => 'denise@example.com',
        'normalized_email' => 'denise@example.com',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 1,
            'direction' => 'next',
            'first_name' => 'Denise',
            'last_name' => 'Wohlford',
            'email' => 'DENISE@example.com',
            'phone' => '555-111-2222',
        ])->assertRedirect(route('marketing.customers.create', ['step' => 2]));

    $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 2,
            'direction' => 'next',
            'customer_context' => 'retail',
        ])->assertRedirect(route('marketing.customers.create', ['step' => 3]));

    $this->actingAs($admin)
        ->get(route('marketing.customers.create', ['step' => 3]))
        ->assertOk()
        ->assertSeeText('Denise Wohlford');

    $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 3,
            'direction' => 'next',
            'decision' => 'use_existing',
            'selected_profile_id' => $existing->id,
        ])->assertRedirect(route('marketing.customers.create', ['step' => 4]));

    $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 4,
            'direction' => 'next',
            'notes' => 'Merged via wizard',
        ])->assertRedirect(route('marketing.customers.create', ['step' => 5]));

    $response = $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 5,
            'direction' => 'next',
            'confirm_create' => '1',
        ]);

    expect(MarketingProfile::query()->count())->toBe(1)
        ->and((array) $existing->fresh()->source_channels)->toContain('manual', 'retail')
        ->and(MarketingProfileLink::query()
            ->where('marketing_profile_id', $existing->id)
            ->where('source_type', 'manual_customer')
            ->exists())->toBeTrue();

    $response->assertRedirect(route('marketing.customers.show', $existing));
});
