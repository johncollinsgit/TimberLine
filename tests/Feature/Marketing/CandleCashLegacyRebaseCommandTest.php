<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use Illuminate\Support\Facades\Artisan;

test('legacy candle cash correction dry run previews corrected legacy totals without mutating balances', function () {
    $alpha = MarketingProfile::query()->create([
        'email' => 'alpha@example.com',
        'normalized_email' => 'alpha@example.com',
    ]);

    $beta = MarketingProfile::query()->create([
        'email' => 'beta@example.com',
        'normalized_email' => 'beta@example.com',
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $alpha->id,
        'balance' => 100,
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $beta->id,
        'balance' => 50,
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $alpha->id,
        'type' => 'import_opening_balance',
        'points' => 100,
        'source' => 'growave',
        'source_id' => 'snapshot:alpha',
        'description' => 'Imported legacy balance',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $beta->id,
        'type' => 'earn',
        'points' => 20,
        'source' => 'growave_activity',
        'source_id' => 'retail:beta:7001',
        'description' => 'Imported Growave activity',
    ]);

    $this->artisan('marketing:rebase-candle-cash-balances', [
        '--factor' => '0.3',
        '--dry-run' => true,
    ])
        ->expectsOutput('profiles=2')
        ->expectsOutput('legacy_transactions=2')
        ->expectsOutput('legacy_rebases=0')
        ->expectsOutput('legacy_points_total=120')
        ->expectsOutput('corrected_candle_cash_total=36')
        ->assertExitCode(0);

    expect((float) CandleCashBalance::query()->where('marketing_profile_id', $alpha->id)->value('balance'))->toBe(100.0)
        ->and((float) CandleCashBalance::query()->where('marketing_profile_id', $beta->id)->value('balance'))->toBe(50.0);
});

test('legacy candle cash correction applies once per run key and leaves modern balances untouched', function () {
    $legacyProfile = MarketingProfile::query()->create([
        'email' => 'legacy@example.com',
        'normalized_email' => 'legacy@example.com',
    ]);

    $modernProfile = MarketingProfile::query()->create([
        'email' => 'modern@example.com',
        'normalized_email' => 'modern@example.com',
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $legacyProfile->id,
        'balance' => 300,
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $modernProfile->id,
        'balance' => 25,
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $legacyProfile->id,
        'type' => 'import_opening_balance',
        'points' => 100,
        'source' => 'growave',
        'source_id' => 'legacy-import',
        'description' => 'Imported legacy balance',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $legacyProfile->id,
        'type' => 'adjustment',
        'points' => -200,
        'source' => 'legacy_rebase',
        'source_id' => 'old-rebase',
        'description' => 'Old proportional haircut',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $modernProfile->id,
        'type' => 'earn',
        'points' => 25,
        'source' => 'admin',
        'source_id' => 'modern-seed',
        'description' => 'Modern canonical Candle Cash',
    ]);

    $exit = Artisan::call('marketing:rebase-candle-cash-balances', [
        '--factor' => '0.3',
        '--run-key' => 'legacy-correction-test',
    ]);

    expect($exit)->toBe(0)
        ->and((float) CandleCashBalance::query()->where('marketing_profile_id', $legacyProfile->id)->value('balance'))->toBe(30.0)
        ->and((float) CandleCashBalance::query()->where('marketing_profile_id', $modernProfile->id)->value('balance'))->toBe(25.0);

    $legacyTransaction = CandleCashTransaction::query()
        ->where('marketing_profile_id', $legacyProfile->id)
        ->where('source', 'growave')
        ->where('type', 'import_opening_balance')
        ->sole();

    $rebaseTransaction = CandleCashTransaction::query()
        ->where('marketing_profile_id', $legacyProfile->id)
        ->where('source', 'legacy_rebase')
        ->sole();

    expect((float) $legacyTransaction->candle_cash_delta)->toBe(30.0)
        ->and((bool) $legacyTransaction->legacy_points_origin)->toBeTrue()
        ->and((int) $legacyTransaction->legacy_points_value)->toBe(100)
        ->and((float) $rebaseTransaction->candle_cash_delta)->toBe(0.0);

    expect(MarketingImportRun::query()
        ->where('type', 'candle_cash_legacy_points_correction')
        ->where('source_label', 'legacy-correction-test')
        ->where('status', 'completed')
        ->exists())->toBeTrue();

    $exitAgain = Artisan::call('marketing:rebase-candle-cash-balances', [
        '--factor' => '0.3',
        '--run-key' => 'legacy-correction-test',
    ]);

    expect($exitAgain)->toBe(0)
        ->and((float) CandleCashBalance::query()->where('marketing_profile_id', $legacyProfile->id)->value('balance'))->toBe(30.0)
        ->and(CandleCashTransaction::query()
            ->where('marketing_profile_id', $legacyProfile->id)
            ->where('source', 'legacy_rebase')
            ->count())->toBe(1);
});
