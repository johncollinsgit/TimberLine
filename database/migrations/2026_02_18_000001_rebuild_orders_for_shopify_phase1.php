<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders_new')) {
            Schema::drop('orders_new');
        }

        Schema::create('orders_new', function (Blueprint $table) {
            $table->id();

            // Source + identifiers
            $table->string('source')->default('manual');
            $table->string('shopify_store')->nullable();
            $table->string('shopify_store_key')->nullable();
            $table->unsignedBigInteger('shopify_order_id')->nullable()->index();
            $table->string('shopify_name')->nullable();
            $table->string('order_number')->nullable();

            // Container info (event / wholesale account / customer)
            $table->string('container_name')->nullable();
            $table->string('customer_name')->nullable();

            // Dates
            $table->timestamp('ordered_at')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('ship_by_at')->nullable();

            // Workflow status
            $table->string('status')->default('new');

            $table->text('internal_notes')->nullable();

            $table->unsignedBigInteger('tenant_id')->nullable();

            $table->timestamps();
        });

        // Copy data from the old table (best-effort for new columns)
        DB::statement(<<<SQL
            INSERT INTO orders_new
            (id, source, shopify_store, shopify_store_key, shopify_order_id, shopify_name, order_number, container_name, customer_name, ordered_at, due_date, due_at, ship_by_at, status, internal_notes, tenant_id, created_at, updated_at)
            SELECT
                id,
                source,
                shopify_store,
                shopify_store AS shopify_store_key,
                CAST(shopify_order_id AS INTEGER),
                order_number AS shopify_name,
                order_number,
                container_name,
                customer_name,
                ordered_at,
                due_date,
                NULL,
                ship_by_at,
                status,
                internal_notes,
                tenant_id,
                created_at,
                updated_at
            FROM orders
        SQL);

        Schema::drop('orders');
        Schema::rename('orders_new', 'orders');

        // SQLite-friendly partial unique index for Shopify IDs scoped by store
        DB::statement(<<<SQL
            CREATE UNIQUE INDEX IF NOT EXISTS orders_unique_shopify_store_order
            ON orders (shopify_store_key, shopify_order_id)
            WHERE shopify_store_key IS NOT NULL AND shopify_order_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        // Rebuild migrations are intentionally one-way in dev.
    }
};
