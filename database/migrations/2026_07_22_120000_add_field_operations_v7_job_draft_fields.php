<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_service_jobs', function (Blueprint $table): void {
            $table->string('project_manager_name')->nullable()->after('customer_phone');
            $table->string('project_manager_company')->nullable()->after('project_manager_name');
            $table->string('project_manager_phone', 80)->nullable()->after('project_manager_company');
            $table->string('project_manager_email')->nullable()->after('project_manager_phone');
        });

        Schema::table('field_service_work_candidates', function (Blueprint $table): void {
            $table->string('customer_email')->nullable()->after('customer_name');
            $table->string('customer_phone', 80)->nullable()->after('customer_email');
            $table->string('service_address_line_1')->nullable()->after('description');
            $table->string('service_address_line_2')->nullable()->after('service_address_line_1');
            $table->string('service_city', 120)->nullable()->after('service_address_line_2');
            $table->string('service_state', 80)->nullable()->after('service_city');
            $table->string('service_postal_code', 40)->nullable()->after('service_state');
            $table->string('service_country', 80)->nullable()->after('service_postal_code');
            $table->string('priority', 20)->default('normal')->after('service_country');
            $table->timestamp('scheduled_for')->nullable()->after('priority');
            $table->timestamp('scheduled_end_at')->nullable()->after('scheduled_for');
            $table->foreignId('assigned_user_id')->nullable()->after('scheduled_end_at')->constrained('users')->nullOnDelete();
            $table->json('participant_user_ids')->nullable()->after('assigned_user_id');
            $table->string('project_manager_name')->nullable()->after('participant_user_ids');
            $table->string('project_manager_company')->nullable()->after('project_manager_name');
            $table->string('project_manager_phone', 80)->nullable()->after('project_manager_company');
            $table->string('project_manager_email')->nullable()->after('project_manager_phone');
            $table->timestamp('archived_at')->nullable()->after('reviewed_at');
        });

        $tenantId = DB::table('tenants')->where('slug', 'collins-electric')->value('id');
        if ($tenantId) {
            $entitlement = DB::table('tenant_module_entitlements')
                ->where('tenant_id', $tenantId)
                ->where('module_key', 'field_service')
                ->first();
            if ($entitlement) {
                $metadata = json_decode((string) ($entitlement->metadata ?? '{}'), true);
                $metadata = is_array($metadata) ? $metadata : [];
                $metadata['member_job_visibility'] = 'all_operational';
                $metadata['experience_version'] = 3;
                $metadata['field_service_contract_version'] = 7;
                DB::table('tenant_module_entitlements')->where('id', $entitlement->id)->update([
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('field_service_work_candidates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropColumn([
                'customer_email', 'customer_phone', 'service_address_line_1', 'service_address_line_2',
                'service_city', 'service_state', 'service_postal_code', 'service_country', 'priority',
                'scheduled_for', 'scheduled_end_at', 'participant_user_ids', 'project_manager_name',
                'project_manager_company', 'project_manager_phone', 'project_manager_email', 'archived_at',
            ]);
        });

        Schema::table('field_service_jobs', function (Blueprint $table): void {
            $table->dropColumn(['project_manager_name', 'project_manager_company', 'project_manager_phone', 'project_manager_email']);
        });
    }
};
