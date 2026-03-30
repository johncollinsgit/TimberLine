<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\ShopifyStore;
use App\Models\Tenant;

beforeEach(function () {
    $this->tenant = Tenant::query()->create([
        'name' => 'Growave Opening Balance Tenant',
        'slug' => 'growave-opening-balance-tenant',
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $this->tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'growave-opening-retail.myshopify.com',
        'access_token' => 'growave-opening-token',
        'installed_at' => now(),
    ]);
});

test('imports latest growave snapshot as candle cash opening balance entry', function () {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'growave.import@example.com',
        'normalized_email' => 'growave.import@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'grow-1001',
        'points_balance' => 125,
        'synced_at' => now(),
    ]);

    $this->artisan('marketing:import-growave-opening-balances --tenant-id=' . $this->tenant->id . ' --limit=10')
        ->assertExitCode(0);

    $balance = CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->first();
    $transaction = CandleCashTransaction::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('type', 'import_opening_balance')
        ->where('source', 'growave')
        ->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->balance)->toBe(0.375)
        ->and($transaction)->not->toBeNull()
        ->and((float) $transaction->candle_cash_delta)->toBe(0.375)
        ->and((bool) $transaction->legacy_points_origin)->toBeTrue()
        ->and((int) $transaction->legacy_points_value)->toBe(125)
        ->and((string) $transaction->source_id)->toBe((string) CustomerExternalProfile::query()->sole()->id)
        ->and(MarketingImportRun::query()
            ->where('type', 'growave_opening_balance_backfill')
            ->where('tenant_id', $this->tenant->id)
            ->count())->toBe(1);
});

test('skips growave opening import when profile already has candle cash transactions', function () {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'growave.skip@example.com',
        'normalized_email' => 'growave.skip@example.com',
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $profile->id,
        'balance' => 50,
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'points' => 50,
        'source' => 'admin',
        'source_id' => 'seed',
        'description' => 'Seed points',
    ]);

    CustomerExternalProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'grow-2002',
        'points_balance' => 200,
        'synced_at' => now(),
    ]);

    $this->artisan('marketing:import-growave-opening-balances --tenant-id=' . $this->tenant->id . ' --limit=10')
        ->assertExitCode(0);

    expect((int) CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->value('balance'))->toBe(50)
        ->and(CandleCashTransaction::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('type', 'import_opening_balance')
            ->exists())->toBeFalse();
});

test('uses latest growave snapshot per profile and remains idempotent', function () {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'growave.latest@example.com',
        'normalized_email' => 'growave.latest@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'grow-3003',
        'points_balance' => 100,
        'synced_at' => now()->subDay(),
    ]);

    CustomerExternalProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'wholesale',
        'external_customer_id' => 'grow-3003-wholesale',
        'points_balance' => 170,
        'synced_at' => now(),
    ]);

    $this->artisan('marketing:import-growave-opening-balances --tenant-id=' . $this->tenant->id . ' --limit=10')
        ->assertExitCode(0);

    $this->artisan('marketing:import-growave-opening-balances --tenant-id=' . $this->tenant->id . ' --limit=10')
        ->assertExitCode(0);

    expect((float) CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->value('balance'))->toBe(0.51)
        ->and(CandleCashTransaction::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('type', 'import_opening_balance')
            ->where('source', 'growave')
            ->count())->toBe(1);
});

test('opening balance import fails closed when tenant ownership proof is missing', function () {
    $this->artisan('marketing:import-growave-opening-balances --limit=10')
        ->expectsOutputToContain('requires --tenant-id or --store')
        ->assertExitCode(1);

    expect(MarketingImportRun::query()
        ->where('type', 'growave_opening_balance_backfill')
        ->exists())->toBeFalse();
});

test('opening balance import fails closed when store owner conflicts with explicit tenant', function () {
    $tenantB = Tenant::query()->create([
        'name' => 'Growave Opening Balance Tenant B',
        'slug' => 'growave-opening-balance-tenant-b',
    ]);

    $this->artisan('marketing:import-growave-opening-balances --store=retail --tenant-id=' . $tenantB->id . ' --limit=10')
        ->expectsOutputToContain('store owner conflicts with provided tenant context')
        ->assertExitCode(1);
});

test('opening balance import does not mutate foreign tenant profiles', function () {
    $tenantB = Tenant::query()->create([
        'name' => 'Growave Opening Balance Tenant C',
        'slug' => 'growave-opening-balance-tenant-c',
    ]);

    $tenantAProfile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'tenant-a-opening@example.com',
        'normalized_email' => 'tenant-a-opening@example.com',
    ]);
    $tenantBProfile = MarketingProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'email' => 'tenant-b-opening@example.com',
        'normalized_email' => 'tenant-b-opening@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => $tenantAProfile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'tenant-a-opening',
        'points_balance' => 120,
        'synced_at' => now(),
    ]);
    CustomerExternalProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'marketing_profile_id' => $tenantBProfile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'tenant-b-opening',
        'points_balance' => 240,
        'synced_at' => now(),
    ]);

    $this->artisan('marketing:import-growave-opening-balances --tenant-id=' . $this->tenant->id . ' --limit=10')
        ->assertExitCode(0);

    expect(CandleCashTransaction::query()
        ->where('marketing_profile_id', $tenantAProfile->id)
        ->where('type', 'import_opening_balance')
        ->where('source', 'growave')
        ->count())->toBe(1)
        ->and(CandleCashTransaction::query()
            ->where('marketing_profile_id', $tenantBProfile->id)
            ->where('type', 'import_opening_balance')
            ->where('source', 'growave')
            ->count())->toBe(0);
});
