<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\Shipping\BusinessDayCalculator;
use Carbon\CarbonImmutable;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders') || !Schema::hasColumn('orders', 'ship_by_at')) {
            return;
        }

        $calculator = app(BusinessDayCalculator::class);

        DB::table('orders')
            ->whereNull('ship_by_at')
            ->whereNotNull('created_at')
            ->orderBy('id')
            ->chunkById(200, function ($orders) use ($calculator) {
                foreach ($orders as $order) {
                    $start = CarbonImmutable::parse($order->created_at)->startOfDay();
                    $type = $order->order_type ?? 'retail';
                    $days = $type === 'wholesale' ? 10 : 3;
                    $shipBy = $calculator->addBusinessDays($start, $days)->startOfDay();
                    $dueAt = $order->due_at ?: $calculator->subBusinessDays($shipBy, 2)->startOfDay();

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update([
                            'ship_by_at' => $shipBy,
                            'due_at' => $dueAt,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // no-op (avoid destructive rollback)
    }
};
