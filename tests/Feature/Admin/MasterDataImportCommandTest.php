<?php

use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendComponent;
use App\Models\OilAbbreviation;
use App\Models\Scent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

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
