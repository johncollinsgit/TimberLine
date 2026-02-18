<?php

namespace Database\Seeders;

use App\Models\Order;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        Order::factory()
            ->count(60)
            ->create()
            ->each(function (Order $order) {
                $count = rand(2, 8);

                // Prefer relationship attach if available
                if (method_exists($order, 'lines')) {
                    $order->lines()->createMany(
                        \App\Models\OrderLine::factory()->count($count)->make()->toArray()
                    );
                    return;
                }

                // Fallback if relation not defined for some reason
                \App\Models\OrderLine::factory()
                    ->count($count)
                    ->create(['order_id' => $order->id]);
            });
    }
}
