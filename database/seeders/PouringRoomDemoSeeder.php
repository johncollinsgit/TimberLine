<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\Size;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class PouringRoomDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (
            Scent::query()->where('is_active', true)->count() === 0 ||
            Size::query()->where('is_active', true)->count() === 0
        ) {
            $this->call(CatalogSeeder::class);
        }

        $scents = Scent::query()
            ->where('is_active', true)
            ->inRandomOrder()
            ->limit(24)
            ->get(['id', 'name']);

        $sizes = Size::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'code', 'label']);

        if ($scents->isEmpty() || $sizes->isEmpty()) {
            $this->command?->warn('Skipping demo seed: no active scents or sizes found.');
            return;
        }

        // Keep demo data repeatable and easy to refresh without touching real orders.
        Order::query()->where('order_number', 'like', 'DEMO-PR-%')->delete();

        $statuses = ['submitted_to_pouring', 'pouring', 'brought_down', 'verified'];
        $types = ['retail', 'wholesale', 'event'];
        $lineStatuses = ['queued', 'laid_out', 'first_pour', 'second_pour', 'waiting_on_oil'];

        $ordersCreated = 0;
        $linesCreated = 0;

        for ($i = 1; $i <= 14; $i++) {
            $type = $types[$i % count($types)];
            $orderedAt = Carbon::now()->subDays(rand(0, 8))->subHours(rand(0, 20));
            $shipByAt = (clone $orderedAt)->addDays($type === 'wholesale' ? rand(4, 10) : rand(1, 6));
            $dueAt = (clone $shipByAt)->subDays(rand(1, 2));

            $order = Order::query()->create([
                'source' => 'manual',
                'order_number' => sprintf('DEMO-PR-%04d', $i),
                'container_name' => $this->demoContainerName($type, $i),
                'customer_name' => $this->demoCustomerName($i),
                'ordered_at' => $orderedAt,
                'due_at' => $dueAt,
                'ship_by_at' => $shipByAt,
                'status' => $statuses[array_rand($statuses)],
                'order_type' => $type,
                'order_label' => 'Pouring Demo ' . $i,
                'published_at' => Carbon::now()->subMinutes(rand(5, 800)),
                'internal_notes' => 'Demo data for Pouring Room visual QA.',
            ]);

            $ordersCreated++;

            $targetLineCount = rand(3, 6);
            $picked = [];
            $attempts = 0;

            while (count($picked) < $targetLineCount && $attempts < 80) {
                $attempts++;

                $scent = $scents->random();
                $size = $sizes->random();
                $pairKey = $scent->id . ':' . $size->id;

                if (isset($picked[$pairKey])) {
                    continue;
                }

                $picked[$pairKey] = true;

                $qty = rand(2, 18);
                $wickType = str_contains((string) $size->code, 'cedar') ? 'wood' : 'cotton';

                OrderLine::query()->create([
                    'order_id' => $order->id,
                    'scent_id' => $scent->id,
                    'size_id' => $size->id,
                    'scent_name' => $scent->name,
                    'size_code' => $size->code ?? $size->label,
                    'ordered_qty' => $qty,
                    'quantity' => $qty,
                    'extra_qty' => rand(0, 2),
                    'pour_status' => $lineStatuses[array_rand($lineStatuses)],
                    'raw_title' => $scent->name . ' Candle',
                    'raw_variant' => $size->label ?? $size->code,
                    'wick_type' => $wickType,
                ]);

                $linesCreated++;
            }
        }

        $this->command?->info("Pouring demo seeded: {$ordersCreated} orders, {$linesCreated} lines.");
    }

    private function demoContainerName(string $type, int $index): string
    {
        return match ($type) {
            'wholesale' => 'Wholesale: Demo Account ' . $index,
            'event' => 'Market: Demo Event ' . $index,
            default => 'Retail: Demo Order ' . $index,
        };
    }

    private function demoCustomerName(int $index): string
    {
        return 'Demo Customer ' . $index;
    }
}
