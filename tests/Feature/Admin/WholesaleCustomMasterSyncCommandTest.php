<?php

use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendComponent;
use App\Models\Scent;
use App\Models\WholesaleCustomScent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function makeWholesaleMasterCsv(string $contents): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wholesale-master-'.uniqid('', true).'.csv';
    file_put_contents($path, $contents);
    return $path;
}

test('wholesale custom master sync can replace mappings and build blend-linked scent recipes', function () {
    $existing = WholesaleCustomScent::query()->create([
        'account_name' => 'Legacy Account',
        'custom_scent_name' => 'Legacy Scent',
        'canonical_scent_id' => null,
        'active' => true,
    ]);

    $cedar = BaseOil::query()->create(['name' => 'Cedar', 'active' => true]);
    $moss = BaseOil::query()->create(['name' => 'Moss', 'active' => true]);

    $thruHike = Blend::query()->create([
        'name' => 'Thru Hike',
        'is_blend' => true,
    ]);

    BlendComponent::query()->create([
        'blend_id' => $thruHike->id,
        'base_oil_id' => $cedar->id,
        'ratio_weight' => 2,
    ]);

    BlendComponent::query()->create([
        'blend_id' => $thruHike->id,
        'base_oil_id' => $moss->id,
        'ratio_weight' => 1,
    ]);

    $csvPath = makeWholesaleMasterCsv(<<<CSV
Wholesale Custom Scents,,,,,,
Scent Name,Oil #1,Oil #2,Oil #3,Total Oils,Abbreviation ,Wholesale Account Name
On the Trail (OTT) *new recipe,Thru Hike Blend,Patchouli Teakwood ,,2,OTT,Swamp Rabbit Cafe
CSV);

    try {
        $exit = Artisan::call('wholesale-custom:sync-master', [
            'csv' => $csvPath,
            '--replace' => true,
        ]);

        $output = Artisan::output();

        expect($exit)->toBe(0);
        expect($output)->toContain('rows_read=1');
        expect($output)->toContain('wholesale_custom_scents: inserted=1 updated=0 deleted=1');

        expect(WholesaleCustomScent::query()->whereKey($existing->id)->exists())->toBeFalse();

        $mapping = WholesaleCustomScent::query()
            ->where('account_name', 'Swamp Rabbit Cafe')
            ->where('custom_scent_name', 'On the Trail')
            ->firstOrFail();

        $scent = Scent::query()->findOrFail($mapping->canonical_scent_id);
        expect((bool) $scent->is_blend)->toBeTrue();
        expect((bool) $scent->is_wholesale_custom)->toBeTrue();
        expect((string) ($scent->abbreviation ?? ''))->toBe('OTT');

        $blend = Blend::query()->findOrFail((int) $scent->oil_blend_id);
        $components = BlendComponent::query()
            ->where('blend_id', $blend->id)
            ->get()
            ->mapWithKeys(function (BlendComponent $component): array {
                $name = BaseOil::query()->find($component->base_oil_id)?->name ?? '';
                return [$name => (int) $component->ratio_weight];
            })
            ->all();

        expect($components)->toMatchArray([
            'Cedar' => 2,
            'Moss' => 1,
            'Patchouli Teakwood' => 1,
        ]);
    } finally {
        if (is_file($csvPath)) {
            @unlink($csvPath);
        }
    }
});

