<?php

use App\Models\CustomerMergeOperation;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('reviewed reconciliation keeps the ledger profile and the more complete compatible birthday', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $donor = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Faith',
        'last_name' => 'Crocker',
        'email' => 'faith@example.com',
        'normalized_email' => 'faith@example.com',
        'phone' => '+18644973866',
        'normalized_phone' => '18644973866',
    ]);
    $survivor = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Faith',
        'last_name' => 'Crocker',
        'email' => 'faith@example.com',
        'normalized_email' => 'faith@example.com',
        'phone' => '+18644973866',
        'normalized_phone' => '18644973866',
    ]);
    DB::table('candle_cash_transactions')->insert([
        'marketing_profile_id' => $survivor->id,
        'type' => 'earn',
        'points' => 74.5,
        'candle_cash_delta' => 74.5,
        'source' => 'growave_activity',
        'source_id' => 'faith:ledger:1',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('candle_cash_balances')->insert([
        'marketing_profile_id' => $survivor->id,
        'balance' => 74.5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('customer_external_profiles')->insert([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $survivor->id,
        'provider' => 'shopify',
        'integration' => 'shopify_customer',
        'store_key' => 'retail',
        'external_customer_id' => '5346630271164',
        'external_customer_gid' => 'gid://shopify/Customer/5346630271164',
        'email' => 'faith@example.com',
        'normalized_email' => 'faith@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('marketing_profile_links')->insert([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $donor->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:5346630271164',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('customer_birthday_profiles')->insert([
        [
            'marketing_profile_id' => $donor->id,
            'birth_month' => 6,
            'birth_day' => 12,
            'birth_year' => 1997,
            'birthday_full_date' => '1997-06-12',
            'source' => 'birthday_import',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'marketing_profile_id' => $survivor->id,
            'birth_month' => 6,
            'birth_day' => 12,
            'birth_year' => null,
            'birthday_full_date' => null,
            'source' => 'shopify_rewards_surface',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $options = [
        '--tenant' => $tenant->slug,
        '--store' => 'retail',
        '--profiles' => $donor->id.','.$survivor->id,
        '--survivor' => $survivor->id,
        '--expected-email' => 'faith@example.com',
    ];
    $this->artisan('marketing:reconcile-reviewed-customer-identity', $options)->assertSuccessful();
    expect($donor->fresh()->merged_at)->toBeNull();

    $this->artisan('marketing:reconcile-reviewed-customer-identity', [...$options, '--apply' => true])->assertSuccessful();

    $birthday = DB::table('customer_birthday_profiles')->where('marketing_profile_id', $survivor->id)->sole();
    expect($donor->fresh()->merged_into_profile_id)->toBe($survivor->id)
        ->and($donor->fresh()->merged_at)->not->toBeNull()
        ->and((float) DB::table('candle_cash_balances')->where('marketing_profile_id', $survivor->id)->value('balance'))->toBe(74.5)
        ->and(DB::table('candle_cash_transactions')->where('marketing_profile_id', $survivor->id)->count())->toBe(1)
        ->and((int) $birthday->birth_year)->toBe(1997)
        ->and((string) $birthday->birthday_full_date)->toBe('1997-06-12')
        ->and(CustomerMergeOperation::query()->where('source', 'production_maintenance_reviewed_identity')->where('status', 'completed')->count())->toBe(1);

    $this->artisan('marketing:reconcile-reviewed-customer-identity', [...$options, '--apply' => true])->assertSuccessful();
    expect(DB::table('candle_cash_transactions')->where('marketing_profile_id', $survivor->id)->count())->toBe(1);
});

test('reviewed reconciliation fails closed when a profile has a QuickBooks reference', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $donor = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Faith',
        'last_name' => 'Crocker',
        'email' => 'faith@example.com',
        'normalized_email' => 'faith@example.com',
    ]);
    $survivor = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Faith',
        'last_name' => 'Crocker',
        'email' => 'faith@example.com',
        'normalized_email' => 'faith@example.com',
    ]);
    DB::table('candle_cash_transactions')->insert([
        'marketing_profile_id' => $survivor->id,
        'type' => 'earn',
        'points' => 10,
        'candle_cash_delta' => 10,
        'source' => 'test',
        'source_id' => 'faith:quickbooks-blocker',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('candle_cash_balances')->insert([
        'marketing_profile_id' => $survivor->id,
        'balance' => 10,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('marketing_profile_links')->insert([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $donor->id,
        'source_type' => 'quickbooks_customer',
        'source_id' => 'customer:123',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('marketing:reconcile-reviewed-customer-identity', [
        '--tenant' => $tenant->slug,
        '--profiles' => $donor->id.','.$survivor->id,
        '--survivor' => $survivor->id,
        '--expected-email' => 'faith@example.com',
        '--apply' => true,
    ])->assertFailed();

    expect($donor->fresh()->merged_at)->toBeNull()
        ->and(CustomerMergeOperation::query()->count())->toBe(0);
});
