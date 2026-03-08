<?php

use App\Actions\ScentGovernance\CreateScentAction;
use App\Actions\ScentGovernance\UpdateScentAction;
use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendTemplateComponent;
use App\Models\Scent;
use App\Models\ScentRecipe;
use App\Models\ScentRecipeComponent;
use App\Services\ScentGovernance\ScentRecipeService;

it('creates a scent and writes an active recipe version', function () {
    $blend = Blend::query()->create([
        'name' => 'thru hike',
        'is_blend' => true,
    ]);

    $scent = app(CreateScentAction::class)->execute([
        'name' => 'on the trail',
        'display_name' => 'On The Trail',
        'is_blend' => true,
        'oil_blend_id' => $blend->id,
        'lifecycle_status' => 'draft',
        'source_context' => 'test-suite',
    ]);

    $scent->refresh();

    $activeRecipe = ScentRecipe::query()->where('scent_id', $scent->id)->where('is_active', true)->first();

    expect($activeRecipe)->not->toBeNull();
    expect((int) $scent->current_scent_recipe_id)->toBe((int) $activeRecipe?->id);
    expect($activeRecipe?->version)->toBe(1);

    $component = ScentRecipeComponent::query()->where('scent_recipe_id', $activeRecipe->id)->first();
    expect($component?->component_type)->toBe(ScentRecipeComponent::TYPE_BLEND_TEMPLATE);
    expect((int) $component?->blend_template_id)->toBe($blend->id);
});

it('keeps a single active recipe and versions when recipe input changes', function () {
    $blendA = Blend::query()->create(['name' => 'blend a', 'is_blend' => true]);
    $blendB = Blend::query()->create(['name' => 'blend b', 'is_blend' => true]);

    $scent = app(CreateScentAction::class)->execute([
        'name' => 'vintage amber recipe test',
        'display_name' => 'Vintage Amber Recipe Test',
        'is_blend' => true,
        'oil_blend_id' => $blendA->id,
        'lifecycle_status' => 'active',
    ]);

    $scent = app(UpdateScentAction::class)->execute($scent, [
        'name' => 'vintage amber recipe test',
        'display_name' => 'Vintage Amber Recipe Test',
        'is_blend' => true,
        'oil_blend_id' => $blendB->id,
        'lifecycle_status' => 'active',
    ]);

    $recipes = ScentRecipe::query()->where('scent_id', $scent->id)->orderBy('version')->get();
    expect($recipes)->toHaveCount(2);
    expect($recipes->where('is_active', true))->toHaveCount(1);
    expect($recipes->last()->version)->toBe(2);

    $activeRecipe = $recipes->firstWhere('is_active', true);
    $activeComponent = $activeRecipe?->components()->first();
    expect((int) ($activeComponent?->blend_template_id ?? 0))->toBe($blendB->id);
    expect((int) ($scent->fresh()->current_scent_recipe_id ?? 0))->toBe((int) ($activeRecipe?->id ?? 0));
});

it('persists oil-based recipe component when oil ref matches a base oil', function () {
    $oil = BaseOil::query()->create([
        'name' => 'Patchouli Teakwood',
    ]);

    $scent = app(CreateScentAction::class)->execute([
        'name' => 'patchouli single oil scent',
        'display_name' => 'Patchouli Single Oil Scent',
        'is_blend' => false,
        'oil_reference_name' => 'Patchouli Teakwood',
        'lifecycle_status' => 'active',
    ]);

    $recipe = ScentRecipe::query()->where('scent_id', $scent->id)->where('is_active', true)->firstOrFail();
    $component = ScentRecipeComponent::query()->where('scent_recipe_id', $recipe->id)->firstOrFail();

    expect($component->component_type)->toBe(ScentRecipeComponent::TYPE_OIL);
    expect((int) $component->base_oil_id)->toBe($oil->id);
    expect((float) $component->percentage)->toBe(100.0);
});

it('supports nested blend template component rows', function () {
    $parent = Blend::query()->create(['name' => 'parent blend template', 'is_blend' => true]);
    $child = Blend::query()->create(['name' => 'child blend template', 'is_blend' => true]);

    BlendTemplateComponent::query()->create([
        'blend_id' => $parent->id,
        'component_type' => BlendTemplateComponent::TYPE_BLEND_TEMPLATE,
        'blend_template_id' => $child->id,
        'ratio_weight' => 2,
        'percentage' => 50,
        'sort_order' => 0,
    ]);

    $saved = BlendTemplateComponent::query()->where('blend_id', $parent->id)->firstOrFail();

    expect($saved->component_type)->toBe(BlendTemplateComponent::TYPE_BLEND_TEMPLATE);
    expect((int) $saved->blend_template_id)->toBe($child->id);
});

it('can backfill recipe truth from legacy scent fields through service shim', function () {
    $blend = Blend::query()->create(['name' => 'legacy blend target', 'is_blend' => true]);

    $scent = Scent::query()->create([
        'name' => 'legacy bridge scent',
        'display_name' => 'Legacy Bridge Scent',
        'is_blend' => true,
        'oil_blend_id' => $blend->id,
        'is_active' => true,
    ]);

    $recipe = app(ScentRecipeService::class)->syncActiveRecipeForScent($scent, [], true);

    expect($recipe)->not->toBeNull();
    expect((int) ($scent->fresh()->current_scent_recipe_id ?? 0))->toBe((int) ($recipe?->id ?? 0));
    expect($recipe?->components)->toHaveCount(1);
    expect((int) ($recipe?->components->first()?->blend_template_id ?? 0))->toBe($blend->id);
});
