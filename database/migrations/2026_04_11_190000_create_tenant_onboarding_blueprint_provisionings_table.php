<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_onboarding_blueprint_provisionings')) {
            return;
        }

        Schema::create('tenant_onboarding_blueprint_provisionings', function (Blueprint $table): void {
            $table->id();
            // Source/demo tenant the blueprint belongs to (canonical tenant scope column).
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('source_blueprint_id')
                ->constrained('tenant_onboarding_blueprints')
                ->cascadeOnDelete();
            $table->foreignId('provisioned_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 40)->default('completed');
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Policy: one production tenant per finalized blueprint (safer default).
            $table->unique('source_blueprint_id', 'tenant_onboarding_blueprint_provisionings_blueprint_unique');
            $table->index('provisioned_tenant_id', 'tenant_onboarding_blueprint_provisionings_provisioned_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_onboarding_blueprint_provisionings');
    }
};
