<?php

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Marketing\CustomerIdentityAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('audit keeps the ledger-backed profile and inventories donor-only Shopify birthday and address data', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $rewardsProfile = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Faith',
        'last_name' => 'Crocker',
        'email' => 'faith@example.com',
        'normalized_email' => 'faith@example.com',
    ]);
    $loginProfile = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Faith',
        'last_name' => 'Crocker',
        'email' => 'faith@example.com',
        'normalized_email' => 'faith@example.com',
        'phone' => '(555) 111-2222',
        'normalized_phone' => '5551112222',
        'address_line_1' => '10 Forest Lane',
        'city' => 'Savannah',
        'state' => 'GA',
    ]);

    DB::table('candle_cash_transactions')->insert([
        'marketing_profile_id' => $rewardsProfile->id,
        'type' => 'earn',
        'points' => 42,
        'candle_cash_delta' => 42,
        'source' => 'growave_activity',
        'source_id' => 'faith:legacy:1',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('candle_cash_balances')->insert([
        'marketing_profile_id' => $rewardsProfile->id,
        'balance' => 42,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('customer_external_profiles')->insert([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $loginProfile->id,
        'provider' => 'shopify',
        'integration' => 'shopify_customer',
        'store_key' => 'retail',
        'external_customer_id' => '123',
        'external_customer_gid' => 'gid://shopify/Customer/123',
        'email' => 'faith@example.com',
        'normalized_email' => 'faith@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('marketing_profile_links')->insert([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $rewardsProfile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:123',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('customer_birthday_profiles')->insert([
        'marketing_profile_id' => $loginProfile->id,
        'birth_month' => 4,
        'birth_day' => 12,
        'source' => 'shopify',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = app(CustomerIdentityAuditService::class)->audit($tenant->id, $tenant->slug, 'retail');
    $cluster = $payload['results'][0];

    expect($payload['clusters'])->toBe(1)
        ->and($payload['deterministic_clusters'])->toBe(1)
        ->and($cluster['status'])->toBe('deterministic')
        ->and($cluster['recommended_survivor_profile_id'])->toBe($rewardsProfile->id)
        ->and($cluster['shopify_merge_required'])->toBeFalse()
        ->and($cluster['field_sources']['phone'])->toBe($loginProfile->id)
        ->and($cluster['field_sources']['address_line_1'])->toBe($loginProfile->id)
        ->and($cluster['donor_only_data'])->toContain('birthday:profile:'.$loginProfile->id)
        ->and(collect($cluster['profiles'])->firstWhere('id', $loginProfile->id)['birthdays'][0]['birth_day'])->toBe(12);
});

test('audit fails closed when two duplicate profiles contain conflicting birthdays or multiple reward ledgers', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $profiles = collect([4, 5])->map(function (int $month) use ($tenant): MarketingProfile {
        $profile = MarketingProfile::factory()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Faith',
            'last_name' => 'Crocker',
            'email' => 'faith@example.com',
            'normalized_email' => 'faith@example.com',
        ]);
        DB::table('candle_cash_transactions')->insert([
            'marketing_profile_id' => $profile->id,
            'type' => 'earn',
            'points' => 10,
            'candle_cash_delta' => 10,
            'source' => 'test',
            'source_id' => 'faith:'.$month,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('candle_cash_balances')->insert([
            'marketing_profile_id' => $profile->id,
            'balance' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('customer_birthday_profiles')->insert([
            'marketing_profile_id' => $profile->id,
            'birth_month' => $month,
            'birth_day' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $profile;
    });

    $cluster = app(CustomerIdentityAuditService::class)->audit($tenant->id, $tenant->slug, 'retail')['results'][0];

    expect($cluster['status'])->toBe('needs_review')
        ->and($cluster['recommended_survivor_profile_id'])->toBeNull()
        ->and($cluster['review_reasons'])->toContain('multiple_profiles_own_candle_cash_ledger_entries')
        ->and($cluster['review_reasons'])->toContain('conflicting_birthday_values')
        ->and($profiles)->toHaveCount(2);
});

test('audit bulk loads related facts instead of issuing queries per duplicate profile', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    MarketingProfile::factory()->count(60)->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Shared',
        'last_name' => 'Customer',
        'email' => 'shared@example.com',
        'normalized_email' => 'shared@example.com',
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $payload = app(CustomerIdentityAuditService::class)->audit($tenant->id, $tenant->slug, 'retail');
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($payload['clusters'])->toBe(1)
        ->and($payload['results'][0]['profile_ids'])->toHaveCount(60)
        ->and($queryCount)->toBeLessThan(400);
});

test('tenant-wide audit excludes ambiguous name-only clusters while focused lookup can inspect them', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    MarketingProfile::factory()->count(2)->sequence(
        ['first_name' => 'Alex', 'last_name' => 'Smith', 'email' => 'alex.one@example.com', 'normalized_email' => 'alex.one@example.com'],
        ['first_name' => 'Alex', 'last_name' => 'Smith', 'email' => 'alex.two@example.com', 'normalized_email' => 'alex.two@example.com'],
    )->create(['tenant_id' => $tenant->id]);

    $broad = app(CustomerIdentityAuditService::class)->audit($tenant->id, $tenant->slug, 'retail');
    $focused = app(CustomerIdentityAuditService::class)->audit($tenant->id, $tenant->slug, 'retail', 'Alex Smith');

    expect($broad['clusters'])->toBe(0)
        ->and($focused['clusters'])->toBe(1)
        ->and($focused['results'][0]['identities'])->toContain('name:alex smith')
        ->and($focused['results'][0]['status'])->toBe('needs_review');
});

test('tenant-wide audit omits unrelated source row payloads while focused lookup retains them', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $profiles = MarketingProfile::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Taylor',
        'last_name' => 'Pine',
        'email' => 'taylor@example.com',
        'normalized_email' => 'taylor@example.com',
    ]);
    DB::table('marketing_profile_links')->insert([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profiles->first()->id,
        'source_type' => 'yotpo_contact',
        'source_id' => 'yotpo-email:taylor@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $broad = app(CustomerIdentityAuditService::class)->audit($tenant->id, $tenant->slug, 'retail');
    $focused = app(CustomerIdentityAuditService::class)->audit($tenant->id, $tenant->slug, 'retail', 'taylor@example.com');
    $broadProfile = collect($broad['results'][0]['profiles'])->firstWhere('id', $profiles->first()->id);
    $focusedProfile = collect($focused['results'][0]['profiles'])->firstWhere('id', $profiles->first()->id);

    expect($broadProfile['source_links'])->toBe([])
        ->and($broadProfile['owned_record_counts']['marketing_profile_links.marketing_profile_id'])->toBe(1)
        ->and($focusedProfile['source_links'][0]['source_type'])->toBe('yotpo_contact');
});
