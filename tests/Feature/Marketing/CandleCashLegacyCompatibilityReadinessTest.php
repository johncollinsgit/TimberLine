<?php

use App\Models\BirthdayRewardIssuance;
use App\Models\CandleCashLegacyCompatibilityUsage;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\MarketingSetting;
use App\Services\Marketing\CandleCashLegacyCompatibilityService;
use App\Services\Marketing\CandleCashService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    app(CandleCashLegacyCompatibilityService::class)->reset();
});

test('legacy candle cash model shims record legacy reads writes and fallback reads', function () {
    $transaction = new CandleCashTransaction();
    $transaction->points = 25;

    $legacyOnlyTransaction = new CandleCashTransaction();
    $legacyOnlyTransaction->setRawAttributes(['points' => 25], true);

    expect($transaction->points)->toBe(25)
        ->and($legacyOnlyTransaction->candle_cash_delta)->toBe(25);

    $rows = CandleCashLegacyCompatibilityUsage::query()
        ->orderBy('operation')
        ->get()
        ->mapWithKeys(fn (CandleCashLegacyCompatibilityUsage $row): array => [$row->operation => $row->path])
        ->all();

    expect($rows)->toMatchArray([
        'legacy_write' => 'candle_cash_transactions.points',
        'legacy_read' => 'candle_cash_transactions.points',
        'fallback_read' => 'candle_cash_transactions.points',
    ]);
});

test('legacy candle cash program config fallback usage is recorded', function () {
    MarketingSetting::query()->updateOrCreate(
        ['key' => 'candle_cash_program_config'],
        [
            'value' => [
                'points_per_dollar' => 30,
                'redeem_increment_dollars' => 10,
                'max_redeemable_per_order_dollars' => 10,
                'max_open_codes' => 1,
            ],
            'description' => 'Legacy Candle Cash config for readiness test',
        ]
    );

    $config = app(CandleCashService::class)->programConfig();

    expect((int) ($config['legacy_points_per_candle_cash'] ?? 0))->toBe(30)
        ->and(CandleCashLegacyCompatibilityUsage::query()
            ->where('path', 'marketing_settings.candle_cash_program_config.points_per_dollar')
            ->where('operation', 'config_fallback')
            ->exists())->toBeTrue();
});

test('birthday points normalization usage is recorded', function () {
    $issuance = new BirthdayRewardIssuance();
    $issuance->reward_type = 'points';

    expect($issuance->reward_type)->toBe('candle_cash')
        ->and(CandleCashLegacyCompatibilityUsage::query()
            ->where('path', 'birthday_reward_issuances.reward_type')
            ->where('operation', 'normalization')
            ->exists())->toBeTrue();
});

test('canonical candle cash paths stay silent and readiness command reports ready', function () {
    $profile = MarketingProfile::query()->create([
        'email' => 'canonical@example.com',
        'normalized_email' => 'canonical@example.com',
    ]);

    app(CandleCashService::class)->addPoints(
        profile: $profile,
        points: 15,
        type: 'earn',
        source: 'test',
        sourceId: 'canonical-only',
        description: 'Canonical Candle Cash earn'
    );

    expect(CandleCashLegacyCompatibilityUsage::query()->count())->toBe(0);

    Artisan::call('marketing:candle-cash-compatibility-readiness', ['--json' => true]);

    $summary = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect(data_get($summary, 'observed.total_signals'))->toBe(0)
        ->and(data_get($summary, 'go_no_go.ready_to_drop_old_columns'))->toBeTrue();
});
