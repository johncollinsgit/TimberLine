<?php

use App\Actions\ScentGovernance\CreateScentAction;
use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendTemplateComponent;
use App\Models\Scent;
use App\Services\Recipes\Exceptions\FormulaCycleDetectedException;
use App\Services\Recipes\FlattenFormulaService;
use App\Services\ScentGovernance\ScentRecipeService;

it('flattens a single-oil recipe and returns grams', function () {
    $oil = BaseOil::query()->create(['name' => 'Patchouli']);

    $scent = app(CreateScentAction::class)->execute([
        'name' => 'single oil flattening test',
        'display_name' => 'Single Oil Flattening Test',
        'oil_reference_name' => 'Patchouli',
        'is_blend' => false,
        'lifecycle_status' => 'active',
    ]);

    $result = app(FlattenFormulaService::class)->flattenScent($scent, 2000);

    expect($result['components'])->toHaveCount(1);
    expect($result['components'][0]['base_oil_id'])->toBe($oil->id);
    expect((float) $result['components'][0]['percentage'])->toBe(100.0);
    expect((float) $result['components'][0]['grams'])->toBe(2000.0);
});

it('normalizes parts-based multi-oil recipes deterministically', function () {
    $oilA = BaseOil::query()->create(['name' => 'Blue Spruce']);
    $oilB = BaseOil::query()->create(['name' => 'Oak Moss & Amber']);

    $scent = app(CreateScentAction::class)->execute([
        'name' => 'parts split flattening test',
        'display_name' => 'Parts Split Flattening Test',
        'is_blend' => false,
        'lifecycle_status' => 'active',
    ]);

    app(ScentRecipeService::class)->syncActiveRecipeForScent($scent, [
        'recipe_components' => [
            ['component_type' => 'oil', 'base_oil_id' => $oilA->id, 'parts' => 2],
            ['component_type' => 'oil', 'base_oil_id' => $oilB->id, 'parts' => 1],
        ],
        'source_context' => 'test-parts',
        'lifecycle_status' => 'active',
    ], true);

    $result = app(FlattenFormulaService::class)->flattenScent($scent, 300);
    $byOil = $result['by_oil_id'];

    $this->assertEqualsWithDelta(66.666667, (float) $byOil[(string) $oilA->id]['percentage'], 0.0005);
    $this->assertEqualsWithDelta(33.333333, (float) $byOil[(string) $oilB->id]['percentage'], 0.0005);
    $this->assertEqualsWithDelta(200.0, (float) $byOil[(string) $oilA->id]['grams'], 0.01);
    $this->assertEqualsWithDelta(100.0, (float) $byOil[(string) $oilB->id]['grams'], 0.01);
});

it('supports equal split when no parts or percentages are provided', function () {
    $oils = collect([
        BaseOil::query()->create(['name' => 'Oil A']),
        BaseOil::query()->create(['name' => 'Oil B']),
        BaseOil::query()->create(['name' => 'Oil C']),
        BaseOil::query()->create(['name' => 'Oil D']),
        BaseOil::query()->create(['name' => 'Oil E']),
    ]);

    $components = $oils->map(fn (BaseOil $oil): array => [
        'component_type' => 'oil',
        'base_oil_id' => $oil->id,
    ])->all();

    $scent = app(CreateScentAction::class)->execute([
        'name' => 'equal split flattening test',
        'display_name' => 'Equal Split Flattening Test',
        'is_blend' => false,
        'lifecycle_status' => 'active',
    ]);

    app(ScentRecipeService::class)->syncActiveRecipeForScent($scent, [
        'recipe_components' => $components,
        'source_context' => 'test-equal',
        'lifecycle_status' => 'active',
    ], true);

    $result = app(FlattenFormulaService::class)->flattenScent($scent, 500);

    expect($result['components'])->toHaveCount(5);

    foreach ($result['components'] as $row) {
        $this->assertEqualsWithDelta(20.0, (float) $row['percentage'], 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $row['grams'], 0.01);
    }
});

