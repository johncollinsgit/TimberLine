<?php

use App\Actions\Inventory\AdjustInventoryAction;
use App\Models\BaseOil;
use App\Models\InventoryAdjustment;
use App\Models\WaxInventory;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\WaxConversionService;
use Illuminate\Validation\ValidationException;

it('sets oil on-hand and records a signed adjustment row', function () {
    $oil = BaseOil::query()->create([
        'name' => 'Inventory Test Oil',
        'grams_on_hand' => 100,
        'reorder_threshold' => 200,
    ]);

    $adjustment = app(AdjustInventoryAction::class)->setOilOnHand(
        oil: $oil,
        targetOnHand: 250,
        reason: InventoryAdjustment::REASON_RECOUNT,
        notes: 'Physical recount',
        performedBy: null
    );

    expect($adjustment)->not->toBeNull();
    expect($adjustment?->item_type)->toBe(InventoryAdjustment::ITEM_TYPE_OIL);
    expect((float) $adjustment?->grams_delta)->toBe(150.0);
    expect((float) $adjustment?->before_grams)->toBe(100.0);
    expect((float) $adjustment?->after_grams)->toBe(250.0);
    expect($oil->fresh()->grams_on_hand)->toBe('250.00');
});

it('records positive and negative wax adjustments with history', function () {
    $wax = WaxInventory::query()->create([
        'name' => 'Wax Test Row',
        'on_hand_grams' => 0,
        'reorder_threshold_grams' => 1000,
    ]);

    $received = app(AdjustInventoryAction::class)->adjustWax(
        wax: $wax,
        gramsDelta: 500,
        reason: InventoryAdjustment::REASON_RECEIVED,
        notes: 'Shipment in',
        performedBy: null
    );
    $spill = app(AdjustInventoryAction::class)->adjustWax(
        wax: $wax,
        gramsDelta: -200,
        reason: InventoryAdjustment::REASON_SPILL,
        notes: 'Operator spill',
        performedBy: null
    );

    expect((float) $received?->grams_delta)->toBe(500.0);
    expect((float) $spill?->grams_delta)->toBe(-200.0);
    expect($wax->fresh()->on_hand_grams)->toBe('300.00');
    expect(InventoryAdjustment::query()->where('wax_inventory_id', $wax->id)->count())->toBe(2);
});

it('evaluates reorder states from on-hand and thresholds', function () {
    $service = app(InventoryService::class);

    $ok = $service->evaluateReorderState(300, 200);
    $low = $service->evaluateReorderState(120, 200);
    $reorder = $service->evaluateReorderState(50, 200);

    expect($ok['status'])->toBe('ok');
    expect($low['status'])->toBe('low');
    expect($reorder['status'])->toBe('reorder');
});

it('converts wax units between grams, pounds, and box equivalents', function () {
    $conversion = app(WaxConversionService::class);

    $grams45lb = $conversion->boxesToGrams(1);
    $pounds = $conversion->gramsToPounds($grams45lb);
    $boxes = $conversion->gramsToBoxes($grams45lb);

    $this->assertEqualsWithDelta(20411.66, $grams45lb, 0.02);
    $this->assertEqualsWithDelta(45.0, $pounds, 0.02);
    $this->assertEqualsWithDelta(1.0, $boxes, 0.001);
    $this->assertEqualsWithDelta(163293.26, $conversion->defaultWaxReorderThresholdGrams(), 0.02);
});

it('evaluates projected oil inventory after demand', function () {
    $oilA = BaseOil::query()->create([
        'name' => 'Demand Oil A',
        'grams_on_hand' => 500,
        'reorder_threshold' => 200,
    ]);

    $oilB = BaseOil::query()->create([
        'name' => 'Demand Oil B',
        'grams_on_hand' => 150,
        'reorder_threshold' => 200,
    ]);

    $rows = app(InventoryService::class)->evaluateDemandAgainstOilInventory([
        $oilA->id => 100,
        $oilB->id => 120,
    ]);

    expect($rows)->toHaveCount(2);
    $byId = collect($rows)->keyBy('base_oil_id');

    expect((float) $byId[$oilA->id]['projected_on_hand_grams'])->toBe(400.0);
    expect($byId[$oilA->id]['state_after_demand']['status'])->toBe('ok');
    expect((float) $byId[$oilB->id]['projected_on_hand_grams'])->toBe(30.0);
    expect($byId[$oilB->id]['state_after_demand']['status'])->toBe('reorder');
});

it('rejects invalid adjustment reasons', function () {
    $oil = BaseOil::query()->create([
        'name' => 'Bad Reason Oil',
        'grams_on_hand' => 100,
        'reorder_threshold' => 100,
    ]);

    app(AdjustInventoryAction::class)->adjustOil(
        oil: $oil,
        gramsDelta: 10,
        reason: 'not_a_reason'
    );
})->throws(ValidationException::class);
