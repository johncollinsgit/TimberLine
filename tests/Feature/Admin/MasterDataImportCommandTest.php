<?php

use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendComponent;
use App\Models\CandleClubScent;
use App\Models\OilAbbreviation;
use App\Models\Scent;
use App\Models\WholesaleCustomScent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function makeMasterDataZip(array $files): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'master-data-test-'.uniqid('', true).'.zip';
    $zip = new \ZipArchive();
    $opened = $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

    if ($opened !== true) {
        throw new RuntimeException('Could not create test zip.');
    }

    foreach ($files as $name => $contents) {
        $zip->addFromString("normalized_export_for_codex/{$name}", $contents);
    }

    $zip->close();

    return $path;
}

function makeMasterDataDirectory(array $files): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'master-data-dir-'.uniqid('', true);
    $root = $path.DIRECTORY_SEPARATOR.'normalized_export_for_codex';

    File::ensureDirectoryExists($root);

    foreach ($files as $name => $contents) {
        File::put($root.DIRECTORY_SEPARATOR.$name, $contents);
    }

    return $root;
}

test('master data import command imports in dependency order and is idempotent', function () {
    $zipPath = makeMasterDataZip([
        'scents_master.csv' => <<<CSV
scent_name,status,abbreviation,oil_list
Almond Cream Cake,active,ACC,Almond Macaron | Spiced Oat Milk
Amber Fog,discontinued,AF,Black Sea
CSV,
        'base_oils.csv' => <<<CSV
name
Almond Macaron
Spiced Oat Milk
CSV,
        'blends.csv' => <<<CSV
blend_name,blend_abbreviation
Almond Cream Cake,
CSV,
        'blend_components.csv' => <<<CSV
blend_name,blend_abbreviation,base_oil_name,ratio_weight
Almond Cream Cake,,Almond Macaron,1
Almond Cream Cake,,Missing Oil,1
CSV,
        'scent_recipes_pour_room.csv' => <<<CSV
Scent Name,Oil Name,Abbreviations
Almond Cream Cake,Almond Cream Cake Blend,ACC
Amber Fog,Black Sea,AF
CSV,
    ]);

    try {
        $exitCode = Artisan::call('master-data:import', [
            '--zip' => $zipPath,
            '--upsert' => true,
        ]);

        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('scents: inserted=');
        expect($output)->toContain('base_oils: inserted=2 updated=0 skipped=0');
        expect($output)->toContain('blends: inserted=1 updated=0 skipped=0');
        expect($output)->toContain('blend_components: inserted=1 updated=0 skipped=1');
        expect($output)->toContain("Missing FK for blend_components: blend='Almond Cream Cake' base_oil='Missing Oil'");

        expect(Scent::query()->whereIn('name', ['Almond Cream Cake', 'Amber Fog'])->count())->toBe(2);
        expect(BaseOil::query()->count())->toBe(2);
        expect(Blend::query()->count())->toBe(1);
        expect(BlendComponent::query()->count())->toBe(1);
        expect(OilAbbreviation::query()->whereIn('name', ['Almond Cream Cake Blend', 'Black Sea'])->count())->toBe(2);

        $blend = Blend::query()->where('name', 'Almond Cream Cake')->firstOrFail();
        $scent = Scent::query()->where('name', 'Almond Cream Cake')->firstOrFail();

        expect((int) ($scent->oil_blend_id ?? 0))->toBe((int) $blend->id);

        $exitCode = Artisan::call('master-data:import', [
            '--zip' => $zipPath,
            '--upsert' => true,
        ]);

        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('scents: inserted=0 updated=0 skipped=2');
        expect($output)->toContain('base_oils: inserted=0 updated=0 skipped=2');
        expect($output)->toContain('blends: inserted=0 updated=0 skipped=1');
        expect($output)->toContain('blend_components: inserted=0 updated=0 skipped=2');

        expect(Scent::query()->whereIn('name', ['Almond Cream Cake', 'Amber Fog'])->count())->toBe(2);
        expect(BaseOil::query()->count())->toBe(2);
        expect(Blend::query()->count())->toBe(1);
        expect(BlendComponent::query()->count())->toBe(1);
    } finally {
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }
    }
});

