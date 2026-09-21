<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('replacement_modules')) {
            return;
        }

        Schema::create('replacement_modules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('shopify_store_id');
            $table->string('module_key', 80);
            $table->string('source_app', 120);
            $table->string('replacement_name', 120);
            $table->string('status', 40)->default('draft');
            $table->string('active_provider', 80)->default('legacy');
            $table->string('target_provider', 80)->default('everbranch');
            $table->string('activation_mode', 40)->default('single_click');
            $table->string('source_fingerprint', 64)->nullable();
            $table->string('target_fingerprint', 64)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'shopify_store_id', 'module_key'], 'replacement_modules_store_module_uq');
            $table->index(['tenant_id', 'status'], 'replacement_modules_tenant_status_idx');
            $table->foreign('tenant_id', 'replacement_modules_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('shopify_store_id', 'replacement_modules_store_fk')->references('id')->on('shopify_stores')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replacement_modules');
    }
};
