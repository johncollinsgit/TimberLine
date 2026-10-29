<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('field_service_materials')) {
            return;
        }

        if (! Schema::hasColumn('field_service_materials', 'review_client_uuid')) {
            Schema::table('field_service_materials', function (Blueprint $table): void {
                $table->uuid('review_client_uuid')->nullable()->after('requested_by_user_id');
            });
        }

        if (Schema::hasColumn('field_service_materials', 'review_client_uuid')
            && Schema::hasColumn('field_service_materials', 'requested_by_user_id')
            && ! Schema::hasIndex('field_service_materials', 'fs_material_review_idempotency_uq')) {
            Schema::table('field_service_materials', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'requested_by_user_id', 'review_client_uuid'], 'fs_material_review_idempotency_uq');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('field_service_materials')) {
            return;
        }
        if (Schema::hasIndex('field_service_materials', 'fs_material_review_idempotency_uq')) {
            Schema::table('field_service_materials', fn (Blueprint $table) => $table->dropUnique('fs_material_review_idempotency_uq'));
        }
        if (Schema::hasColumn('field_service_materials', 'review_client_uuid')) {
            Schema::table('field_service_materials', fn (Blueprint $table) => $table->dropColumn('review_client_uuid'));
        }
    }
};