test('master data import command supports extracted directories and imports reference tables idempotently', function () {
    $canonical = Scent::query()->create([
        'name' => 'Walking on Sunshine',
        'display_name' => 'Walking on Sunshine',
        'abbreviation' => 'WOS',
        'is_active' => true,
    ]);

    $retiredCanonical = Scent::query()->create([
        'name' => 'Sage Pomegranate',
        'display_name' => 'Sage Pomegranate',
        'is_active' => true,
    ]);

    $directory = makeMasterDataDirectory([
        'candle_club_scent_recipes.csv' => <<<CSV
month,scent_name,oil_1,oil_2,abbreviations,additional_notes,unnamed_6,unnamed_7,unnamed_8
2024-07-01 00:00:00,Walking on Sunshine (WOS),Citrus Agave 1,Blood Orange 1,WOS,,,,
2021,Rose Champagne,Love Spell (1),Rose Petal Gelato (1),,,,,
CSV,
        'wholesale_custom_scents_sheet.csv' => <<<CSV
Scent Name,Oil #1,Oil #2,Oil #3,Total Oils,Abbreviation,Wholesale Account Name
Walking on Sunshine (WOS),Citrus Agave 1,Blood Orange 1,,2,WOS,Monroe 816
CSV,
        'retired_wholesale_custom_scents_sheet.csv' => <<<CSV
Scent Name,Oil #1,Oil #2,Total Oils,Abbreviation,Wholesale Account Name,Notes
Sage Pomegranate,Sage Pomegranate,,1,,Persnickety Plum,Wholesale account closed.
CSV,
    ]);

    try {
        $exitCode = Artisan::call('master-data:import', [
            '--dir' => $directory,
            '--upsert' => true,
        ]);

        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('candle_club_scents: inserted=1 updated=0 skipped=1');
        expect($output)->toContain('candle_club_scents.scent_rows: inserted=1 updated=0 skipped=1');
        expect($output)->toContain('wholesale_custom_scents.active: inserted=1 updated=0 skipped=0');
        expect($output)->toContain('wholesale_custom_scents.retired: inserted=1 updated=0 skipped=0');
        expect($output)->toContain("Skipped candle_club_scent_recipes row with ambiguous month value '2021'");

        $candleClub = CandleClubScent::query()->with('scent')->firstOrFail();
        expect((int) $candleClub->month)->toBe(7);
        expect((int) $candleClub->year)->toBe(2024);
        expect((string) $candleClub->scent->display_name)->toBe('July 2024 Candle Club — Walking on Sunshine');
        expect((string) $candleClub->scent->oil_reference_name)->toBe('Citrus Agave 1 | Blood Orange 1');
        expect((bool) $candleClub->scent->is_candle_club)->toBeTrue();

        $activeWholesale = WholesaleCustomScent::query()
            ->where('account_name', 'Monroe 816')
            ->where('custom_scent_name', 'Walking on Sunshine (WOS)')
            ->firstOrFail();

        expect((int) ($activeWholesale->canonical_scent_id ?? 0))->toBe((int) $canonical->id);
        expect((bool) $activeWholesale->active)->toBeTrue();

        $retiredWholesale = WholesaleCustomScent::query()
            ->where('account_name', 'Persnickety Plum')
            ->where('custom_scent_name', 'Sage Pomegranate')
            ->firstOrFail();

        expect((int) ($retiredWholesale->canonical_scent_id ?? 0))->toBe((int) $retiredCanonical->id);
        expect((bool) $retiredWholesale->active)->toBeFalse();
        expect((string) $retiredWholesale->notes)->toBe('Wholesale account closed.');

        $exitCode = Artisan::call('master-data:import', [
            '--dir' => $directory,
            '--upsert' => true,
        ]);

        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('candle_club_scents: inserted=0 updated=0 skipped=2');
        expect($output)->toContain('candle_club_scents.scent_rows: inserted=0 updated=0 skipped=2');
        expect($output)->toContain('wholesale_custom_scents.active: inserted=0 updated=0 skipped=1');
        expect($output)->toContain('wholesale_custom_scents.retired: inserted=0 updated=0 skipped=1');
        expect(CandleClubScent::query()->count())->toBe(1);
        expect(WholesaleCustomScent::query()->count())->toBe(2);
    } finally {
        File::deleteDirectory(dirname($directory));
    }
});

