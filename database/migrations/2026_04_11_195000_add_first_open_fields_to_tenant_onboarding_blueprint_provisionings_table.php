<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_onboarding_blueprint_provisionings')) {
            return;
        }

        Schema::table('tenant_onboarding_blueprint_provisionings', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_onboarding_blueprint_provisionings', 'first_opened_at')) {
                $table->timestamp('first_opened_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('tenant_onboarding_blueprint_provisionings', 'first_open_acknowledged_by_user_id')) {
                $table->unsignedBigInteger('first_open_acknowledged_by_user_id')
                    ->nullable()
                    ->after('first_opened_at');
            }
            if (! Schema::hasColumn('tenant_onboarding_blueprint_provisionings', 'first_open_payload_anchor')) {
                $table->string('first_open_payload_anchor', 40)->nullable()->after('first_open_acknowledged_by_user_id');
            }
            if (! Schema::hasColumn('tenant_onboarding_blueprint_provisionings', 'first_open_opened_path')) {
                $table->string('first_open_opened_path', 2048)->nullable()->after('first_open_payload_anchor');
            }
        });

        // Keep FK constraint identifiers short to stay under MySQL's 64-character limit.
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql' && Schema::hasColumn('tenant_onboarding_blueprint_provisionings', 'first_open_acknowledged_by_user_id')) {
            try {
                Schema::table('tenant_onboarding_blueprint_provisionings', function (Blueprint $table): void {
                    $table->foreign('first_open_acknowledged_by_user_id', 'onb_bp_prov_first_open_actor_fk')
                        ->references('id')
                        ->on('users')
                        ->nullOnDelete();
                });
            } catch (\Throwable) {
                // Ignore duplicate/unsupported FK errors; column existence means this migration
                // must not block deploy.
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenant_onboarding_blueprint_provisionings')) {
            return;
        }

        Schema::table('tenant_onboarding_blueprint_provisionings', function (Blueprint $table): void {
            if (Schema::hasColumn('tenant_onboarding_blueprint_provisionings', 'first_open_opened_path')) {
                $table->dropColumn('first_open_opened_path');
            }
            if (Schema::hasColumn('tenant_onboarding_blueprint_provisionings', 'first_open_payload_anchor')) {
                $table->dropColumn('first_open_payload_anchor');
            }
            if (Schema::hasColumn('tenant_onboarding_blueprint_provisionings', 'first_open_acknowledged_by_user_id')) {
                try {
                    $table->dropForeign('onb_bp_prov_first_open_actor_fk');
                } catch (\Throwable) {
                    // ignore
                }
                $table->dropColumn('first_open_acknowledged_by_user_id');
            }
            if (Schema::hasColumn('tenant_onboarding_blueprint_provisionings', 'first_opened_at')) {
                $table->dropColumn('first_opened_at');
            }
        });
    }
};
