<?php

use App\Actions\ScentGovernance\CreateScentAction;
use App\Models\BaseOil;
use App\Models\MappingException;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Size;
use App\Services\Reporting\AnalyticsComparisonService;
use App\Services\Reporting\AnalyticsTimeframeService;
use App\Services\Reporting\DemandReportingService;
use App\Services\Reporting\ScentAnalyticsService;
use Carbon\CarbonImmutable;

it('resolves rolling presets with previous-period comparison windows', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-08 12:00:00'));

    $resolved = app(AnalyticsTimeframeService::class)->resolve([
        'time_mode' => 'rolling',
        'preset' => 'last_30_days',
        'comparison_mode' => 'previous_period',
    ]);

    expect(data_get($resolved, 'primary.days'))->toBe(30)
        ->and(data_get($resolved, 'comparison.days'))->toBe(30)
        ->and(data_get($resolved, 'comparison.to_date'))
        ->toBe(CarbonImmutable::parse(data_get($resolved, 'primary.from_date'))->subDay()->toDateString())
        ->and(data_get($resolved, 'comparison.from_date'))
        ->toBe(CarbonImmutable::parse(data_get($resolved, 'primary.from_date'))->subDays(30)->toDateString());

    CarbonImmutable::setTestNow();
});

it('resolves fixed this-month windows with same-period-last-year comparison', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-08 12:00:00'));

    $resolved = app(AnalyticsTimeframeService::class)->resolve([
        'time_mode' => 'fixed',
        'preset' => 'this_month',
        'comparison_mode' => 'same_period_last_year',
    ]);

    expect(data_get($resolved, 'primary.from_date'))->toBe('2026-03-01')
        ->and(data_get($resolved, 'primary.to_date'))->toBe('2026-03-31')
        ->and(data_get($resolved, 'comparison.from_date'))->toBe('2025-03-01')
        ->and(data_get($resolved, 'comparison.to_date'))->toBe('2025-03-31');

    CarbonImmutable::setTestNow();
});

it('resolves year-over-year and custom fixed windows deterministically', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-08 12:00:00'));

    $yoy = app(AnalyticsTimeframeService::class)->resolve([
        'time_mode' => 'rolling',
        'preset' => 'last_90_days',
        'comparison_mode' => 'year_over_year',
    ]);

    expect(data_get($yoy, 'comparison.from_date'))
        ->toBe(CarbonImmutable::parse(data_get($yoy, 'primary.from_date'))->subYear()->toDateString())
        ->and(data_get($yoy, 'comparison.to_date'))
        ->toBe(CarbonImmutable::parse(data_get($yoy, 'primary.to_date'))->subYear()->toDateString());

    $custom = app(AnalyticsTimeframeService::class)->resolve([
        'time_mode' => 'fixed',
        'preset' => 'custom',
        'custom_start_date' => '2026-01-10',
        'custom_end_date' => '2026-01-20',
        'comparison_mode' => 'previous_period',
    ]);

    expect(data_get($custom, 'primary.days'))->toBe(11)
        ->and(data_get($custom, 'comparison.from_date'))->toBe('2025-12-30')
        ->and(data_get($custom, 'comparison.to_date'))->toBe('2026-01-09');

    CarbonImmutable::setTestNow();
});

it('calculates comparison deltas and trend metadata consistently', function () {
    $result = app(AnalyticsComparisonService::class)->compareTotals(
        primaryTotals: ['units' => 120, 'wax_grams' => 1400],
        comparisonTotals: ['units' => 100, 'wax_grams' => 1500],
        keys: ['units', 'wax_grams']
    );

    expect(data_get($result, 'has_comparison'))->toBeTrue()
        ->and(data_get($result, 'metrics.units.delta'))->toBe(20.0)
        ->and(data_get($result, 'metrics.units.delta_pct'))->toBe(20.0)
        ->and(data_get($result, 'metrics.units.trend'))->toBe('up')
        ->and(data_get($result, 'metrics.wax_grams.delta'))->toBe(-100.0)
        ->and(data_get($result, 'metrics.wax_grams.trend'))->toBe('down');
});

