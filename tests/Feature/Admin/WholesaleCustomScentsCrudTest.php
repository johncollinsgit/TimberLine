<?php

use App\Livewire\Admin\Wholesale\CustomScentsCrud;
use App\Models\Scent;
use App\Models\WholesaleCustomScent;
use Livewire\Livewire;

test('wholesale custom scents admin shows an explicit empty state', function () {
    Livewire::test(CustomScentsCrud::class)
        ->assertSee('0 custom scents')
        ->assertSee('No wholesale custom scents yet. Add one manually or import the wholesale custom scent data first.');
});

test('wholesale custom scents admin lists saved rows', function () {
    $scent = Scent::query()->create([
        'name' => 'Walking on Sunshine',
        'display_name' => 'Walking on Sunshine',
        'is_active' => true,
    ]);

    WholesaleCustomScent::query()->create([
        'account_name' => 'Monroe 816',
        'custom_scent_name' => 'Walking on Sunshine (WOS)',
        'canonical_scent_id' => $scent->id,
        'active' => true,
    ]);

    Livewire::test(CustomScentsCrud::class)
        ->assertSee('1 custom scent')
        ->assertSee('Monroe 816')
        ->assertSee('Walking on Sunshine (WOS)')
        ->assertSee('Walking on Sunshine')
        ->assertSee('Mapped');
});
