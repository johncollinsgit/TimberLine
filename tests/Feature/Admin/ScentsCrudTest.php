<?php

use App\Livewire\Admin\Catalog\ScentsCrud;
use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendComponent;
use App\Models\Scent;
use App\Models\WholesaleCustomScent;
use Livewire\Livewire;

test('open create redirects to scent wizard', function () {
    $expectedWizardUrl = route('admin.scent-wizard', [
        'source_context' => 'catalog',
        'return_to' => route('admin.index', ['tab' => 'catalog']),
    ]);

    Livewire::test(ScentsCrud::class)
        ->call('openCreate')
        ->assertRedirect($expectedWizardUrl);
});

test('legacy create action redirects to scent wizard', function () {
    $expectedWizardUrl = route('admin.scent-wizard', [
        'source_context' => 'catalog',
        'return_to' => route('admin.index', ['tab' => 'catalog']),
    ]);

    Livewire::test(ScentsCrud::class)
        ->call('create')
        ->assertRedirect($expectedWizardUrl);
});

test('catalog scents view points users to the wizard for new scent creation', function () {
    $expectedWizardUrl = route('admin.scent-wizard', [
        'source_context' => 'catalog',
        'return_to' => route('admin.index', ['tab' => 'catalog']),
    ]);

    Livewire::test(ScentsCrud::class)
        ->assertSee('New Scent Wizard')
        ->assertSee($expectedWizardUrl);
});

test('unchecking blend clears blend fields on edit payload', function () {
    $blend = Blend::query()->create([
        'name' => 'Citrus Blend',
        'is_blend' => true,
    ]);

    $scent = Scent::query()->create([
        'name' => 'citrus woods',
        'display_name' => 'Citrus Woods',
        'is_blend' => true,
        'oil_blend_id' => $blend->id,
        'blend_oil_count' => 3,
        'is_active' => true,
    ]);

    Livewire::test(ScentsCrud::class)
        ->call('openEdit', $scent->id)
        ->set('edit.is_blend', false)
        ->assertSet('edit.oil_blend_id', null)
        ->assertSet('edit.blend_oil_count', null)
        ->assertSet('edit.recipe_components', []);
});

test('apply selected wholesale source hydrates canonical and recipe sources for edit', function () {
    $editableScent = Scent::query()->create([
        'name' => 'pending custom scent',
        'display_name' => 'Pending Custom Scent',
        'is_active' => true,
    ]);

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
        ->call('openEdit', $editableScent->id)
        ->set('edit.is_blend', true)
        ->set('edit.source_wholesale_custom_scent_id', $source->id)
        ->call('applySelectedWholesaleSource', 'edit')
        ->assertSet('edit.canonical_scent_id', $canonical->id)
        ->assertSet('edit.recipe_components.0.type', 'blend');
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
