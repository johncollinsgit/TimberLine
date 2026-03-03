<?php

namespace Database\Seeders;

use App\Models\Scent;
use App\Models\Size;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $scents = $this->canonicalScents();
        $seen = [];
        foreach ($scents as $name) {
            $clean = $this->normalizeApostrophes(trim($name));
            if ($clean === '') {
                continue;
            }
            $key = $this->normalizeKey($clean);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            Scent::query()->firstOrCreate(
                ['name' => $clean],
                [
                    'display_name' => $clean,
                    'is_active' => true,
                ]
            );
        }

        $sizes = $this->canonicalSizes();
        $sort = 1;
        foreach ($sizes as $size) {
            Size::query()->firstOrCreate(
                ['code' => $size['code']],
                [
                    'label' => $size['label'],
                    'wholesale_price' => $size['wholesale'],
                    'retail_price' => $size['retail'],
                    'is_active' => true,
                    'sort_order' => $sort++,
                ]
            );
        }
    }

    protected function normalizeApostrophes(string $value): string
    {
        return str_replace(['’', '‘'], "'", $value);
    }

    protected function normalizeKey(string $value): string
    {
        $lower = strtolower($this->normalizeApostrophes($value));
        $stripped = preg_replace('/[^a-z0-9]+/i', '', $lower) ?? '';
        return $stripped;
    }

    protected function canonicalScents(): array
    {
        return [
            'Almond Cream Cake',
            'Amber Fog',
            'Appalachian Maple Bourbon',
            'Autumn Leaves',
            'Beard',
            'Butter Pecan',
            'Campfire',
            'Christmas Garland',
            'Cinna Bakery',
            'Cinnamon Broom',
            'Citronella',
            'Coconut Glow',
            'Coffeehouse',
            'Cozy Cabin',
            'Cranberry Apple',
            'Enchanted Forest',
            'Eucalyptus Mint',
            'Fireside Flannel',
            'Forest Spice',
            'Garden Mint',
            'Harvest Apple',
            'Holiday at Sea',
            'Holiday Hike In',
            'Holly Wreath',
            'Honeysuckle',
            'Hot Apple Cider',
            'Lava Rock',
            'Lavender',
            'Magnolia Blossom',
            'Nightfall',
            'Orange Pomander',
            'Orange Sandalwood',
            "Papa's Pipe",
            'Patchouli Teakwood',
            'Peach Orchard',
            'Peppermint Milkshake',
            'Pomegranate Cider',
            'Pumpkin Chai',
            'Pumpkin Spice Latte',
            'Pumpkin Streusel',
            'River Birch',
            'Rosemary',
            "Sippin' Sunshine",
            'Snow Day',
            'Strawberry Jam',
            'Summer Linen',
            'Sunwashed',
            'Thru Hike',
            'Thundershowers',
            'Tomato Leaf',
            'Vanilla',
            'Vanilla Latte',
            'Watermelon',
            'White Tea',
        ];
    }

    protected function canonicalSizes(): array
    {
        return [
            ['code' => 'wax-melts', 'label' => 'Wax Melts', 'wholesale' => 3.00, 'retail' => 6.00],
            ['code' => '4oz-cotton', 'label' => '4oz Cotton Wick', 'wholesale' => 6.00, 'retail' => 12.00],
            ['code' => 'room-sprays', 'label' => 'Room Sprays', 'wholesale' => 6.00, 'retail' => 12.00],
            ['code' => '8oz-cotton', 'label' => '8oz Cotton Wick', 'wholesale' => 9.00, 'retail' => 18.00],
            ['code' => '8oz-cedar', 'label' => '8oz Cedar Wick', 'wholesale' => 10.00, 'retail' => 20.00],
            ['code' => '16oz-cotton', 'label' => '16oz Cotton Wick', 'wholesale' => 14.00, 'retail' => 28.00],
            ['code' => '16oz-cedar', 'label' => '16oz Cedar Wick', 'wholesale' => 15.00, 'retail' => 30.00],
        ];
    }
}