it('returns demand bundle deltas for primary vs comparison windows', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-08 12:00:00'));

    $oil = BaseOil::query()->create(['name' => 'Time Delta Oil']);
    $size = Size::query()->create([
        'code' => '8oz Cotton Wick',
        'label' => '8oz Cotton Wick',
        'is_active' => true,
    ]);

    $scent = app(CreateScentAction::class)->execute([
        'name' => 'time delta scent',
        'display_name' => 'Time Delta Scent',
        'lifecycle_status' => 'active',
        'recipe_components' => [
            ['component_type' => 'oil', 'base_oil_id' => $oil->id, 'parts' => 1],
        ],
    ]);

    $comparisonOrder = Order::query()->create([
        'source' => 'manual',
        'order_number' => 'CMP-100',
        'order_type' => 'retail',
        'status' => 'submitted_to_pouring',
        'published_at' => CarbonImmutable::parse('2026-02-25 10:00:00'),
        'due_at' => CarbonImmutable::parse('2026-02-25 10:00:00'),
    ]);
    OrderLine::query()->create([
        'order_id' => $comparisonOrder->id,
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'scent_name' => $scent->name,
        'size_code' => $size->code,
        'quantity' => 3,
        'ordered_qty' => 3,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    $primaryOrder = Order::query()->create([
        'source' => 'manual',
        'order_number' => 'PRI-100',
        'order_type' => 'retail',
        'status' => 'submitted_to_pouring',
        'published_at' => CarbonImmutable::parse('2026-03-04 10:00:00'),
        'due_at' => CarbonImmutable::parse('2026-03-04 10:00:00'),
    ]);
    OrderLine::query()->create([
        'order_id' => $primaryOrder->id,
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'scent_name' => $scent->name,
        'size_code' => $size->code,
        'quantity' => 5,
        'ordered_qty' => 5,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    $timeframe = app(AnalyticsTimeframeService::class)->resolve([
        'time_mode' => 'fixed',
        'preset' => 'custom',
        'custom_start_date' => '2026-03-01',
        'custom_end_date' => '2026-03-07',
        'comparison_mode' => 'previous_period',
    ]);

    $bundle = app(DemandReportingService::class)->scentDemandWithComparison('current', $timeframe, 'retail');

    expect(data_get($bundle, 'primary.totals.units'))->toBe(5)
        ->and(data_get($bundle, 'comparison.totals.units'))->toBe(3)
        ->and(data_get($bundle, 'delta.metrics.units.delta'))->toBe(2.0)
        ->and(data_get($bundle, 'delta.metrics.units.trend'))->toBe('up')
        ->and(data_get($bundle, 'delta.has_comparison'))->toBeTrue();

    CarbonImmutable::setTestNow();
});

it('returns deterministic trend series for demand and unmapped exceptions', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-08 12:00:00'));

    $oil = BaseOil::query()->create(['name' => 'Trend Oil']);
    $size = Size::query()->create([
        'code' => '8oz Cotton Wick',
        'label' => '8oz Cotton Wick',
        'is_active' => true,
    ]);
    $scent = app(CreateScentAction::class)->execute([
        'name' => 'trend series scent',
        'display_name' => 'Trend Series Scent',
        'lifecycle_status' => 'active',
        'recipe_components' => [
            ['component_type' => 'oil', 'base_oil_id' => $oil->id, 'parts' => 1],
        ],
    ]);

    $order = Order::query()->create([
        'source' => 'manual',
        'order_number' => 'TR-100',
        'order_type' => 'retail',
        'status' => 'submitted_to_pouring',
        'published_at' => CarbonImmutable::parse('2026-03-05 09:00:00'),
        'due_at' => CarbonImmutable::parse('2026-03-05 09:00:00'),
    ]);
    OrderLine::query()->create([
        'order_id' => $order->id,
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'scent_name' => $scent->name,
        'size_code' => $size->code,
        'quantity' => 4,
        'ordered_qty' => 4,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    MappingException::query()->create([
        'store_key' => 'retail-main',
        'raw_title' => 'Trend Mystery',
        'raw_scent_name' => 'Trend Mystery',
        'resolved_at' => null,
        'created_at' => CarbonImmutable::parse('2026-03-06 10:00:00'),
        'updated_at' => CarbonImmutable::parse('2026-03-06 10:00:00'),
    ]);

    $timeframe = app(AnalyticsTimeframeService::class)->resolve([
        'time_mode' => 'fixed',
        'preset' => 'custom',
        'custom_start_date' => '2026-03-01',
        'custom_end_date' => '2026-03-08',
        'comparison_mode' => 'none',
    ]);

    $demandTrend = app(DemandReportingService::class)->trendSeries('current', $timeframe, 'retail', 'units', 4);
    $exceptionTrend = app(ScentAnalyticsService::class)->unmappedExceptionTrend($timeframe, 4, 'retail');

    expect(count($demandTrend))->toBeGreaterThan(1)
        ->and(count($demandTrend))->toBeLessThanOrEqual(8)
        ->and(count($exceptionTrend))->toBeGreaterThan(1)
        ->and(count($exceptionTrend))->toBeLessThanOrEqual(8)
        ->and(collect($demandTrend)->sum('value'))->toBeGreaterThan(0)
        ->and(collect($exceptionTrend)->sum('value'))->toBeGreaterThan(0);

    CarbonImmutable::setTestNow();
});
