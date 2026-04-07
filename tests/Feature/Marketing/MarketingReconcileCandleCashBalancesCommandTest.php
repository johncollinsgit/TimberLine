<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('reconcile command preview reports drift without mutating candle cash balances', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Preview Tenant',
        'slug' => 'preview-tenant',
    ]);

    $profileWithStaleBalance = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'preview-stale@example.com',
        'normalized_email' => 'preview-stale@example.com',
    ]);
    $profileMissingBalance = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'preview-missing@example.com',
        'normalized_email' => 'preview-missing@example.com',
    ]);
    $profileZeroLedger = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'preview-zero@example.com',
        'normalized_email' => 'preview-zero@example.com',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profileWithStaleBalance->id,
        'type' => 'earn',
        'candle_cash_delta' => 150,
        'source' => 'consent',
        'source_id' => 'preview:stale',
        'description' => 'Program earn',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profileMissingBalance->id,
        'type' => 'earn',
        'candle_cash_delta' => 20,
        'source' => 'consent',
        'source_id' => 'preview:missing',
        'description' => 'Program earn',
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $profileWithStaleBalance->id,
        'balance' => 80,
    ]);
    CandleCashBalance::query()->create([
        'marketing_profile_id' => $profileZeroLedger->id,
        'balance' => 10,
    ]);

    $this->artisan('marketing:reconcile-candle-cash-balances', [
        '--tenant-id' => $tenant->id,
    ])
        ->expectsOutput('mode=preview')
        ->expectsOutput('mismatches=3')
        ->expectsOutput('post_mismatches=3')
        ->expectsOutput('reconciled=no')
        ->assertExitCode(1);

    expect((float) CandleCashBalance::query()->where('marketing_profile_id', $profileWithStaleBalance->id)->value('balance'))
        ->toBe(80.0)
        ->and(CandleCashBalance::query()->where('marketing_profile_id', $profileMissingBalance->id)->exists())->toBeFalse()
        ->and((float) CandleCashBalance::query()->where('marketing_profile_id', $profileZeroLedger->id)->value('balance'))->toBe(10.0);
});

test('reconcile command apply repairs drift and produces reconciled scope', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Apply Tenant',
        'slug' => 'apply-tenant',
    ]);

    $profileWithStaleBalance = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'apply-stale@example.com',
        'normalized_email' => 'apply-stale@example.com',
    ]);
    $profileMissingBalance = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'apply-missing@example.com',
        'normalized_email' => 'apply-missing@example.com',
    ]);
    $profileZeroLedger = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'apply-zero@example.com',
        'normalized_email' => 'apply-zero@example.com',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profileWithStaleBalance->id,
        'type' => 'earn',
        'candle_cash_delta' => 150,
        'source' => 'consent',
        'source_id' => 'apply:stale',
        'description' => 'Program earn',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profileMissingBalance->id,
        'type' => 'earn',
        'candle_cash_delta' => 20,
        'source' => 'consent',
        'source_id' => 'apply:missing',
        'description' => 'Program earn',
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $profileWithStaleBalance->id,
        'balance' => 80,
    ]);
    CandleCashBalance::query()->create([
        'marketing_profile_id' => $profileZeroLedger->id,
        'balance' => 10,
    ]);

    $this->artisan('marketing:reconcile-candle-cash-balances', [
        '--tenant-id' => $tenant->id,
        '--apply' => true,
    ])
        ->expectsOutput('mode=apply')
        ->expectsOutput('mismatches=3')
        ->expectsOutput('post_mismatches=0')
        ->expectsOutput('reconciled=yes')
        ->assertExitCode(0);

    expect((float) CandleCashBalance::query()->where('marketing_profile_id', $profileWithStaleBalance->id)->value('balance'))
        ->toBe(150.0)
        ->and((float) CandleCashBalance::query()->where('marketing_profile_id', $profileMissingBalance->id)->value('balance'))->toBe(20.0)
        ->and((float) CandleCashBalance::query()->where('marketing_profile_id', $profileZeroLedger->id)->value('balance'))->toBe(0.0);

    $this->artisan('marketing:reconcile-candle-cash-balances', [
        '--tenant-id' => $tenant->id,
    ])
        ->expectsOutput('mode=preview')
        ->expectsOutput('mismatches=0')
        ->expectsOutput('post_mismatches=0')
        ->expectsOutput('reconciled=yes')
        ->assertExitCode(0);
});