it('expands blend template plus direct oil components', function () {
    $blue = BaseOil::query()->create(['name' => 'Blue Spruce']);
    $oak = BaseOil::query()->create(['name' => 'Oak Moss & Amber']);
    $campfire = BaseOil::query()->create(['name' => 'Campfire']);
    $patchouli = BaseOil::query()->create(['name' => 'Patchouli Teakwood']);

    $thruHike = Blend::query()->create(['name' => 'Thru Hike', 'is_blend' => true]);
    BlendTemplateComponent::query()->create([
        'blend_id' => $thruHike->id,
        'component_type' => 'oil',
        'base_oil_id' => $blue->id,
        'ratio_weight' => 3,
        'sort_order' => 0,
    ]);
    BlendTemplateComponent::query()->create([
        'blend_id' => $thruHike->id,
        'component_type' => 'oil',
        'base_oil_id' => $oak->id,
        'ratio_weight' => 2,
        'sort_order' => 1,
    ]);
    BlendTemplateComponent::query()->create([
        'blend_id' => $thruHike->id,
        'component_type' => 'oil',
        'base_oil_id' => $campfire->id,
        'ratio_weight' => 1,
        'sort_order' => 2,
    ]);

    $scent = app(CreateScentAction::class)->execute([
        'name' => 'on the trail flattening test',
        'display_name' => 'On The Trail Flattening Test',
        'is_blend' => true,
        'oil_blend_id' => $thruHike->id,
        'lifecycle_status' => 'active',
    ]);

    app(ScentRecipeService::class)->syncActiveRecipeForScent($scent, [
        'recipe_components' => [
            ['component_type' => 'blend_template', 'blend_template_id' => $thruHike->id, 'percentage' => 50],
            ['component_type' => 'oil', 'base_oil_id' => $patchouli->id, 'percentage' => 50],
        ],
        'source_context' => 'test-template-plus-oil',
        'lifecycle_status' => 'active',
    ], true);

    $result = app(FlattenFormulaService::class)->flattenScent($scent, 1000);
    $byOil = $result['by_oil_id'];

    $this->assertEqualsWithDelta(50.0, (float) $byOil[(string) $patchouli->id]['percentage'], 0.0001);
    $this->assertEqualsWithDelta(25.0, (float) $byOil[(string) $blue->id]['percentage'], 0.0001);
    $this->assertEqualsWithDelta(16.666667, (float) $byOil[(string) $oak->id]['percentage'], 0.0005);
    $this->assertEqualsWithDelta(8.333333, (float) $byOil[(string) $campfire->id]['percentage'], 0.0005);
    $this->assertEqualsWithDelta(500.0, (float) $byOil[(string) $patchouli->id]['grams'], 0.01);
});

it('expands nested blend templates recursively', function () {
    $a = BaseOil::query()->create(['name' => 'Oil Nested A']);
    $b = BaseOil::query()->create(['name' => 'Oil Nested B']);
    $c = BaseOil::query()->create(['name' => 'Oil Nested C']);

    $child = Blend::query()->create(['name' => 'Nested Child', 'is_blend' => true]);
    $parent = Blend::query()->create(['name' => 'Nested Parent', 'is_blend' => true]);

    BlendTemplateComponent::query()->create([
        'blend_id' => $child->id,
        'component_type' => 'oil',
        'base_oil_id' => $a->id,
        'ratio_weight' => 1,
        'sort_order' => 0,
    ]);
    BlendTemplateComponent::query()->create([
        'blend_id' => $child->id,
        'component_type' => 'oil',
        'base_oil_id' => $b->id,
        'ratio_weight' => 1,
        'sort_order' => 1,
    ]);

    BlendTemplateComponent::query()->create([
        'blend_id' => $parent->id,
        'component_type' => 'blend_template',
        'blend_template_id' => $child->id,
        'ratio_weight' => 3,
        'sort_order' => 0,
    ]);
    BlendTemplateComponent::query()->create([
        'blend_id' => $parent->id,
        'component_type' => 'oil',
        'base_oil_id' => $c->id,
        'ratio_weight' => 1,
        'sort_order' => 1,
    ]);

    $scent = app(CreateScentAction::class)->execute([
        'name' => 'nested blend flattening test',
        'display_name' => 'Nested Blend Flattening Test',
        'is_blend' => true,
        'oil_blend_id' => $parent->id,
        'lifecycle_status' => 'active',
    ]);

    app(ScentRecipeService::class)->syncActiveRecipeForScent($scent, [
        'recipe_components' => [
            ['component_type' => 'blend_template', 'blend_template_id' => $parent->id, 'percentage' => 100],
        ],
        'source_context' => 'test-nested',
        'lifecycle_status' => 'active',
    ], true);

    $result = app(FlattenFormulaService::class)->flattenScent($scent, 400);
    $byOil = $result['by_oil_id'];

    $this->assertEqualsWithDelta(37.5, (float) $byOil[(string) $a->id]['percentage'], 0.0001);
    $this->assertEqualsWithDelta(37.5, (float) $byOil[(string) $b->id]['percentage'], 0.0001);
    $this->assertEqualsWithDelta(25.0, (float) $byOil[(string) $c->id]['percentage'], 0.0001);
    $this->assertEqualsWithDelta(150.0, (float) $byOil[(string) $a->id]['grams'], 0.01);
    $this->assertEqualsWithDelta(100.0, (float) $byOil[(string) $c->id]['grams'], 0.01);
});

