<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stockist_locator_settings')) {
            return;
        }

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

    public function down(): void
    {
        Schema::dropIfExists('stockist_locator_settings');
    }
};
