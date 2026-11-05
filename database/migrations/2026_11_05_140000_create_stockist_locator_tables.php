<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stockist_locator_settings')) {
            Schema::create('stockist_locator_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('shopify_store_id');
                $table->string('status', 32)->default('draft');
                $table->json('configuration')->nullable();
                $table->string('source_checksum', 64)->nullable();
                $table->timestamp('last_imported_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'shopify_store_id'], 'stockist_settings_tenant_store_uq');
                $table->foreign('tenant_id', 'stockist_settings_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('shopify_store_id', 'stockist_settings_store_fk')->references('id')->on('shopify_stores')->cascadeOnDelete();
            });
        }
        if (! Schema::hasTable('stockist_locations')) {
            Schema::create('stockist_locations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('shopify_store_id');
                $table->string('source_key', 190);
                $table->string('source_id', 120)->nullable();
                $table->string('name', 190);
                $table->text('address')->nullable();
                $table->text('body')->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->string('marker_icon', 500)->nullable();
                $table->boolean('featured')->default(false);
                $table->boolean('source_visible')->default(true);
                $table->boolean('published')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->json('categories')->nullable();
                $table->json('source_payload')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'shopify_store_id', 'source_key'], 'stockist_locations_store_source_uq');
                $table->index(['tenant_id', 'shopify_store_id', 'published'], 'stockist_locations_store_published_idx');
                $table->foreign('tenant_id', 'stockist_locations_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('shopify_store_id', 'stockist_locations_store_fk')->references('id')->on('shopify_stores')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Retain the source and published snapshots during application rollback.
    }
};
