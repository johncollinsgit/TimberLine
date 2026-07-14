<?php

use App\Models\MarketingProfile;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('audited deterministic reconciliation keeps the Candle Cash profile and is replay safe', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $survivor = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Faith',
        'last_name' => 'Crocker',
        'email' => 'faith@example.com',
        'normalized_email' => 'faith@example.com',
        'phone' => '+1 (864) 497-3866',
        'normalized_phone' => '18644973866',
    ]);
    $donor = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Faith',
        'last_name' => 'Crocker',
        'email' => 'faith@example.com',
        'normalized_email' => 'faith@example.com',
        'phone' => '+1 (864) 497-3866',
        'normalized_phone' => '18644973866',
    ]);

    DB::table('candle_cash_transactions')->insert([
        [
            'marketing_profile_id' => $survivor->id,
            'type' => 'earn',
            'points' => 74.5,
            'candle_cash_delta' => 74.5,
            'source' => 'growave_activity',
            'source_id' => 'faith:ledger:1',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    DB::table('candle_cash_balances')->insert([
        ['marketing_profile_id' => $survivor->id, 'balance' => 74.5, 'created_at' => now(), 'updated_at' => now()],
        ['marketing_profile_id' => $donor->id, 'balance' => 0, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('customer_external_profiles')->insert([
        [
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
        ],
        [
            'tenant_id' => $tenant->id,
            'marketing_profile_id' => $donor->id,
            'provider' => 'shopify',
            'integration' => 'shopify_customer',
            'store_key' => 'retail',
            'external_customer_id' => '5346630279999',
            'external_customer_gid' => 'gid://shopify/Customer/5346630279999',
            'email' => 'faith@example.com',
            'normalized_email' => 'faith@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    DB::table('customer_birthday_profiles')->insert([
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
    ]);

    $auditFile = tempnam(sys_get_temp_dir(), 'audit');
    file_put_contents($auditFile, implode("\n", [
        json_encode([
            'record_type' => 'summary',
            'mode' => 'preview',
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'store_key' => 'retail',
            'clusters' => 1,
            'deterministic_clusters' => 1,
            'needs_review_clusters' => 0,
            'shopify_merge_clusters' => 1,
            'birthday_data_clusters' => 1,
        ], JSON_THROW_ON_ERROR),
        json_encode([
            'record_type' => 'cluster',
            'cluster' => [
                'status' => 'deterministic',
                'profile_ids' => [$survivor->id, $donor->id],
                'recommended_survivor_profile_id' => $survivor->id,
                'shopify_merge_required' => false,
                'review_reasons' => [],
                'profiles' => [
                    ['fields' => ['email' => 'faith@example.com']],
                    ['fields' => ['email' => 'faith@example.com']],
                ],
                'field_sources' => [
                    'first_name' => $survivor->id,
                    'last_name' => $survivor->id,
                    'email' => $survivor->id,
                    'phone' => $survivor->id,
                    'address_line_1' => $survivor->id,
                    'address_line_2' => $survivor->id,
                    'city' => $survivor->id,
                    'state' => $survivor->id,
                    'postal_code' => $survivor->id,
                    'country' => $survivor->id,
                    'notes' => $survivor->id,
                    'tags' => $survivor->id,
                ],
            ],
        ], JSON_THROW_ON_ERROR),
    ])."\n");

    Artisan::call('marketing:reconcile-audited-customer-identities', [
        '--tenant' => $tenant->slug,
        '--store' => 'retail',
        '--audit-file' => $auditFile,
        '--exact-email-only' => true,
        '--apply' => true,
    ]);

    expect($donor->fresh()->merged_into_profile_id)->toBe($survivor->id)
        ->and($donor->fresh()->merged_at)->not->toBeNull()
        ->and((float) DB::table('candle_cash_balances')->where('marketing_profile_id', $survivor->id)->value('balance'))->toBe(74.5)
        ->and(DB::table('candle_cash_transactions')->where('marketing_profile_id', $survivor->id)->count())->toBe(1)
        ->and(DB::table('customer_external_profiles')->where('marketing_profile_id', $survivor->id)->count())->toBe(2)
        ->and(DB::table('customer_external_profiles')->where('marketing_profile_id', $donor->id)->count())->toBe(0)
        ->and(DB::table('customer_birthday_profiles')->where('marketing_profile_id', $survivor->id)->count())->toBe(1);

    Artisan::call('marketing:reconcile-audited-customer-identities', [
        '--tenant' => $tenant->slug,
        '--store' => 'retail',
        '--audit-file' => $auditFile,
        '--exact-email-only' => true,
        '--apply' => true,
    ]);

    expect(DB::table('candle_cash_transactions')->where('marketing_profile_id', $survivor->id)->count())->toBe(1);
});
