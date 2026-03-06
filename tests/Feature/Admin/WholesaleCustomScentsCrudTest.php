<?php

use App\Livewire\Admin\Wholesale\CustomScentsCrud;
use App\Livewire\Components\ScentCombobox;
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

test('scent combobox can search by display name and include inactive scents when enabled', function () {
    Scent::query()->create([
        'name' => 'march-2026-drop',
        'display_name' => 'Vintage Amber',
        'is_wholesale_custom' => true,
        'is_active' => false,
    ]);

    Livewire::test(ScentCombobox::class, [
        'emitKey' => 'wholesale-edit',
        'allowWholesaleCustom' => true,
        'includeInactive' => true,
    ])
        ->set('query', 'vintage amber')
        ->assertSee('Vintage Amber')
        ->assertSee('Inactive');
});

test('wholesale custom scents edit can map canonical scent from selection event', function () {
    $canonical = Scent::query()->create([
        'name' => 'vintage amber',
        'display_name' => 'Vintage Amber',
        'is_active' => true,
    ]);

    $row = WholesaleCustomScent::query()->create([
        'account_name' => 'ERIN NUTZ',
        'custom_scent_name' => 'Custom Scent',
        'canonical_scent_id' => null,
        'active' => true,
    ]);

    Livewire::test(CustomScentsCrud::class)
        ->call('openEdit', $row->id)
        ->dispatch('scentSelected', key: 'wholesale-edit', scentId: $canonical->id, scentName: 'Vintage Amber')
        ->assertSet('edit.canonical_scent_id', $canonical->id)
        ->call('save')
        ->assertSet('showEdit', false);

    expect((int) WholesaleCustomScent::query()->findOrFail($row->id)->canonical_scent_id)->toBe((int) $canonical->id);
});
