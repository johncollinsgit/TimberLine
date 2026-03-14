<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('candle_cash_tasks')
            ->where('handle', 'google-review')
            ->update([
                'action_url' => 'https://g.page/r/CTucm4R1-wmOEAI/review',
                'reward_amount' => 3.00,
                'enabled' => 1,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('candle_cash_tasks')
            ->where('handle', 'google-review')
            ->where('action_url', 'https://g.page/r/CTucm4R1-wmOEAI/review')
            ->update([
                'action_url' => null,
                'updated_at' => now(),
            ]);
    }
};
