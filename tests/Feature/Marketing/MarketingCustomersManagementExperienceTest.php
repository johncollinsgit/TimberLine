<?php

use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
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

test('customers index includes canonical rows even when no growave enrichment exists', function () {
    $shopifyOnly = MarketingProfile::query()->create([
        'first_name' => 'Shopify',
        'last_name' => 'Only',
        'email' => 'shopify.only@example.com',
        'normalized_email' => 'shopify.only@example.com',
        'source_channels' => ['shopify'],
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $shopifyOnly->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'shopify:1001',
        'source_meta' => [],
        'match_method' => 'seed',
        'confidence' => 1.00,
    ]);

    $growaveEnriched = MarketingProfile::query()->create([
        'first_name' => 'Growave',
        'last_name' => 'Enriched',
        'email' => 'growave.enriched@example.com',
        'normalized_email' => 'growave.enriched@example.com',
        'source_channels' => ['shopify'],
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $growaveEnriched->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'grow-2002',
        'points_balance' => 220,
        'vip_tier' => 'Gold',
        'synced_at' => now(),
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers'))
        ->assertOk()
        ->assertSeeText('Shopify Only')
        ->assertSeeText('Growave Enriched')
        ->assertSeeText('Gold');
});

test('customer row exposes detail route and detail renders canonical and external sections', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Detail',
        'last_name' => 'Tester',
        'email' => 'detail.tester@example.com',
        'normalized_email' => 'detail.tester@example.com',
        'phone' => '(555) 818-9999',
        'normalized_phone' => '5558189999',
        'source_channels' => ['shopify', 'manual'],
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'detail-123',
        'points_balance' => 80,
        'vip_tier' => 'Silver',
        'referral_link' => 'https://example.test/ref/detail',
        'synced_at' => now(),
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers'))
        ->assertOk()
        ->assertSee(route('marketing.customers.show', $profile), false);

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', $profile))
        ->assertOk()
        ->assertSeeText('Detail Tester')
        ->assertSeeText('Edit Customer Profile')
        ->assertSeeText('External Enrichment (Read-Only)')
        ->assertSeeText('Growave Points')
        ->assertSeeText('Silver');
});

test('customer detail edit updates canonical fields and preserves normalized identity', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Before',
        'last_name' => 'Name',
        'email' => 'before@example.com',
        'normalized_email' => 'before@example.com',
        'phone' => '555-123-1234',
        'normalized_phone' => '5551231234',
        'notes' => 'Old notes',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($admin)
        ->patch(route('marketing.customers.update', $profile), [
            'first_name' => 'After',
            'last_name' => 'Update',
            'email' => 'After.Update@example.com',
            'phone' => '(555) 444-9900',
            'notes' => 'Canonical notes updated',
        ]);

    $response->assertRedirect(route('marketing.customers.show', $profile));

    $fresh = $profile->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh->first_name)->toBe('After')
        ->and($fresh->last_name)->toBe('Update')
        ->and($fresh->email)->toBe('After.Update@example.com')
        ->and($fresh->normalized_email)->toBe('after.update@example.com')
        ->and($fresh->normalized_phone)->toBe('+15554449900')
        ->and($fresh->notes)->toBe('Canonical notes updated');
});

test('customer detail update keeps growave enrichment read-only', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Readonly',
        'last_name' => 'Check',
        'email' => 'readonly@example.com',
        'normalized_email' => 'readonly@example.com',
    ]);

    $external = CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'readonly-444',
        'points_balance' => 500,
        'vip_tier' => 'Platinum',
        'synced_at' => now(),
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->patch(route('marketing.customers.update', $profile), [
            'first_name' => 'Readonly',
            'last_name' => 'Updated',
            'email' => 'readonly.updated@example.com',
            'phone' => '555-898-1212',
            'notes' => 'Only canonical profile should change',
            'points_balance' => 999999,
            'vip_tier' => 'Diamond',
        ])
        ->assertRedirect(route('marketing.customers.show', $profile));

    $freshExternal = $external->fresh();

    expect($freshExternal)->not->toBeNull()
        ->and((int) $freshExternal->points_balance)->toBe(500)
        ->and($freshExternal->vip_tier)->toBe('Platinum');
});
