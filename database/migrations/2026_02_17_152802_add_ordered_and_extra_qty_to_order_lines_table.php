<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->integer('ordered_qty')->nullable()->after('quantity');
            $table->integer('extra_qty')->nullable()->after('ordered_qty');
        });

        // Backfill so existing UI/data still works
        DB::statement("UPDATE order_lines SET ordered_qty = quantity WHERE ordered_qty IS NULL");
        DB::statement("UPDATE order_lines SET extra_qty = 0 WHERE extra_qty IS NULL");
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropColumn(['ordered_qty', 'extra_qty']);
        });
    }
};
