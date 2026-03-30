<?php

use App\Models\MarketingSetting;
use App\Models\CandleCashReward;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Marketing\CandleCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('candle cash service treats active candle cash amounts as 1 to 1', function () {
    config()->set('marketing.candle_cash.legacy_points_per_candle_cash', 30);

    MarketingSetting::query()->updateOrCreate(
        ['key' => 'candle_cash_program_config'],
        [
            'value' => [
                'legacy_points_per_candle_cash' => 30,
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
    config()->set('marketing.candle_cash.legacy_points_per_candle_cash', 30);

    MarketingSetting::query()->updateOrCreate(
        ['key' => 'candle_cash_program_config'],
        [
            'value' => [
                'legacy_points_per_candle_cash' => 30,
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

test('candle cash service converts historical legacy starting points with the corrected 0.003 rule', function () {
    $service = app(CandleCashService::class);

    expect($service->legacyPointsToStartingCandleCash(100))->toBe(0.3)
        ->and($service->legacyPointsToStartingCandleCash(125))->toBe(0.375)
        ->and($service->amountFromPoints(0.375))->toBe(0.38)
        ->and($service->amountFromPoints(15))->toBe(15.0);
});

test('candle cash service resolves storefront runtime config through tenant overrides before global defaults', function () {
    MarketingSetting::query()->updateOrCreate(
        ['key' => 'candle_cash_program_config'],
        [
            'value' => [
                'legacy_points_per_candle_cash' => 30,
                'redeem_increment_dollars' => 10,
                'max_redeemable_per_order_dollars' => 10,
                'max_open_codes' => 1,
                'storefront_reward_type' => 'coupon',
            ],
            'description' => 'Global runtime config',
        ]
    );

    $tenant = Tenant::query()->create([
        'name' => 'Runtime Override Tenant',
        'slug' => 'runtime-override-tenant',
    ]);

    TenantMarketingSetting::query()->create([
        'tenant_id' => $tenant->id,
        'key' => 'candle_cash_program_config',
        'value' => [
            'legacy_points_per_candle_cash' => 30,
            'redeem_increment_dollars' => 25,
            'max_redeemable_per_order_dollars' => 25,
            'max_open_codes' => 2,
            'storefront_reward_type' => 'coupon',
        ],
        'description' => 'Tenant runtime override',
    ]);

    CandleCashReward::query()->create([
        'name' => 'Redeem $10 Reward Credit',
        'description' => 'Global storefront reward',
        'candle_cash_cost' => 10,
        'reward_type' => 'coupon',
        'reward_value' => '10USD',
        'is_active' => true,
    ]);
    $tenantReward = CandleCashReward::query()->create([
        'name' => 'Redeem $25 Reward Credit',
        'description' => 'Tenant storefront reward',
        'candle_cash_cost' => 25,
        'reward_type' => 'coupon',
        'reward_value' => '25USD',
        'is_active' => true,
    ]);

    $service = app(CandleCashService::class);

    expect($service->storefrontReward($tenant->id)?->id)->toBe($tenantReward->id)
        ->and($service->fixedRedemptionAmount($tenant->id))->toBe(25.0)
        ->and($service->maxOpenStorefrontCodes($tenant->id))->toBe(2)
        ->and(data_get($service->redemptionRulesPayload($tenant->id), 'redeem_increment_dollars'))->toBe(25.0);
});
