<?php

use App\Livewire\Admin\Catalog\ScentsCrud;
use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendComponent;
use App\Models\Scent;
use App\Models\WholesaleCustomScent;
use Livewire\Livewire;

test('can create a non-blend scent without blend fields', function () {
    Livewire::test(ScentsCrud::class)
        ->call('openCreate')
        ->set('create.name', 'Glazed Lemon Cake')
        ->set('create.display_name', 'Glazed Lemon Cake')
        ->set('create.abbreviation', 'GLC')
        ->set('create.oil_reference_name', 'Lemon Pound Cake')
        ->set('create.is_blend', false)
        ->set('create.oil_blend_id', '')
        ->set('create.blend_oil_count', '')
        ->call('create')
        ->assertDispatched('toast');

    $scent = Scent::query()->where('name', 'glazed lemon cake')->first();
    expect($scent)->not->toBeNull();
    expect((string) ($scent->display_name ?? ''))->toBe('Glazed Lemon Cake');
    expect((string) ($scent->abbreviation ?? ''))->toBe('GLC');
    expect((string) ($scent->oil_reference_name ?? ''))->toBe('Lemon Pound Cake');
    expect((bool) $scent->is_blend)->toBeFalse();
    expect($scent->oil_blend_id)->toBeNull();
    expect($scent->blend_oil_count)->toBeNull();
});

test('create form only shows blend fields when blend is enabled', function () {
    $component = Livewire::test(ScentsCrud::class)
        ->call('openCreate')
        ->assertDontSee('Blend Mapping')
        ->assertDontSee('Recipe Sources');

    $component
        ->set('create.is_blend', true)
        ->assertSee('Blend Mapping')
        ->assertSee('Recipe Sources');
});

test('unchecking blend clears blend fields', function () {
    $blend = Blend::query()->create([
        'name' => 'Citrus Blend',
        'is_blend' => true,
    ]);

    Livewire::test(ScentsCrud::class)
        ->call('openCreate')
        ->set('create.is_blend', true)
        ->set('create.oil_blend_id', $blend->id)
        ->set('create.blend_oil_count', 3)
        ->set('create.is_blend', false)
        ->assertSet('create.oil_blend_id', null)
        ->assertSet('create.blend_oil_count', null)
        ->assertSet('create.recipe_components', []);
});

test('duplicate name shows create name error', function () {
    Scent::query()->create([
        'name' => 'honeysuckle',
        'display_name' => 'Honeysuckle',
        'is_active' => true,
    ]);

    Livewire::test(ScentsCrud::class)
        ->call('openCreate')
        ->set('create.name', 'Honeysuckle')
        ->set('create.display_name', 'Honeysuckle 2')
        ->set('create.abbreviation', 'HSX')
        ->call('create')
        ->assertHasErrors(['create.name']);
});

test('duplicate abbreviation shows create abbreviation error', function () {
    Scent::query()->create([
        'name' => 'mint tea',
        'display_name' => 'Mint Tea',
        'abbreviation' => 'MT',
        'is_active' => true,
    ]);

    Livewire::test(ScentsCrud::class)
        ->call('openCreate')
        ->set('create.name', 'Vanilla Mint')
        ->set('create.display_name', 'Vanilla Mint')
        ->set('create.abbreviation', 'MT')
        ->call('create')
        ->assertHasErrors(['create.abbreviation']);
});

