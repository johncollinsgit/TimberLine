<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Marketing\CandleCashEarnedAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('liability replay keeps debit carry so pre-credit debits do not inflate remaining balances', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Ledger Reconcile Tenant',
        'slug' => 'ledger-reconcile-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'carry@example.com',
        'normalized_email' => 'carry@example.com',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'redeem',
        'candle_cash_delta' => -100,
        'source' => 'reward',
        'source_id' => 'redeem:carry',
        'description' => 'Redeem before historical earn replay',
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3),
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'candle_cash_delta' => 300,
        'source' => 'consent',
        'source_id' => 'earn:carry',
        'description' => 'Program earn after earlier debit',
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $profile->id,
        'balance' => 200,
    ]);

    $liability = app(CandleCashEarnedAnalyticsService::class)->balanceLiability($tenant->id);

    expect((float) data_get($liability, 'totalCurrentBalance.points'))->toBe(200.0)
        ->and((float) data_get($liability, 'programExpiring.points'))->toBe(200.0)
        ->and((float) data_get($liability, 'legacyMigrated.points'))->toBe(0.0)
        ->and((float) data_get($liability, 'manualNonExpiring.points'))->toBe(0.0)
        ->and((float) data_get($liability, 'ledgerBalance.points'))->toBe(200.0)
        ->and((float) data_get($liability, 'difference.points'))->toBe(0.0)
        ->and((bool) data_get($liability, 'reconciled'))->toBeTrue();
});

test('liability replay preserves legacy, manual, and program buckets after debit carry consumption', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Liability Buckets Tenant',
        'slug' => 'liability-buckets-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'buckets@example.com',
        'normalized_email' => 'buckets@example.com',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'redeem',
        'candle_cash_delta' => -50,
        'source' => 'reward',
        'source_id' => 'redeem:buckets',
        'description' => 'Redemption before imported credits',
        'created_at' => now()->subDays(4),
        'updated_at' => now()->subDays(4),
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'points' => 100,
        'source' => 'growave_activity',
        'source_id' => 'legacy:buckets',
        'description' => 'Imported legacy Growave activity',
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3),
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'adjustment',
        'candle_cash_delta' => 40,
        'source' => 'admin',
        'source_id' => 'opening:buckets',
        'description' => 'Opening seed credit',
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'candle_cash_delta' => 100,
        'source' => 'consent',
        'source_id' => 'earn:buckets',
        'description' => 'Program earn',
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $profile->id,
        'balance' => 120,
    ]);

    $liability = app(CandleCashEarnedAnalyticsService::class)->balanceLiability($tenant->id);

    expect((float) data_get($liability, 'totalCurrentBalance.points'))->toBe(120.0)
        ->and((float) data_get($liability, 'legacyMigrated.points'))->toBe(0.0)
        ->and((float) data_get($liability, 'manualNonExpiring.points'))->toBe(20.0)
        ->and((float) data_get($liability, 'programExpiring.points'))->toBe(100.0)
        ->and((float) data_get($liability, 'ledgerBalance.points'))->toBe(120.0)
        ->and((float) data_get($liability, 'difference.points'))->toBe(0.0)
        ->and((bool) data_get($liability, 'reconciled'))->toBeTrue();
});
