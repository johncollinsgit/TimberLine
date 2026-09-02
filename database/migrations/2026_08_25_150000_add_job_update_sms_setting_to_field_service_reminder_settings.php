<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('field_service_reminder_settings')) {
            return;
        }

        if (! Schema::hasColumn('field_service_reminder_settings', 'job_update_sms')) {
            Schema::table('field_service_reminder_settings', function (Blueprint $table): void {
                $table->json('job_update_sms')->nullable();
            });
        }

        if (! Schema::hasTable('tenants') || ! Schema::hasColumn('tenants', 'slug')) {
            return;
        }

        $collinsTenantId = DB::table('tenants')->where('slug', 'collins-electric')->value('id');

        if ($collinsTenantId !== null) {
            DB::table('field_service_reminder_settings')
                ->where('tenant_id', $collinsTenantId)
                ->whereNull('job_update_sms')
                ->update([
                    'job_update_sms' => json_encode([
                        'phone' => '+18646406642',
                        'enabled' => false,
                    ], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('field_service_reminder_settings') && Schema::hasColumn('field_service_reminder_settings', 'job_update_sms')) {
            Schema::table('field_service_reminder_settings', function (Blueprint $table): void {
                $table->dropColumn('job_update_sms');
            });
        }
    }
};
