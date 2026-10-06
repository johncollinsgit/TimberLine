<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('website_collections')) {
            Schema::create('website_collections', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants', 'id', 'wc_tenant_fk')->cascadeOnDelete();
                $table->foreignId('tenant_site_id')->constrained('tenant_sites', 'id', 'wc_site_fk')->cascadeOnDelete();
                $table->string('title', 190);
                $table->string('handle', 160);
                $table->text('description')->nullable();
                $table->string('status', 24)->default('draft');
                $table->text('image_url')->nullable();
                $table->json('seo')->nullable();
                $table->timestamps();
                $table->unique(['tenant_site_id', 'handle'], 'wc_site_handle_uq');
                $table->index(['tenant_id', 'status'], 'wc_tenant_status_idx');
            });
        }
        if (! Schema::hasTable('website_collection_products')) {
            Schema::create('website_collection_products', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants', 'id', 'wcp_tenant_fk')->cascadeOnDelete();
                $table->foreignId('website_collection_id')->constrained('website_collections', 'id', 'wcp_collection_fk')->cascadeOnDelete();
                $table->foreignId('website_product_id')->constrained('website_products', 'id', 'wcp_product_fk')->cascadeOnDelete();
                $table->unsignedInteger('position')->default(0);
                $table->unique(['website_collection_id', 'website_product_id'], 'wcp_collection_product_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('website_collection_products');
        Schema::dropIfExists('website_collections');
    }
};
