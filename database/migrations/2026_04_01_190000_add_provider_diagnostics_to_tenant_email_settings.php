<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_email_settings')) {
            return;
        }

        Schema::table('tenant_email_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_email_settings', 'provider_status_checked_at')) {
                $table->timestamp('provider_status_checked_at')->nullable()->after('provider_status');
            }

            if (! Schema::hasColumn('tenant_email_settings', 'provider_status_message')) {
                $table->text('provider_status_message')->nullable()->after('provider_status_checked_at');
            }
        });

        DB::table('tenant_email_settings')
            ->whereNull('provider_status_checked_at')
            ->whereNotNull('last_tested_at')
            ->update(['provider_status_checked_at' => DB::raw('last_tested_at')]);

        DB::table('tenant_email_settings')
            ->whereNull('provider_status_message')
            ->whereNotNull('last_error')
            ->update(['provider_status_message' => DB::raw('last_error')]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenant_email_settings')) {
            return;
        }

        Schema::table('tenant_email_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('tenant_email_settings', 'provider_status_message')) {
                $table->dropColumn('provider_status_message');
            }

            if (Schema::hasColumn('tenant_email_settings', 'provider_status_checked_at')) {
                $table->dropColumn('provider_status_checked_at');
            }
        });
    }
};
