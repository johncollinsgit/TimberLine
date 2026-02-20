<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['size_code' => '16oz', 'product_type' => 'candle', 'wax_grams' => 357.17, 'oil_grams' => 22.83],
            ['size_code' => '16oz top off', 'product_type' => 'candle', 'wax_grams' => 42.33, 'oil_grams' => 2.67],
            ['size_code' => '8oz', 'product_type' => 'candle', 'wax_grams' => 157.92, 'oil_grams' => 10.08],
            ['size_code' => '8oz top off', 'product_type' => 'candle', 'wax_grams' => 28.17, 'oil_grams' => 1.83],
            ['size_code' => '4oz', 'product_type' => 'candle', 'wax_grams' => 80.75, 'oil_grams' => 5.17],
            ['size_code' => 'wax melts', 'product_type' => 'wax_melt', 'wax_grams' => 75.17, 'oil_grams' => 4.83],
        ];

        foreach ($rows as $row) {
            $row['total_grams'] = round($row['wax_grams'] + $row['oil_grams'], 2);

            DB::table('pouring_measurements')->updateOrInsert(
                ['size_code' => $row['size_code'], 'product_type' => $row['product_type']],
                $row
            );
        }
    }

    public function down(): void
    {
        DB::table('pouring_measurements')
            ->whereIn('size_code', ['16oz', '16oz top off', '8oz', '8oz top off', '4oz', 'wax melts'])
            ->delete();
    }
};
