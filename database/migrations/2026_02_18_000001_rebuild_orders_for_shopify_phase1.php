<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rebuild orders table for Shopify Phase 1 (MySQL-safe)
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

            // Container info
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

        // Copy data without SQLite-specific SQL (no CAST ... AS INTEGER)
        if (Schema::hasTable('orders')) {
            // Use chunking to avoid memory spikes if table grows.
            DB::table('orders')
                ->orderBy('id')
                ->chunkById(1000, function ($rows) {
                    foreach ($rows as $r) {
                        DB::table('orders_new')->insert([
                            'id'               => $r->id,
                            'source'           => $r->source ?? 'manual',
                            'shopify_store'    => $r->shopify_store ?? null,
                            'shopify_store_key'=> $r->shopify_store ?? ($r->shopify_store_key ?? null),
                            'shopify_order_id' => $r->shopify_order_id ? (int) $r->shopify_order_id : null,
                            'shopify_name'     => $r->order_number ?? ($r->shopify_name ?? null),
                            'order_number'     => $r->order_number ?? null,
                            'container_name'   => $r->container_name ?? null,
                            'customer_name'    => $r->customer_name ?? null,
                            'ordered_at'       => $r->ordered_at ?? null,
                            'due_date'         => $r->due_date ?? null,
                            'due_at'           => $r->due_at ?? null,
                            'ship_by_at'       => $r->ship_by_at ?? null,
                            'status'           => $r->status ?? 'new',
                            'internal_notes'   => $r->internal_notes ?? null,
                            'tenant_id'        => $r->tenant_id ?? null,
                            'created_at'       => $r->created_at ?? now(),
                            'updated_at'       => $r->updated_at ?? now(),
                        ]);
                    }
                });

            Schema::drop('orders');
        }

        Schema::rename('orders_new', 'orders');

        // MySQL-safe unique index (MySQL allows multiple NULLs in UNIQUE indexes)
        Schema::table('orders', function (Blueprint $table) {
            $table->unique(['shopify_store_key', 'shopify_order_id'], 'orders_unique_shopify_store_order');
        });
    }

    public function down(): void
    {
        // Rebuild migrations are intentionally one-way in dev.
    }
};
