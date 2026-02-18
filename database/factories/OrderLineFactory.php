<?php

namespace Database\Factories;

use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\Size;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderLineFactory extends Factory
{
    protected $model = OrderLine::class;

    /** @var string[] */
    private const FALLBACK_SCENTS = [
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
        'Papa’s Pipe',
        'Patchouli Teakwood',
        'Peach Orchard',
        'Peppermint Milkshake',
        'Pomegranate Cider',
        'Pumpkin Chai',
        'Pumpkin Spice Latte',
        'Pumpkin Streusel',
        'River Birch',
        'Rosemary',
        'Sippin’ Sunshine',
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

    public function definition(): array
    {
        // Pull active catalog entries if they exist
        $scent = Scent::query()
            ->where('is_active', true)
            ->inRandomOrder()
            ->first(['id', 'name']);

        $size = Size::query()
            ->where('is_active', true)
            ->inRandomOrder()
            ->first(['id', 'code', 'label']);

        // Fallbacks if catalog is empty
        $scentName = $scent?->name ?? $this->faker->randomElement(self::FALLBACK_SCENTS);

        // Weighted sizes if DB doesn’t have any yet
        $fallbackSizeCode = $this->faker->randomElement([
            '8oz', '8oz', '8oz', '8oz',
            '16oz', '16oz',
            'Wax Melt',
            'Room Spray',
        ]);

        $sizeCode = $size?->code ?? $fallbackSizeCode;

        // Qty feels like wholesale-ish ordering
        $qty = $this->faker->randomElement([1, 3, 3, 6, 6, 9, 12, 12]);

        $pourStatus = $this->faker->randomElement([
            'queued','queued','queued',
            'laid_out','laid_out',
            'first_pour',
            'second_pour',
            'waiting_on_oil',
        ]);

        return [
            // order_id set by seeder

            'scent_id'    => $scent?->id,
            'size_id'     => $size?->id,

            // legacy/search fields still used in some parts of your UI/querying
            'scent_name'  => $scentName,
            'size_code'   => $sizeCode,

            // keep BOTH columns in sync while you’re in transition
            'ordered_qty' => $qty,
            'quantity'    => $qty,

            'pour_status' => $pourStatus,
        ];
    }
}
