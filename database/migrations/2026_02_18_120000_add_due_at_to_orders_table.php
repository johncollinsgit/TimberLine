<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('orders', 'due_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('due_at')->nullable()->after('due_date');
            });
        }

        if (Schema::hasColumn('orders', 'due_date')) {
            // Backfill due_at from due_date at 00:00:00 (SQLite/MySQL compatible)
            DB::statement("UPDATE orders SET due_at = TIMESTAMP(due_date) WHERE due_at IS NULL AND due_date IS NOT NULL");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'due_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('due_at');
            });
        }
    }
};
