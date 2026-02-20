<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_lines_new')) {
            Schema::drop('order_lines_new');
        }

        Schema::create('order_lines_new', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');

            // legacy fields (nullable)
            $table->string('scent_name')->nullable();
            $table->string('size_code')->nullable();

            $table->integer('quantity')->default(1);
            $table->integer('ordered_qty')->default(0);
            $table->integer('extra_qty')->default(0);

            $table->unsignedBigInteger('shopify_line_item_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('raw_title')->nullable();
            $table->string('raw_variant')->nullable();

            $table->string('pour_status')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('brought_down_at')->nullable();

            $table->timestamps();

            $table->unsignedBigInteger('scent_id')->nullable();
            $table->unsignedBigInteger('size_id')->nullable();
        });

        DB::statement(<<<SQL
            INSERT INTO order_lines_new
            (id, order_id, scent_name, size_code, quantity, ordered_qty, extra_qty, shopify_line_item_id, sku, raw_title, raw_variant, pour_status, started_at, brought_down_at, created_at, updated_at, scent_id, size_id)
            SELECT
                id,
                order_id,
                scent_name,
                size_code,
                quantity,
                COALESCE(ordered_qty, quantity),
                COALESCE(extra_qty, 0),
                NULL,
                NULL,
                raw_title,
                raw_variant,
                pour_status,
                started_at,
                brought_down_at,
                created_at,
                updated_at,
                scent_id,
                size_id
            FROM order_lines
        SQL);

        Schema::drop('order_lines');
        Schema::rename('order_lines_new', 'order_lines');

        Schema::table('order_lines', function (Blueprint $table) {
            $table->index(['order_id', 'scent_id', 'size_id'], 'order_lines_order_scent_size_idx');
        });

        try {
            DB::statement(<<<SQL
                CREATE UNIQUE INDEX IF NOT EXISTS order_lines_unique_order_shopify_line
                ON order_lines (order_id, shopify_line_item_id)
                WHERE shopify_line_item_id IS NOT NULL
            SQL);
        } catch (Throwable $e) {
            Schema::table('order_lines', function (Blueprint $table) {
                $table->unique(['order_id', 'shopify_line_item_id'], 'order_lines_unique_order_shopify_line');
            });
        }

        // Partial unique index when both scent_id and size_id are present.
        // If the database does not support partial indexes, we fall back to a standard unique index.
        try {
            DB::statement(<<<SQL
                CREATE UNIQUE INDEX IF NOT EXISTS order_lines_unique_order_scent_size_not_null
                ON order_lines (order_id, scent_id, size_id)
                WHERE scent_id IS NOT NULL AND size_id IS NOT NULL
            SQL);
        } catch (Throwable $e) {
            Schema::table('order_lines', function (Blueprint $table) {
                $table->unique(['order_id', 'scent_id', 'size_id'], 'order_lines_unique_order_scent_size_not_null');
            });
        }
    }

    public function down(): void
    {
        // Rebuild migrations are intentionally one-way in dev.
    }
};