it('hard-fails on circular nested blend template references', function () {
    $a = Blend::query()->create(['name' => 'Cycle A', 'is_blend' => true]);
    $b = Blend::query()->create(['name' => 'Cycle B', 'is_blend' => true]);

    BlendTemplateComponent::query()->create([
        'blend_id' => $a->id,
        'component_type' => 'blend_template',
        'blend_template_id' => $b->id,
        'ratio_weight' => 1,
    ]);
    BlendTemplateComponent::query()->create([
        'blend_id' => $b->id,
        'component_type' => 'blend_template',
        'blend_template_id' => $a->id,
        'ratio_weight' => 1,
    ]);

    $scent = app(CreateScentAction::class)->execute([
        'name' => 'cycle flattening test',
        'display_name' => 'Cycle Flattening Test',
        'is_blend' => true,
        'oil_blend_id' => $a->id,
        'lifecycle_status' => 'active',
    ]);

    app(ScentRecipeService::class)->syncActiveRecipeForScent($scent, [
        'recipe_components' => [
            ['component_type' => 'blend_template', 'blend_template_id' => $a->id, 'percentage' => 100],
        ],
        'source_context' => 'test-cycle',
        'lifecycle_status' => 'active',
    ], true);

    app(FlattenFormulaService::class)->flattenScent($scent);
})->throws(FormulaCycleDetectedException::class);

it('prioritizes active recipe truth over legacy scent fields', function () {
    $legacyOil = BaseOil::query()->create(['name' => 'Legacy Oil']);
    $activeOil = BaseOil::query()->create(['name' => 'Active Recipe Oil']);

    $legacyBlend = Blend::query()->create(['name' => 'Legacy Blend', 'is_blend' => true]);
    BlendTemplateComponent::query()->create([
        'blend_id' => $legacyBlend->id,
        'component_type' => 'oil',
        'base_oil_id' => $legacyOil->id,
        'ratio_weight' => 1,
    ]);

    $scent = app(CreateScentAction::class)->execute([
        'name' => 'active recipe precedence test',
        'display_name' => 'Active Recipe Precedence Test',
        'is_blend' => true,
        'oil_blend_id' => $legacyBlend->id,
        'lifecycle_status' => 'active',
    ]);

    app(ScentRecipeService::class)->syncActiveRecipeForScent($scent, [
        'recipe_components' => [
            ['component_type' => 'oil', 'base_oil_id' => $activeOil->id, 'percentage' => 100],
        ],
        'source_context' => 'test-precedence',
        'lifecycle_status' => 'active',
    ], true);

    $result = app(FlattenFormulaService::class)->flattenScent($scent, 120);

    expect($result['components'])->toHaveCount(1);
    expect($result['components'][0]['base_oil_id'])->toBe($activeOil->id);
    expect((float) $result['components'][0]['grams'])->toBe(120.0);
    expect($result['by_oil_id'])->not->toHaveKey((string) $legacyOil->id);
});
