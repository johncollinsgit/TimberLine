<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $scents = [
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

        foreach ($scents as $scent) {
            DB::table('scents')->updateOrInsert(
                ['name' => $scent],
                [
                    'display_name' => $scent,
                    'is_active' => true,
                    'sort_order' => 0,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $sizes = [
            ['code' => '2oz tin', 'label' => '2 oz Tin', 'sort_order' => 10],
            ['code' => '4oz jar', 'label' => '4 oz Jar', 'sort_order' => 20],
            ['code' => '4oz tin', 'label' => '4 oz Tin', 'sort_order' => 30],
            ['code' => '6oz tin', 'label' => '6 oz Tin', 'sort_order' => 40],
            ['code' => '8oz tin', 'label' => '8 oz Tin', 'sort_order' => 50],
            ['code' => '8oz jar', 'label' => '8 oz Jar', 'sort_order' => 60],
            ['code' => '16oz jar', 'label' => '16 oz Jar', 'sort_order' => 70],
        ];

        foreach ($sizes as $size) {
            DB::table('sizes')->updateOrInsert(
                ['code' => $size['code']],
                [
                    'label' => $size['label'],
                    'is_active' => true,
                    'sort_order' => $size['sort_order'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $wicks = ['cotton', 'cedar'];
        foreach ($wicks as $wick) {
            DB::table('wicks')->updateOrInsert(
                ['name' => $wick],
                [
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('wicks')->whereIn('name', ['cotton', 'cedar'])->delete();
        DB::table('sizes')->whereIn('code', ['2oz tin', '4oz jar', '4oz tin', '6oz tin', '8oz tin', '8oz jar', '16oz jar'])->delete();
        DB::table('scents')->whereIn('name', [
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
        ])->delete();
    }
};
