<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['quantity' => 1, 'alcohol_grams' => 28, 'oil_grams' => 3, 'water_grams' => 81],
            ['quantity' => 2, 'alcohol_grams' => 56, 'oil_grams' => 6, 'water_grams' => 162],
            ['quantity' => 3, 'alcohol_grams' => 84, 'oil_grams' => 10, 'water_grams' => 245],
            ['quantity' => 6, 'alcohol_grams' => 168, 'oil_grams' => 20, 'water_grams' => 490],
        ];

        foreach ($rows as $row) {
            $row['total_grams'] = (float) $row['alcohol_grams'] + (float) $row['oil_grams'] + (float) $row['water_grams'];

            DB::table('room_spray_measurements')->updateOrInsert(
                ['quantity' => $row['quantity']],
                $row
            );
        }
    }

    public function down(): void
    {
        DB::table('room_spray_measurements')->whereIn('quantity', [1,2,3,6])->delete();
    }
};
