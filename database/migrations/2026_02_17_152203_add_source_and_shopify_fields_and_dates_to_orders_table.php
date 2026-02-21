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
        // MySQL-safe: unique index (MySQL allows multiple NULLs in UNIQUE indexes)
        Schema::table("orders", function (Blueprint $table) {
            $table->unique(["shopify_store", "shopify_order_id"], "orders_unique_shopify_store_order");
        });
    }

    public function down(): void
    {
        // Drop index
        Schema::table("orders", function (Blueprint $table) {
            $table->dropUnique("orders_unique_shopify_store_order");
        });

        // Drop column
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('ship_by_at');
        });
    }
};
