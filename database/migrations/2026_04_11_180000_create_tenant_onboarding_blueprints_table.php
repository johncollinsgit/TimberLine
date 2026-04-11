<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_onboarding_blueprints')) {
            Schema::create('tenant_onboarding_blueprints', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 40)->default('draft');
                $table->string('account_mode', 40)->default('production');
                $table->string('rail', 40)->default('direct');
                $table->unsignedInteger('blueprint_version')->default(1);
                $table->json('payload')->nullable();
                $table->json('origin')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'status'], 'tenant_onboarding_blueprints_tenant_status_idx');
                $table->index(['tenant_id', 'rail'], 'tenant_onboarding_blueprints_tenant_rail_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_onboarding_blueprints');
    }
};

