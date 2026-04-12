<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_onboarding_blueprint_provisionings')) {
            // Deployment safety: the first rollout of this migration failed on MySQL due to an
            // auto-generated foreign key identifier that exceeded 64 characters. If the table was
            // created before the failure, attempt to attach the missing FK using a short name.
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql') {
                try {
                    Schema::table('tenant_onboarding_blueprint_provisionings', function (Blueprint $table): void {
                        $table->foreign('source_blueprint_id', 'onb_bp_prov_src_bp_fk')
                            ->references('id')
                            ->on('tenant_onboarding_blueprints')
                            ->cascadeOnDelete();
                    });
                } catch (\Throwable) {
                    // Ignore duplicate/unsupported FK errors; table existence means this migration
                    // must not block deploy. Blueprint provisioning remains blueprint-only truth.
                }
            }

            return;
        }

        Schema::create('tenant_onboarding_blueprint_provisionings', function (Blueprint $table): void {
            $table->id();
            // Source/demo tenant the blueprint belongs to (canonical tenant scope column).
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('source_blueprint_id');
            $table->unsignedBigInteger('provisioned_tenant_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('status', 40)->default('completed');
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Policy: one production tenant per finalized blueprint (safer default).
            $table->unique('source_blueprint_id', 'tenant_onboarding_blueprint_provisionings_blueprint_unique');
            $table->index('provisioned_tenant_id', 'tenant_onboarding_blueprint_provisionings_provisioned_idx');

            // Keep FK constraint identifiers short to stay under MySQL's 64-character limit.
            $table->foreign('tenant_id', 'onb_bp_prov_tenant_fk')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->foreign('source_blueprint_id', 'onb_bp_prov_src_bp_fk')
                ->references('id')
                ->on('tenant_onboarding_blueprints')
                ->cascadeOnDelete();

            $table->foreign('provisioned_tenant_id', 'onb_bp_prov_prov_tenant_fk')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('created_by_user_id', 'onb_bp_prov_creator_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_onboarding_blueprint_provisionings');
    }
};
