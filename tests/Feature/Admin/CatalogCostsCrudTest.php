<?php

use App\Livewire\Admin\Catalog\CostsCrud;
use App\Models\CatalogItemCost;
use App\Models\Scent;
use App\Models\Size;
use Livewire\Livewire;

test('admin catalog costs crud can create a cost rule', function () {
    $scent = Scent::query()->create([
        'name' => 'midnight orchard',
        'display_name' => 'Midnight Orchard',
        'is_active' => true,
    ]);

    $size = Size::query()->create([
        'code' => '8oz',
        'label' => '8oz',
        'retail_price' => 24,
        'wholesale_price' => 14,
        'is_active' => true,
    ]);

    Livewire::test(CostsCrud::class)
        ->call('openCreate')
        ->set('create.shopify_store_key', 'retail')
        ->set('create.shopify_variant_id', '4401')
        ->set('create.scent_id', (string) $scent->id)
        ->set('create.size_id', (string) $size->id)
        ->set('create.cost_amount', '9.75')
        ->set('create.currency_code', 'usd')
        ->call('create')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $cost = CatalogItemCost::query()->where('shopify_variant_id', 4401)->first();

    expect($cost)->not->toBeNull()
        ->and((float) $cost->cost_amount)->toBe(9.75)
        ->and($cost->currency_code)->toBe('USD')
        ->and($cost->scent_id)->toBe($scent->id)
        ->and($cost->size_id)->toBe($size->id);
});

test('admin catalog costs crud requires at least one matcher', function () {
    Livewire::test(CostsCrud::class)
        ->call('openCreate')
        ->set('create.cost_amount', '8.50')
        ->set('create.currency_code', 'USD')
        ->call('create')
        ->assertHasErrors(['create.shopify_variant_id']);
});

test('admin catalog costs crud can update an existing cost rule', function () {
    $cost = CatalogItemCost::factory()->create([
        'shopify_store_key' => 'retail',
        'shopify_variant_id' => 5555,
        'cost_amount' => 8.25,
    ]);

    Livewire::test(CostsCrud::class)
        ->call('openEdit', $cost->id)
        ->set('edit.shopify_variant_id', '7777')
        ->set('edit.cost_amount', '12.40')
        ->set('edit.currency_code', 'usd')
        ->set('edit.notes', 'Updated for spring run.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $cost->refresh();

    expect($cost->shopify_variant_id)->toBe(7777)
        ->and((float) $cost->cost_amount)->toBe(12.4)
        ->and($cost->currency_code)->toBe('USD')
        ->and($cost->notes)->toBe('Updated for spring run.');
});
