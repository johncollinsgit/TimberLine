<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_plant_inventory_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('category')->nullable()->index();
            $table->string('sku')->nullable();
            $table->string('vendor_source')->nullable();
            $table->decimal('purchased_cost', 10, 2)->nullable();
            $table->decimal('sell_price', 10, 2)->nullable();
            $table->integer('quantity_on_hand')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->text('notes')->nullable();
            $table->string('square_id')->nullable();
            $table->string('shopify_product_id')->nullable();
            $table->string('shopify_variant_id')->nullable();
            $table->string('status', 40)->default('active')->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'sku'], 'tenant_plant_inventory_items_tenant_sku_unique');
            $table->index(['tenant_id', 'status', 'category'], 'tenant_plant_inventory_items_scope_idx');
        });

        Schema::create('tenant_plant_inventory_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plant_inventory_item_id')->constrained('tenant_plant_inventory_items')->cascadeOnDelete();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('adjustment_type', 40)->index();
            $table->integer('quantity_delta')->default(0);
            $table->integer('reserved_delta')->default(0);
            $table->integer('before_quantity_on_hand');
            $table->integer('after_quantity_on_hand');
            $table->integer('before_reserved_quantity');
            $table->integer('after_reserved_quantity');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'plant_inventory_item_id'], 'tenant_plant_adjustments_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_plant_inventory_adjustments');
        Schema::dropIfExists('tenant_plant_inventory_items');
    }
};
