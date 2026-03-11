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
