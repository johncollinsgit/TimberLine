<?php

namespace Database\Seeders;

use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendComponent;
use App\Models\Scent;
use Illuminate\Database\Seeder;

class BlendRecipeSeeder extends Seeder
{
    public function run(): void
    {
        $recipes = [
            [
                'blend' => 'Almond Cream Cake',
                'abbreviation' => 'ACC',
                'oils' => [
                    ['name' => 'Almond Macaron', 'weight' => 1],
                    ['name' => 'Spiced Oat Milk', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Autumn No. 9',
                'abbreviation' => 'A9',
                'oils' => [
                    ['name' => 'Cashmere Pumpkin', 'weight' => 1],
                    ['name' => 'Vanilla Cake Pop', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Autumn Leaves',
                'abbreviation' => 'AL',
                'oils' => [
                    ['name' => 'Apples and Maple Bourbon', 'weight' => 1],
                    ['name' => 'Caribbean Teakwood', 'weight' => 1],
                    ['name' => 'Orange Pomander', 'weight' => 1],
                    ['name' => 'Pomegranate Cider', 'weight' => 1],
                    ['name' => 'Pumpkin Souffle', 'weight' => 1],
                    ['name' => 'Fireside', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Christmas Garland',
                'abbreviation' => 'CG',
                'oils' => [
                    ['name' => 'Blue Spruce', 'weight' => 1],
                    ['name' => 'Pomegranate Cider', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Cinna Bakery',
                'abbreviation' => 'CB',
                'oils' => [
                    ['name' => "Hansel and Gretel's House", 'weight' => 1],
                    ['name' => 'Cinnamon and Vanilla', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Clean Shaven',
                'abbreviation' => 'CSH',
                'oils' => [
                    ['name' => 'Fog and Fern', 'weight' => 1],
                    ['name' => 'Black Currant Absinthe', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Coconut Glow',
                'abbreviation' => 'CGL',
                'oils' => [
                    ['name' => 'Toasted Coconut', 'weight' => 1],
                    ['name' => 'Golden Hour', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Cozy Cabin',
                'abbreviation' => 'CC',
                'oils' => [
                    ['name' => 'Cinnamon and Vanilla', 'weight' => 2],
                    ['name' => 'Fireside', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Cranberry Apple',
                'abbreviation' => 'CA',
                'oils' => [
                    ['name' => 'Cranberry Woods', 'weight' => 1],
                    ['name' => 'Macintosh Apple', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Fireside Flannel',
                'abbreviation' => 'FF',
                'oils' => [
                    ['name' => 'Cinnamon and Vanilla', 'weight' => 1],
                    ['name' => 'Fallen Leaves', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Holly Wreath',
                'abbreviation' => 'HW',
                'oils' => [
                    ['name' => 'Blue Spruce', 'weight' => 1],
                    ['name' => 'Cranberry Woods', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Midnight Sea',
                'abbreviation' => 'MS',
                'oils' => [
                    ['name' => 'Velvet Vanilla', 'weight' => 1],
                    ['name' => 'Black Sea', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Nightfall',
                'abbreviation' => 'NF',
                'oils' => [
                    ['name' => 'Orange Pomander', 'weight' => 1],
                    ['name' => 'Black Sea', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Orange Pomander',
                'abbreviation' => 'OP',
                'oils' => [
                    ['name' => 'Orange Pomander', 'weight' => 1],
                    ['name' => 'Blood Orange', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Orange Sandalwood',
                'abbreviation' => 'OS',
                'oils' => [
                    ['name' => 'Orange Pomander', 'weight' => 1],
                    ['name' => 'Sandalwood', 'weight' => 1],
                ],
            ],
            [
                'blend' => "Papa's Pipe",
                'abbreviation' => 'PP',
                'oils' => [
                    ['name' => 'Cedarwood Blanc', 'weight' => 1],
                    ['name' => 'Spiced Honey and Tonka', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Pumpkin Chai',
                'abbreviation' => 'PKCH',
                'oils' => [
                    ['name' => 'Toasted Pumpkin Spice', 'weight' => 1],
                    ['name' => 'Cinnamon and Vanilla', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Pumpkin Spice Latte',
                'abbreviation' => 'PSL',
                'oils' => [
                    ['name' => 'Pumpkin Spice Buttercream', 'weight' => 1],
                    ['name' => 'Coffeeshop', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Room Refresh',
                'abbreviation' => 'RR',
                'oils' => [
                    ['name' => 'Cinnamon Stick', 'weight' => 1],
                    ['name' => 'Citrus Agave', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Snow Day',
                'abbreviation' => 'SD',
                'oils' => [
                    ['name' => 'Nordic Night', 'weight' => 2],
                    ['name' => 'Fireside', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Thru Hike',
                'abbreviation' => 'TH',
                'oils' => [
                    ['name' => 'Blue Spruce', 'weight' => 3],
                    ['name' => 'Oakmoss and Amber', 'weight' => 2],
                    ['name' => 'Fireside', 'weight' => 1],
                ],
            ],
            [
                'blend' => 'Violet Spice',
                'abbreviation' => 'VS',
                'oils' => [
                    ['name' => 'Lavender', 'weight' => 1],
                    ['name' => 'Black Violet and Saffron', 'weight' => 1],
                ],
            ],
        ];

        foreach ($recipes as $recipe) {
            $blend = Blend::query()->firstOrCreate(
                ['name' => $recipe['blend']],
                ['is_blend' => true]
            );

            $blend->is_blend = true;
            $blend->save();

            // Ensure base oils exist and components are fresh
            $blend->components()->delete();

            foreach ($recipe['oils'] as $oil) {
                $baseOil = BaseOil::query()->firstOrCreate(
                    ['name' => $oil['name']],
                    ['active' => true]
                );

                BlendComponent::query()->create([
                    'blend_id' => $blend->id,
                    'base_oil_id' => $baseOil->id,
                    'ratio_weight' => (int) $oil['weight'],
                ]);
            }

            $scent = Scent::query()->get()->first(function (Scent $s) use ($recipe) {
                return Scent::normalizeName($s->name) === Scent::normalizeName($recipe['blend'])
                    || ($s->display_name && Scent::normalizeName($s->display_name) === Scent::normalizeName($recipe['blend']));
            });

            if ($scent) {
                $scent->is_blend = true;
                $scent->oil_blend_id = $blend->id;
                $scent->abbreviation = $recipe['abbreviation'] ?? $scent->abbreviation;
                $scent->oil_reference_name = $recipe['blend'];
                $scent->save();
            }
        }
    }
}
