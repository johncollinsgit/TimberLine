<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_module_entitlements')) {
            return;
        }

        Schema::create('tenant_module_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('module_key', 120);
            $table->string('availability_status', 40)->default('available');
            $table->string('enabled_status', 40)->default('inherit');
            $table->string('billing_status', 40)->nullable();
            $table->integer('price_override_cents')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('entitlement_source', 80)->nullable();
            $table->string('price_source', 80)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'module_key'], 'tenant_module_entitlements_tenant_module_unique');
            $table->index(['module_key', 'availability_status'], 'tenant_module_entitlements_module_availability_index');
            $table->index(['billing_status', 'enabled_status'], 'tenant_module_entitlements_billing_enabled_index');

            $table->foreign('tenant_id', 'tenant_module_entitlements_tenant_fk')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table->foreign('created_by', 'tenant_module_entitlements_created_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('updated_by', 'tenant_module_entitlements_updated_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_module_entitlements');
    }
};
