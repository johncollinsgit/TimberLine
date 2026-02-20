<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $sizes = [
            ['code' => 'wax-melts', 'label' => 'Wax Melts', 'wholesale' => 3.00, 'retail' => 6.00, 'sort' => 10],
            ['code' => 'room-sprays', 'label' => 'Room Sprays', 'wholesale' => 6.00, 'retail' => 12.00, 'sort' => 20],
            ['code' => '4oz-cotton', 'label' => '4oz Cotton Wick', 'wholesale' => 6.00, 'retail' => 12.00, 'sort' => 30],
            ['code' => '8oz-cotton', 'label' => '8oz Cotton Wick', 'wholesale' => 9.00, 'retail' => 18.00, 'sort' => 40],
            ['code' => '8oz-cedar', 'label' => '8oz Cedar Wick', 'wholesale' => 10.00, 'retail' => 20.00, 'sort' => 50],
            ['code' => '16oz-cotton', 'label' => '16oz Cotton Wick', 'wholesale' => 14.00, 'retail' => 28.00, 'sort' => 60],
            ['code' => '16oz-cedar', 'label' => '16oz Cedar Wick', 'wholesale' => 15.00, 'retail' => 30.00, 'sort' => 70],
        ];

        foreach ($sizes as $size) {
            DB::table('sizes')->updateOrInsert(
                ['code' => $size['code']],
                [
                    'label' => $size['label'],
                    'wholesale_price' => $size['wholesale'],
                    'retail_price' => $size['retail'],
                    'is_active' => true,
                    'sort_order' => $size['sort'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $legacyCodes = [
            '2oz tin', '4oz jar', '4oz tin', '6oz tin', '8oz tin', '8oz jar', '16oz jar',
        ];
        $legacyLabels = [
            '2 oz Tin', '4 oz Jar', '4 oz Tin', '6 oz Tin', '8 oz Tin', '8 oz Jar', '16 oz Jar',
        ];

        DB::table('sizes')->whereIn('code', $legacyCodes)->delete();
        DB::table('sizes')->whereIn('label', $legacyLabels)->delete();
    }

    public function down(): void
    {
        // No rollback: keep canonical sizes once applied.
    }
};
