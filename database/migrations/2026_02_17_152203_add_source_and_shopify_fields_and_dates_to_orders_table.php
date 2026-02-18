<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add ship_by_at if missing (you don't have it yet)
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('ship_by_at')->nullable();
        });

        // SQLite-friendly: partial unique index so manual orders (null shopify_order_id) are allowed
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS orders_unique_shopify_store_order
            ON orders (shopify_store, shopify_order_id)
            WHERE shopify_store IS NOT NULL AND shopify_order_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        // Drop index
        DB::statement("DROP INDEX IF EXISTS orders_unique_shopify_store_order");

        // Drop column
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('ship_by_at');
        });
    }
};
