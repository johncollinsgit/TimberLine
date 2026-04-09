<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('candle_cash_tasks')) {
            return;
        }

        DB::table('candle_cash_tasks')
            ->where('handle', 'instagram-comment')
            ->update([
                'action_url' => 'https://www.instagram.com/modernforestry/',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('candle_cash_tasks')) {
            return;
        }

        DB::table('candle_cash_tasks')
            ->where('handle', 'instagram-comment')
            ->update([
                'action_url' => 'https://www.instagram.com/theforestrystudio/',
                'updated_at' => now(),
            ]);
    }
};

