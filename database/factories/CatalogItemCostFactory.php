<?php

namespace Database\Factories;

use App\Models\CatalogItemCost;
use App\Models\Scent;
use App\Models\Size;
use Illuminate\Database\Eloquent\Factories\Factory;

class CatalogItemCostFactory extends Factory
{
    protected $model = CatalogItemCost::class;

    public function definition(): array
    {
        return [
            'shopify_store_key' => 'retail',
            'shopify_product_id' => null,
            'shopify_variant_id' => null,
            'sku' => 'SKU-' . $this->faker->unique()->numerify('####'),
            'scent_id' => Scent::query()->inRandomOrder()->value('id'),
            'size_id' => Size::query()->inRandomOrder()->value('id'),
            'cost_amount' => $this->faker->randomFloat(2, 3, 18),
            'currency_code' => 'USD',
            'is_active' => true,
            'effective_at' => now()->subDay(),
            'notes' => null,
        ];
    }
}
