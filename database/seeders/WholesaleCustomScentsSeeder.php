<?php

namespace Database\Seeders;

use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendComponent;
use App\Models\Scent;
use App\Models\WholesaleCustomScent;
use Illuminate\Database\Seeder;

class WholesaleCustomScentsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'account' => 'Swamp Rabbit Cafe',
                'custom_scent' => 'On the Trail (OTT)',
                'oils' => [
                    ['name' => 'Thru Hike Blend', 'weight' => 1],
                    ['name' => 'Patchouli Teakwood', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Monroe 816',
                'custom_scent' => 'Walking on Sunshine (WOS)',
                'oils' => [
                    ['name' => 'Citrus Agave', 'weight' => 1],
                    ['name' => 'Blood Orange', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Monroe 816',
                'custom_scent' => 'Country Roads (CR)',
                'oils' => [
                    ['name' => 'Thru Hike Blend', 'weight' => 1],
                    ['name' => 'Egyptian Amber', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Monroe 816',
                'custom_scent' => 'Home',
                'oils' => [
                    ['name' => 'Coconut Glow Blend', 'weight' => 2],
                    ['name' => 'Sandalwood', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Circa',
                'custom_scent' => 'Vintage Amber',
                'oils' => [
                    ['name' => 'Egyptian Amber', 'weight' => 2],
                    ['name' => 'Lavender', 'weight' => 2],
                    ['name' => 'Caribbean Teakwood', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Circa',
                'custom_scent' => 'Warm & Cozy',
                'oils' => [
                    ['name' => 'Clove Essential Oil', 'weight' => 1],
                    ['name' => 'Blue Spruce', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Mind and Spirit Alchemy',
                'custom_scent' => 'Isis',
                'oils' => [
                    ['name' => "Hansel and Gretel's House", 'weight' => 1],
                ],
            ],
            [
                'account' => 'Mind and Spirit Alchemy',
                'custom_scent' => 'Blessed Mother',
                'oils' => [
                    ['name' => 'Very Vanilla', 'weight' => 1],
                    ['name' => 'Magnolia Blossom', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Mind and Spirit Alchemy',
                'custom_scent' => 'Raphael',
                'oils' => [
                    ['name' => 'Lavender', 'weight' => 1],
                    ['name' => 'Sandalwood', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Mind and Spirit Alchemy',
                'custom_scent' => 'Chamuel',
                'oils' => [
                    ['name' => 'Lavender', 'weight' => 2],
                    ['name' => 'Mint Mojito', 'weight' => 1],
                    ['name' => 'Very Vanilla', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Mind and Spirit Alchemy',
                'custom_scent' => 'Gabriel',
                'oils' => [
                    ['name' => 'Cinnamon and Vanilla', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Mind and Spirit Alchemy',
                'custom_scent' => 'Jeremiel',
                'oils' => [
                    ['name' => 'Cranberry Woods', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Mind and Spirit Alchemy',
                'custom_scent' => 'Lord Melchizedek',
                'oils' => [
                    ['name' => 'Lavender', 'weight' => 1],
                    ['name' => 'Patchouli', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Mind and Spirit Alchemy',
                'custom_scent' => 'Padre Pio',
                'oils' => [
                    ['name' => 'Sandalwood', 'weight' => 1],
                    ['name' => 'Lava Rock', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Mind and Spirit Alchemy',
                'custom_scent' => 'Michael',
                'oils' => [
                    ['name' => 'Patchouli', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Mind and Spirit Alchemy',
                'custom_scent' => 'Kuan Yin',
                'oils' => [
                    ['name' => 'French Lilac', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Mind and Spirit Alchemy',
                'custom_scent' => 'Jophiel',
                'oils' => [
                    ['name' => 'Green Tea and Lemongrass', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Speckled Hen Farms',
                'custom_scent' => 'Chicken Scratch',
                'oils' => [
                    ['name' => 'Rain Water', 'weight' => 1],
                    ['name' => 'Blue Spruce', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Lofts at Woodside',
                'custom_scent' => 'Woodside 1902',
                'oils' => [
                    ['name' => 'Black Sea', 'weight' => 1],
                    ['name' => 'Fireside', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Bright Skincare',
                'custom_scent' => 'Spa Day',
                'oils' => [
                    ['name' => 'Peppermint and Eucalyptus', 'weight' => 1],
                    ['name' => 'White Sage and Lavender', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Western Lane',
                'custom_scent' => 'The Caves',
                'oils' => [
                    ['name' => 'Frosted Juniper', 'weight' => 1],
                    ['name' => 'Orange Blossom', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Western Lane',
                'custom_scent' => 'Hocking Hills',
                'oils' => [
                    ['name' => 'Thru Hike Blend', 'weight' => 2],
                    ['name' => 'Apples and Maple Bourbon', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Cindy Fox Miller & Associates',
                'custom_scent' => 'Isle of Keys',
                'oils' => [
                    ['name' => 'Mango and Coconut Milk', 'weight' => 1],
                    ['name' => 'Mahogany Shea', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Cindy Fox Miller & Associates',
                'custom_scent' => 'CFM Signature Scent',
                'oils' => [
                    ['name' => 'Black Sea', 'weight' => 1],
                    ['name' => 'Sea Salt and Orchid', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Grateful Brew',
                'custom_scent' => 'Grateful Chai',
                'oils' => [
                    ['name' => 'Forest Chai', 'weight' => 1],
                    ['name' => 'Iced Gingersnap', 'weight' => 1],
                ],
            ],
            [
                'account' => 'Idletime Creations',
                'custom_scent' => 'Morning Mist',
                'oils' => [
                    ['name' => 'Baltic Dew', 'weight' => 1],
                ],
            ],
        ];

        $blendIndex = Blend::query()->with('components.baseOil')->get()->keyBy(function (Blend $blend) {
            return Scent::normalizeName($blend->name);
        });

        foreach ($rows as $row) {
            [$customName, $abbr] = $this->extractNameAndAbbr($row['custom_scent']);

            $blend = Blend::query()->firstOrCreate(
                ['name' => $customName],
                ['is_blend' => true]
            );

            $blend->is_blend = true;
            $blend->save();

            $blend->components()->delete();

            $components = $this->expandOilComponents($row['oils'], $blendIndex);

            foreach ($components as $component) {
                $baseOil = BaseOil::query()->firstOrCreate(
                    ['name' => $component['name']],
                    ['active' => true]
                );

                BlendComponent::query()->create([
                    'blend_id' => $blend->id,
                    'base_oil_id' => $baseOil->id,
                    'ratio_weight' => (int) $component['weight'],
                ]);
            }

            $scent = Scent::query()->get()->first(function (Scent $s) use ($customName) {
                return Scent::normalizeName($s->name) === Scent::normalizeName($customName)
                    || ($s->display_name && Scent::normalizeName($s->display_name) === Scent::normalizeName($customName));
            });

            if (!$scent) {
                $scent = Scent::query()->create([
                    'name' => Scent::normalizeName($customName),
                    'display_name' => $customName,
                    'abbreviation' => $abbr,
                    'oil_reference_name' => $customName,
                    'is_blend' => true,
                    'oil_blend_id' => $blend->id,
                    'is_wholesale_custom' => true,
                    'is_active' => true,
                ]);
            } else {
                $scent->is_blend = true;
                $scent->oil_blend_id = $blend->id;
                $scent->abbreviation = $abbr ?: $scent->abbreviation;
                $scent->oil_reference_name = $customName;
                $scent->is_wholesale_custom = true;
                $scent->save();
            }

            WholesaleCustomScent::query()->updateOrCreate(
                [
                    'account_name' => $row['account'],
                    'custom_scent_name' => $customName,
                ],
                [
                    'canonical_scent_id' => $scent->id,
                    'active' => true,
                ]
            );
        }
    }

    private function extractNameAndAbbr(string $value): array
    {
        $value = trim($value);
        $abbr = null;
        if (preg_match('/^(.*)\(([^)]+)\)\s*$/', $value, $matches)) {
            $value = trim($matches[1]);
            $abbr = trim($matches[2]);
        }
        return [$value, $abbr];
    }

    private function expandOilComponents(array $oils, $blendIndex): array
    {
        $components = [];

        foreach ($oils as $oil) {
            $name = $oil['name'];
            $weight = (int) $oil['weight'];
            $normalized = Scent::normalizeName(str_replace('blend', '', $name));

            $blend = $blendIndex[$normalized] ?? null;
            if ($blend) {
                foreach ($blend->components as $component) {
                    $components[] = [
                        'name' => $component->baseOil?->name ?? 'Unknown Oil',
                        'weight' => (int) $component->ratio_weight * $weight,
                    ];
                }
            } else {
                $components[] = [
                    'name' => $name,
                    'weight' => $weight,
                ];
            }
        }

        return $components;
    }
}
