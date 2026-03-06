<?php

use App\Livewire\Admin\Wholesale\CustomScentsCrud;
use App\Livewire\Components\ScentCombobox;
use App\Models\Scent;
use App\Models\WholesaleCustomScent;
use Illuminate\Http\UploadedFile;
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

test('wholesale custom scents edit can map canonical scent via bound field', function () {
    $canonical = Scent::query()->create([
        'name' => 'forest amber',
        'display_name' => 'Forest Amber',
        'is_active' => true,
    ]);

    $row = WholesaleCustomScent::query()->create([
        'account_name' => 'TRAIL HOUSE',
        'custom_scent_name' => 'Custom Scent',
        'canonical_scent_id' => null,
        'active' => true,
    ]);

    Livewire::test(CustomScentsCrud::class)
        ->call('openEdit', $row->id)
        ->set('edit.canonical_scent_id', $canonical->id)
        ->call('save')
        ->assertSet('showEdit', false);

    expect((int) WholesaleCustomScent::query()->findOrFail($row->id)->canonical_scent_id)->toBe((int) $canonical->id);
});

test('wholesale custom admin can upload master csv and replace mappings in one action', function () {
    WholesaleCustomScent::query()->create([
        'account_name' => 'Legacy Account',
        'custom_scent_name' => 'Legacy Scent',
        'canonical_scent_id' => null,
        'active' => true,
    ]);

    $csv = <<<CSV
Wholesale Custom Scents,,,,,,
Scent Name,Oil #1,Oil #2,Oil #3,Total Oils,Abbreviation ,Wholesale Account Name
Vintage Amber,Egyptian Amber 2,Lavender 2,Caribbean Teakwood 1,3,,Circa
CSV;

    $file = UploadedFile::fake()->createWithContent('wholesale-custom-master.csv', $csv);

    Livewire::test(CustomScentsCrud::class)
        ->set('masterCsvUpload', $file)
        ->call('syncMasterCsv');

    expect(WholesaleCustomScent::query()
        ->where('account_name', 'Legacy Account')
        ->exists())->toBeFalse();

    $mapping = WholesaleCustomScent::query()
        ->where('account_name', 'Circa')
        ->where('custom_scent_name', 'Vintage Amber')
        ->first();

    expect($mapping)->not->toBeNull();
    expect((int) ($mapping->canonical_scent_id ?? 0))->toBeGreaterThan(0);

    $scent = Scent::query()->findOrFail((int) $mapping->canonical_scent_id);
    expect((bool) $scent->is_wholesale_custom)->toBeTrue();
    expect((bool) $scent->is_blend)->toBeTrue();
});