test('can create blend scent by creating inline blend from recipe sources', function () {
    $lavender = BaseOil::query()->create([
        'name' => 'Lavender',
        'grams_on_hand' => 0,
        'reorder_threshold' => 0,
        'jug_size_grams' => 2263,
        'active' => true,
    ]);
    $gingersnap = BaseOil::query()->create([
        'name' => 'Gingersnap',
        'grams_on_hand' => 0,
        'reorder_threshold' => 0,
        'jug_size_grams' => 2263,
        'active' => true,
    ]);

    Livewire::test(ScentsCrud::class)
        ->call('openCreate')
        ->set('create.name', 'lavender snap')
        ->set('create.display_name', 'Lavender Snap')
        ->set('create.is_blend', true)
        ->set('create.create_inline_blend', true)
        ->set('create.inline_blend_name', 'Lavender Snap Blend')
        ->set('create.recipe_components', [
            ['type' => 'base_oil', 'id' => $lavender->id, 'ratio_weight' => 2],
            ['type' => 'base_oil', 'id' => $gingersnap->id, 'ratio_weight' => 1],
        ])
        ->call('create')
        ->assertHasNoErrors();

    $scent = Scent::query()->where('name', 'lavender snap')->first();
    expect($scent)->not->toBeNull();
    expect((bool) $scent->is_blend)->toBeTrue();
    expect($scent->oil_blend_id)->not->toBeNull();

    $blend = Blend::query()->find($scent->oil_blend_id);
    expect($blend)->not->toBeNull();
    expect($blend->name)->toBe('Lavender Snap Blend');
    expect(BlendComponent::query()->where('blend_id', $blend->id)->count())->toBe(2);
});

test('apply selected wholesale source hydrates canonical and recipe sources', function () {
    $canonical = Scent::query()->create([
        'name' => 'vintage amber',
        'display_name' => 'Vintage Amber',
        'is_active' => true,
    ]);
    $patchouli = BaseOil::query()->create([
        'name' => 'Patchouli',
        'grams_on_hand' => 0,
        'reorder_threshold' => 0,
        'jug_size_grams' => 2263,
        'active' => true,
    ]);
    $trailBlend = Blend::query()->create([
        'name' => 'Thru Hike',
        'is_blend' => true,
    ]);
    BlendComponent::query()->create([
        'blend_id' => $trailBlend->id,
        'base_oil_id' => $patchouli->id,
        'ratio_weight' => 1,
    ]);

    $source = WholesaleCustomScent::query()->create([
        'account_name' => 'Circa',
        'custom_scent_name' => 'On the Trail',
        'oil_1' => 'Thru Hike',
        'oil_2' => 'Patchouli',
        'total_oils' => 2,
        'canonical_scent_id' => $canonical->id,
        'active' => true,
    ]);

    Livewire::test(ScentsCrud::class)
        ->call('openCreate')
        ->set('create.is_blend', true)
        ->set('create.source_wholesale_custom_scent_id', $source->id)
        ->call('applySelectedWholesaleSource', 'create')
        ->assertSet('create.canonical_scent_id', $canonical->id)
        ->assertSet('create.recipe_components.0.type', 'blend');
});

test('can update a scent field with inline editing', function () {
    $seed = (string) random_int(1000, 9999);
    $scent = Scent::query()->create([
        'name' => 'inline honeysuckle ' . $seed,
        'display_name' => 'Inline Honeysuckle ' . $seed,
        'abbreviation' => 'IH' . $seed,
        'is_active' => true,
    ]);

    $component = Livewire::test(ScentsCrud::class)
        ->call('startInlineEdit', $scent->id, 'display_name')
        ->set('inlineValue', 'Honeysuckle Updated')
        ->call('commitInlineEdit');

    $inlineErrors = $component->get('inlineErrors');
    expect($inlineErrors)->not->toHaveKey($scent->id . ':display_name');
    expect((string) $scent->fresh()->display_name)->toBe('Honeysuckle Updated');
});

test('inline editing shows duplicate errors instead of silently failing', function () {
    Scent::query()->create([
        'name' => 'mint tea',
        'display_name' => 'Mint Tea',
        'abbreviation' => 'MT',
        'is_active' => true,
    ]);

    $target = Scent::query()->create([
        'name' => 'vanilla mint',
        'display_name' => 'Vanilla Mint',
        'abbreviation' => 'VM',
        'is_active' => true,
    ]);

    $component = Livewire::test(ScentsCrud::class)
        ->call('startInlineEdit', $target->id, 'abbreviation')
        ->set('inlineValue', 'MT')
        ->call('commitInlineEdit');

    $inlineErrors = $component->get('inlineErrors');
    expect($inlineErrors)->toHaveKey($target->id . ':abbreviation');
    expect($inlineErrors[$target->id . ':abbreviation'])->toContain('abbreviation');
    expect((string) $target->fresh()->abbreviation)->toBe('VM');
});
