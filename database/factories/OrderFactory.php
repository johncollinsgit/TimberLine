<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'source' => 'manual',
            'currency_code' => 'USD',
            'order_number' => 'MF-' . $this->faker->unique()->numberBetween(1000, 9999),
            'container_name' => $this->faker->randomElement([
                'Market: Frosty Farmer',
                'Market: Strawberry Festival',
                'Wholesale: Retail Partner',
            ]),
            'customer_name' => $this->faker->name(),
            'ordered_at' => $this->faker->date(),
            'due_date' => $this->faker->date(),
            'subtotal_price' => 48.00,
            'discount_total' => 0.00,
            'tax_total' => 0.00,
            'shipping_total' => 0.00,
            'refund_total' => 0.00,
            'total_price' => 48.00,
            'status' => $this->faker->randomElement(['new','reviewed','pouring','verified','complete']),
            'internal_notes' => $this->faker->optional()->sentence(),
        ];
    }
}
