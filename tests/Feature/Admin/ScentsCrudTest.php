<?php

use App\Livewire\Admin\Catalog\ScentsCrud;
use App\Models\Blend;
use App\Models\Scent;
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
        ->assertDontSee('Blend Recipe')
        ->assertDontSee('Oil count');

    $component
        ->set('create.is_blend', true)
        ->assertSee('Blend Recipe')
        ->assertSee('Oil count');
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
        ->assertSet('create.blend_oil_count', null);
});
