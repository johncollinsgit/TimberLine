<?php

use App\Actions\ScentGovernance\CreateScentAction;
use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendTemplateComponent;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PourBatch;
use App\Models\PourBatchLine;
use App\Models\Scent;
use App\Models\Size;
use App\Models\WaxInventory;
use App\Services\Reporting\DemandReportingService;
use App\Services\Reporting\InventoryReportingService;
use App\Services\Reporting\ScentAnalyticsService;
use App\Services\ScentGovernance\ScentRecipeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('separates forecast, current, and actual demand states', function () {
    $now = CarbonImmutable::parse('2026-03-07 10:00:00');
    CarbonImmutable::setTestNow($now);

    $oil = BaseOil::query()->create(['name' => 'State Oil']);
    $size = Size::query()->create(['code' => '8oz Cotton Wick', 'label' => '8oz Cotton Wick', 'is_active' => true]);

    /** @var Scent $scent */
    $scent = app(CreateScentAction::class)->execute([
        'name' => 'state split scent',
        'display_name' => 'State Split Scent',
        'lifecycle_status' => 'active',
        'recipe_components' => [
            ['component_type' => 'oil', 'base_oil_id' => $oil->id, 'parts' => 1],
        ],
    ]);

    $forecastOrder = Order::query()->create([
        'source' => 'manual',
        'order_number' => 'F-100',
        'order_type' => 'retail',
        'status' => 'reviewed',
        'due_at' => $now->addDays(2),
        'published_at' => null,
    ]);

    OrderLine::query()->create([
        'order_id' => $forecastOrder->id,
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'scent_name' => $scent->name,
        'size_code' => $size->code,
        'quantity' => 3,
        'ordered_qty' => 3,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    $currentOrder = Order::query()->create([
        'source' => 'manual',
        'order_number' => 'C-100',
        'order_type' => 'wholesale',
        'status' => 'submitted_to_pouring',
        'due_at' => $now->addDays(3),
        'published_at' => $now,
    ]);

    OrderLine::query()->create([
        'order_id' => $currentOrder->id,
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'scent_name' => $scent->name,
        'size_code' => $size->code,
        'quantity' => 5,
        'ordered_qty' => 5,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    $batch = PourBatch::query()->create([
        'name' => 'Actual Batch',
        'status' => 'completed',
        'order_type' => 'retail',
        'completed_at' => $now->addDay(),
    ]);

    PourBatchLine::query()->create([
        'pour_batch_id' => $batch->id,
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'quantity' => 4,
        'wax_grams' => 100,
        'oil_grams' => 20,
        'status' => 'completed',
        'completed_at' => $now->addDay(),
    ]);

    $service = app(DemandReportingService::class);

    $forecast = $service->forecastedScentDemand(4);
    $current = $service->currentScentDemand(4);
    $actual = $service->actualScentDemand(4);

    expect($forecast['state'])->toBe('forecast')
        ->and($forecast['totals']['units'])->toBe(3)
        ->and($current['state'])->toBe('current')
        ->and($current['totals']['units'])->toBe(5)
        ->and($actual['state'])->toBe('actual')
        ->and($actual['totals']['units'])->toBe(4);

    CarbonImmutable::setTestNow();
});

it('returns exploded oil demand from recipe truth for current demand', function () {
    $now = CarbonImmutable::parse('2026-03-07 10:00:00');
    CarbonImmutable::setTestNow($now);

    $oilA = BaseOil::query()->create(['name' => 'Exploded A']);
    $oilB = BaseOil::query()->create(['name' => 'Exploded B']);
    $size = Size::query()->create(['code' => '8oz', 'label' => '8oz', 'is_active' => true]);

    /** @var Scent $scent */
    $scent = app(CreateScentAction::class)->execute([
        'name' => 'exploded demand scent',
        'display_name' => 'Exploded Demand Scent',
        'lifecycle_status' => 'active',
    ]);

    app(ScentRecipeService::class)->syncActiveRecipeForScent($scent, [
        'recipe_components' => [
            ['component_type' => 'oil', 'base_oil_id' => $oilA->id, 'parts' => 1],
            ['component_type' => 'oil', 'base_oil_id' => $oilB->id, 'parts' => 1],
        ],
        'source_context' => 'reporting-test-exploded',
        'lifecycle_status' => 'active',
    ], true);

    $order = Order::query()->create([
        'source' => 'manual',
        'order_number' => 'E-100',
        'order_type' => 'retail',
        'status' => 'submitted_to_pouring',
        'due_at' => $now->addDays(1),
        'published_at' => $now,
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'scent_name' => $scent->name,
        'size_code' => $size->code,
        'quantity' => 8,
        'ordered_qty' => 8,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    $service = app(DemandReportingService::class);
    $scentSnapshot = $service->currentScentDemand(2);
    $exploded = $service->explodedOilDemand('current', 2);

    $this->assertEqualsWithDelta(
        (float) $scentSnapshot['totals']['oil_grams'],
        (float) $exploded['totals']['oil_grams'],
        0.01
    );

    $byOil = collect($exploded['rows'])->keyBy('base_oil_id');

    expect($exploded['state'])->toBe('current')
        ->and($byOil)->toHaveKeys([$oilA->id, $oilB->id]);

    $this->assertEqualsWithDelta(
        (float) data_get($byOil[$oilA->id], 'grams'),
        (float) data_get($byOil[$oilB->id], 'grams'),
        0.01
    );

    CarbonImmutable::setTestNow();
});

it('reports blend-template and wax demand seams for current workload', function () {
    $now = CarbonImmutable::parse('2026-03-07 10:00:00');
    CarbonImmutable::setTestNow($now);

    $blendOil = BaseOil::query()->create(['name' => 'Blend Template Oil']);
    $directOil = BaseOil::query()->create(['name' => 'Direct Oil']);

    $template = Blend::query()->create(['name' => 'Template Alpha', 'is_blend' => true]);
    BlendTemplateComponent::query()->create([
        'blend_id' => $template->id,
        'component_type' => 'oil',
        'base_oil_id' => $blendOil->id,
        'ratio_weight' => 1,
        'sort_order' => 0,
    ]);

    $size = Size::query()->create(['code' => '16oz', 'label' => '16oz', 'is_active' => true]);

    /** @var Scent $scent */
    $scent = app(CreateScentAction::class)->execute([
        'name' => 'blend seam scent',
        'display_name' => 'Blend Seam Scent',
        'lifecycle_status' => 'active',
    ]);

    app(ScentRecipeService::class)->syncActiveRecipeForScent($scent, [
        'recipe_components' => [
            ['component_type' => 'blend_template', 'blend_template_id' => $template->id, 'percentage' => 60],
            ['component_type' => 'oil', 'base_oil_id' => $directOil->id, 'percentage' => 40],
        ],
        'source_context' => 'reporting-test',
        'lifecycle_status' => 'active',
    ], true);

    $order = Order::query()->create([
        'source' => 'manual',
        'order_number' => 'B-100',
        'order_type' => 'retail',
        'status' => 'submitted_to_pouring',
        'due_at' => $now->addDays(2),
        'published_at' => $now,
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'scent_name' => $scent->name,
        'size_code' => $size->code,
        'quantity' => 2,
        'ordered_qty' => 2,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    $service = app(DemandReportingService::class);
    $blendDemand = $service->blendTemplateDemand('current', 2);
    $waxDemand = $service->waxDemand('current', 2);

    $byTemplate = collect($blendDemand['rows'])->keyBy('blend_template_id');

    expect($blendDemand['state'])->toBe('current')
        ->and($byTemplate)->toHaveKey($template->id)
        ->and((float) data_get($byTemplate[$template->id], 'oil_grams'))->toBeGreaterThan(0)
        ->and((float) $waxDemand['totals']['wax_grams'])->toBeGreaterThan(0)
        ->and(collect($waxDemand['rows'])->pluck('channel'))->toContain('retail');

    CarbonImmutable::setTestNow();
});

it('summarizes only unresolved and non-excluded mapping exceptions', function () {
    DB::table('mapping_exceptions')->insert([
        [
            'store_key' => 'retail-main',
            'raw_title' => 'Mystery Retail',
            'raw_scent_name' => 'Mystery Retail',
            'resolved_at' => null,
            'excluded_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'store_key' => 'wholesale-main',
            'raw_title' => 'Mystery Wholesale',
            'raw_scent_name' => 'Mystery Wholesale',
            'resolved_at' => null,
            'excluded_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'store_key' => 'retail-main',
            'raw_title' => 'Resolved One',
            'raw_scent_name' => 'Resolved One',
            'resolved_at' => now(),
            'excluded_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'store_key' => 'wholesale-main',
            'raw_title' => 'Excluded One',
            'raw_scent_name' => 'Excluded One',
            'resolved_at' => null,
            'excluded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $service = app(ScentAnalyticsService::class);

    $all = $service->unmappedExceptionSummary(limit: 10);
    $wholesaleOnly = $service->unmappedExceptionSummary(limit: 10, channel: 'wholesale');

    expect($all['open_count'])->toBe(2)
        ->and(collect($all['by_channel'])->keyBy('channel')->get('retail')['open_count'])->toBe(1)
        ->and(collect($all['by_channel'])->keyBy('channel')->get('wholesale')['open_count'])->toBe(1)
        ->and($wholesaleOnly['open_count'])->toBe(1)
        ->and(collect($all['top_raw_names'])->pluck('raw_name'))->toContain('Mystery Retail');
});

it('provides reorder-risk inputs from exploded oil demand and wax demand', function () {
    $now = CarbonImmutable::parse('2026-03-07 10:00:00');
    CarbonImmutable::setTestNow($now);

    $oil = BaseOil::query()->create([
        'name' => 'Risk Oil',
        'grams_on_hand' => 120,
        'reorder_threshold' => 200,
    ]);

    WaxInventory::query()
        ->whereRaw('lower(name) = ?', ['candle wax'])
        ->update([
            'on_hand_grams' => 400,
            'reorder_threshold_grams' => 500,
            'active' => true,
        ]);

    $size = Size::query()->create(['code' => '16oz', 'label' => '16oz', 'is_active' => true]);

    /** @var Scent $scent */
    $scent = app(CreateScentAction::class)->execute([
        'name' => 'risk scent',
        'display_name' => 'Risk Scent',
        'lifecycle_status' => 'active',
    ]);

    app(ScentRecipeService::class)->syncActiveRecipeForScent($scent, [
        'recipe_components' => [
            ['component_type' => 'oil', 'base_oil_id' => $oil->id, 'parts' => 1],
        ],
        'source_context' => 'reporting-test-risk',
        'lifecycle_status' => 'active',
    ], true);

    $order = Order::query()->create([
        'source' => 'manual',
        'order_number' => 'R-100',
        'order_type' => 'retail',
        'status' => 'submitted_to_pouring',
        'due_at' => $now->addDays(1),
        'published_at' => $now,
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'scent_name' => $scent->name,
        'size_code' => $size->code,
        'quantity' => 2,
        'ordered_qty' => 2,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    $risk = app(InventoryReportingService::class)->reorderRiskInputs('current', 2);

    $oilRows = collect(data_get($risk, 'oil.rows', []))->keyBy('base_oil_id');

    expect($risk['state'])->toBe('current')
        ->and($oilRows)->toHaveKey($oil->id)
        ->and(data_get($oilRows[$oil->id], 'state_after_demand.status'))->toBe('reorder')
        ->and((int) data_get($risk, 'oil.summary.reorder_count'))->toBeGreaterThan(0)
        ->and((float) data_get($risk, 'wax.demand_totals.wax_grams'))->toBeGreaterThan(0)
        ->and((int) data_get($risk, 'wax.summary.row_count'))->toBeGreaterThan(0);

    CarbonImmutable::setTestNow();
});
