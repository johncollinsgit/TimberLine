<?php

use App\Models\MarketingSetting;
use App\Services\Marketing\CandleCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('candle cash service treats active candle cash amounts as 1 to 1', function () {
    config()->set('marketing.candle_cash.points_per_dollar', 30);

    MarketingSetting::query()->updateOrCreate(
        ['key' => 'candle_cash_program_config'],
        [
            'value' => [
                'points_per_dollar' => 30,
                'redeem_increment_dollars' => 10,
                'max_redeemable_per_order_dollars' => 10,
                'max_open_codes' => 1,
            ],
            'description' => 'Candle Cash test config',
        ]
    );

    $service = app(CandleCashService::class);

    expect($service->pointsFromAmount(15))->toBe(15)
        ->and($service->amountFromPoints(150))->toBe(150.0)
        ->and($service->fixedRedemptionPoints())->toBe(10);
});

test('candle cash service keeps the legacy points conversion isolated for compatibility helpers', function () {
    config()->set('marketing.candle_cash.points_per_dollar', 30);

    MarketingSetting::query()->updateOrCreate(
        ['key' => 'candle_cash_program_config'],
        [
            'value' => [
                'points_per_dollar' => 30,
                'redeem_increment_dollars' => 10,
                'max_redeemable_per_order_dollars' => 10,
                'max_open_codes' => 1,
            ],
            'description' => 'Candle Cash compatibility config',
        ]
    );

    $service = app(CandleCashService::class);

    expect($service->legacyPointsFromCandleCash(5))->toBe(150)
        ->and($service->candleCashFromLegacyPoints(150))->toBe(5.0)
        ->and($service->legacyPointsPerCandleCash())->toBe(30);
});