test('master data import command resolves configured aliases before linking recipes', function () {
    $zipPath = makeMasterDataZip([
        'scents_master.csv' => <<<CSV
scent_name,status,abbreviation,oil_list
Pumpkin Chai,active,PC,
CSV,
        'blends.csv' => <<<CSV
blend_name,blend_abbreviation
Orange Sandalwood,
CSV,
        'scent_recipes_pour_room.csv' => <<<CSV
Scent Name,Oil Name,Abbreviations
Pumpin Chai,Orange Sanalwood Blend,PC
CSV,
    ]);

    try {
        $exitCode = Artisan::call('master-data:import', [
            '--zip' => $zipPath,
            '--upsert' => true,
        ]);

        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('Missing FK for scent recipe link');
        expect($output)->toContain('oil_abbreviations: inserted=1 updated=0 skipped=0');
        expect($output)->toContain('scents.oil_blend_id: inserted=0 updated=1 skipped=0');

        $scent = Scent::query()->where('name', 'Pumpkin Chai')->firstOrFail();
        $blend = Blend::query()->where('name', 'Orange Sandalwood')->firstOrFail();

        expect((int) ($scent->oil_blend_id ?? 0))->toBe((int) $blend->id);
        expect(OilAbbreviation::query()
            ->where('name', 'Orange Sandalwood Blend')
            ->exists())->toBeTrue();
    } finally {
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }
    }
});

test('master data import command imports collections and seasonal scents idempotently', function () {
    $zipPath = makeMasterDataZip([
        'scents_master.csv' => <<<CSV
scent_name,status,abbreviation,oil_list
Pumpkin Chai,active,PC,
CSV,
        'collections_long.csv' => <<<CSV
collection_name
Fall Favorites
Fall Favorites
Winter Warmers
CSV,
        'seasonal_scents.csv' => <<<CSV
scent_name,season
Pumpkin Chai,fall
Pumpkin Chai,fall
CSV,
    ]);

    try {
        $exitCode = Artisan::call('master-data:import', [
            '--zip' => $zipPath,
            '--upsert' => true,
        ]);

        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('collections: inserted=2 updated=0 skipped=1');
        expect($output)->toContain('seasonal_scents: inserted=1 updated=0 skipped=1');
        expect($output)->not->toContain('Ignored CSV with no importer mapping: collections_long.csv');
        expect($output)->not->toContain('Ignored CSV with no importer mapping: seasonal_scents.csv');
        expect(DB::table('collections')->count())->toBe(2);
        expect(DB::table('seasonal_scents')->count())->toBe(1);

        $seasonal = DB::table('seasonal_scents')->first();
        expect((int) ($seasonal->scent_id ?? 0))->toBe((int) Scent::query()->where('name', 'Pumpkin Chai')->value('id'));
        expect((string) ($seasonal->season ?? ''))->toBe('Fall');

        $exitCode = Artisan::call('master-data:import', [
            '--zip' => $zipPath,
            '--upsert' => true,
        ]);

        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('collections: inserted=0 updated=0 skipped=3');
        expect($output)->toContain('seasonal_scents: inserted=0 updated=0 skipped=2');
        expect(DB::table('collections')->count())->toBe(2);
        expect(DB::table('seasonal_scents')->count())->toBe(1);
    } finally {
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }
    }
});
