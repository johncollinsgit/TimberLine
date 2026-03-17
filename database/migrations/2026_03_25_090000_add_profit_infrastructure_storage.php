<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'currency_code')) {
                $table->string('currency_code', 8)->nullable()->after('shopify_name');
            }

            foreach ([
                'subtotal_price',
                'discount_total',
                'tax_total',
                'shipping_total',
                'refund_total',
                'total_price',
            ] as $column) {
                if (! Schema::hasColumn('orders', $column)) {
                    $table->decimal($column, 10, 2)->nullable()->after('attribution_meta');
                }
            }
        });

        Schema::table('order_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_lines', 'shopify_product_id')) {
                $table->unsignedBigInteger('shopify_product_id')->nullable()->after('shopify_line_item_id');
                $table->index('shopify_product_id', 'order_lines_shopify_product_id_idx');
            }

            if (! Schema::hasColumn('order_lines', 'shopify_variant_id')) {
                $table->unsignedBigInteger('shopify_variant_id')->nullable()->after('shopify_product_id');
                $table->index('shopify_variant_id', 'order_lines_shopify_variant_id_idx');
            }

            if (! Schema::hasColumn('order_lines', 'currency_code')) {
                $table->string('currency_code', 8)->nullable()->after('shopify_variant_id');
            }

            foreach ([
                'unit_price',
                'line_subtotal',
                'discount_total',
                'line_total',
            ] as $column) {
                if (! Schema::hasColumn('order_lines', $column)) {
                    $table->decimal($column, 10, 2)->nullable()->after('currency_code');
                }
            }
        });

        if (! Schema::hasTable('catalog_item_costs')) {
            Schema::create('catalog_item_costs', function (Blueprint $table): void {
                $table->id();
                $table->string('shopify_store_key', 80)->nullable()->index();
                $table->unsignedBigInteger('shopify_product_id')->nullable()->index();
                $table->unsignedBigInteger('shopify_variant_id')->nullable()->index();
                $table->string('sku', 160)->nullable()->index();
                $table->foreignId('scent_id')->nullable()->constrained('scents')->nullOnDelete();
                $table->foreignId('size_id')->nullable()->constrained('sizes')->nullOnDelete();
                $table->decimal('cost_amount', 10, 2);
                $table->string('currency_code', 8)->default('USD');
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('effective_at')->nullable()->index();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['shopify_store_key', 'shopify_variant_id'], 'catalog_costs_store_variant_idx');
                $table->index(['shopify_store_key', 'shopify_product_id'], 'catalog_costs_store_product_idx');
                $table->index(['shopify_store_key', 'sku'], 'catalog_costs_store_sku_idx');
                $table->index(['scent_id', 'size_id'], 'catalog_costs_scent_size_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('catalog_item_costs')) {
            Schema::drop('catalog_item_costs');
        }

        Schema::table('order_lines', function (Blueprint $table): void {
            foreach ([
                'order_lines_shopify_product_id_idx',
                'order_lines_shopify_variant_id_idx',
            ] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (\Throwable) {
                }
            }

            foreach ([
                'shopify_product_id',
                'shopify_variant_id',
                'currency_code',
                'unit_price',
                'line_subtotal',
                'discount_total',
                'line_total',
            ] as $column) {
                if (Schema::hasColumn('order_lines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            foreach ([
                'currency_code',
                'subtotal_price',
                'discount_total',
                'tax_total',
                'shipping_total',
                'refund_total',
                'total_price',
            ] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
