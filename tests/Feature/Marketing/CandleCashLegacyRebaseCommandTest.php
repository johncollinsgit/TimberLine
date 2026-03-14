<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use Illuminate\Support\Facades\Artisan;

test('legacy candle cash rebase dry run reports aggressive option 3 totals without mutating balances', function () {
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
        'balance' => 300,
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $beta->id,
        'balance' => 151,
    ]);

    $this->artisan('marketing:rebase-candle-cash-balances', [
        '--factor' => '0.3333333333',
        '--dry-run' => true,
    ])
        ->expectsOutput('processed=2')
        ->expectsOutput('adjusted=2')
        ->expectsOutput('unchanged=0')
        ->expectsOutput('original_points=451')
        ->expectsOutput('target_points=150')
        ->expectsOutput('reduced_points=301')
        ->assertExitCode(0);

    expect((int) CandleCashBalance::query()->where('marketing_profile_id', $alpha->id)->value('balance'))->toBe(300)
        ->and((int) CandleCashBalance::query()->where('marketing_profile_id', $beta->id)->value('balance'))->toBe(151)
        ->and(CandleCashTransaction::query()->where('source', 'legacy_rebase')->exists())->toBeFalse();
});

test('legacy candle cash rebase applies option 3 haircut once per run key', function () {
    $profile = MarketingProfile::query()->create([
        'email' => 'rebalance@example.com',
        'normalized_email' => 'rebalance@example.com',
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $profile->id,
        'balance' => 300,
    ]);

    $exit = Artisan::call('marketing:rebase-candle-cash-balances', [
        '--factor' => '0.3333333333',
        '--run-key' => 'option3-aggressive-test',
    ]);

    expect($exit)->toBe(0)
        ->and((int) CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->value('balance'))->toBe(100);

    $transaction = CandleCashTransaction::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('source', 'legacy_rebase')
        ->where('source_id', 'option3-aggressive-test:' . $profile->id)
        ->sole();

    expect((int) $transaction->points)->toBe(-200);

    expect(MarketingImportRun::query()
        ->where('type', 'candle_cash_balance_rebase')
        ->where('source_label', 'option3-aggressive-test')
        ->where('status', 'completed')
        ->exists())->toBeTrue();

    $exitAgain = Artisan::call('marketing:rebase-candle-cash-balances', [
        '--factor' => '0.3333333333',
        '--run-key' => 'option3-aggressive-test',
    ]);

    expect($exitAgain)->toBe(0)
        ->and((int) CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->value('balance'))->toBe(100)
        ->and(CandleCashTransaction::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source', 'legacy_rebase')
            ->count())->toBe(1);
});
