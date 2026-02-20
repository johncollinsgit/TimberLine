<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'order_type')) {
                $table->string('order_type')->nullable()->after('source');
            }
            if (!Schema::hasColumn('orders', 'order_label')) {
                $table->string('order_label')->nullable()->after('order_type');
            }
        });

        if (Schema::hasColumn('orders', 'order_type') && Schema::hasColumn('orders', 'container_name')) {
            DB::statement(<<<SQL
                UPDATE orders
                SET order_type = CASE
                    WHEN source = 'shopify_wholesale' THEN 'wholesale'
                    WHEN source = 'shopify_retail' THEN 'retail'
                    WHEN source = 'market' THEN 'event'
                    WHEN source = 'custom' THEN 'retail'
                    WHEN source = 'internal' THEN 'retail'
                    WHEN container_name LIKE 'Wholesale:%' THEN 'wholesale'
                    WHEN container_name LIKE 'Market:%' THEN 'event'
                    ELSE 'retail'
                END
                WHERE order_type IS NULL
            SQL);
        }

        if (Schema::hasColumn('orders', 'order_label')) {
            DB::statement(<<<SQL
                UPDATE orders
                SET order_label = COALESCE(container_name, customer_name, order_number)
                WHERE order_label IS NULL
            SQL);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'order_label')) {
                $table->dropColumn('order_label');
            }
            if (Schema::hasColumn('orders', 'order_type')) {
                $table->dropColumn('order_type');
            }
        });
    }
};
