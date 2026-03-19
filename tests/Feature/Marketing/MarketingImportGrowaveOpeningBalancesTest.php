<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;

test('imports latest growave snapshot as candle cash opening balance entry', function () {
    $profile = MarketingProfile::query()->create([
        'email' => 'growave.import@example.com',
        'normalized_email' => 'growave.import@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'grow-1001',
        'points_balance' => 125,
        'synced_at' => now(),
    ]);

    $this->artisan('marketing:import-growave-opening-balances --limit=10')
        ->assertExitCode(0);

    $balance = CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->first();
    $transaction = CandleCashTransaction::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('type', 'import_opening_balance')
        ->where('source', 'growave')
        ->first();

    expect($balance)->not->toBeNull()
        ->and((int) $balance->balance)->toBe(125)
        ->and($transaction)->not->toBeNull()
        ->and((int) $transaction->candle_cash_delta)->toBe(125)
        ->and((string) $transaction->source_id)->toBe((string) CustomerExternalProfile::query()->sole()->id);
});

test('skips growave opening import when profile already has candle cash transactions', function () {
    $profile = MarketingProfile::query()->create([
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
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'grow-2002',
        'points_balance' => 200,
        'synced_at' => now(),
    ]);

    $this->artisan('marketing:import-growave-opening-balances --limit=10')
        ->assertExitCode(0);

    expect((int) CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->value('balance'))->toBe(50)
        ->and(CandleCashTransaction::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('type', 'import_opening_balance')
            ->exists())->toBeFalse();
});

test('uses latest growave snapshot per profile and remains idempotent', function () {
    $profile = MarketingProfile::query()->create([
        'email' => 'growave.latest@example.com',
        'normalized_email' => 'growave.latest@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'grow-3003',
        'points_balance' => 100,
        'synced_at' => now()->subDay(),
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'wholesale',
        'external_customer_id' => 'grow-3003-wholesale',
        'points_balance' => 170,
        'synced_at' => now(),
    ]);

    $this->artisan('marketing:import-growave-opening-balances --limit=10')
        ->assertExitCode(0);

    $this->artisan('marketing:import-growave-opening-balances --limit=10')
        ->assertExitCode(0);

    expect((int) CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->value('balance'))->toBe(170)
        ->and(CandleCashTransaction::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('type', 'import_opening_balance')
            ->where('source', 'growave')
            ->count())->toBe(1);
});
