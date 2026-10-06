<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('field_service_time_sessions')
            || ! Schema::hasColumn('field_service_time_sessions', 'tenant_id')
            || ! Schema::hasColumn('field_service_time_sessions', 'clocked_in_at')
            || Schema::hasIndex('field_service_time_sessions', 'fs_time_tenant_clocked_idx')) {
            return;
        }

        Schema::table('field_service_time_sessions', function (Blueprint $table): void {
            $table->index(['tenant_id', 'clocked_in_at'], 'fs_time_tenant_clocked_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('field_service_time_sessions')
            || ! Schema::hasIndex('field_service_time_sessions', 'fs_time_tenant_clocked_idx')) {
            return;
        }

        Schema::table('field_service_time_sessions', function (Blueprint $table): void {
            $table->dropIndex('fs_time_tenant_clocked_idx');
        });
    }
};
