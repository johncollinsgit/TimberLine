<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_equipment')) {
            return;
        }

        Schema::table('customer_equipment', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_equipment', 'external_source')) {
                $table->string('external_source', 80)->nullable()->after('status');
            }
            if (! Schema::hasColumn('customer_equipment', 'external_id')) {
                $table->string('external_id', 255)->nullable()->after('external_source');
            }
        });

        if (! Schema::hasIndex('customer_equipment', 'equipment_tenant_external_unique')) {
            Schema::table('customer_equipment', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'external_source', 'external_id'], 'equipment_tenant_external_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('customer_equipment') || ! Schema::hasColumn('customer_equipment', 'external_id')) {
            return;
        }

        if (Schema::hasIndex('customer_equipment', 'equipment_tenant_external_unique')) {
            Schema::table('customer_equipment', function (Blueprint $table): void {
                $table->dropUnique('equipment_tenant_external_unique');
            });
        }

        Schema::table('customer_equipment', function (Blueprint $table): void {
            $table->dropColumn(['external_source', 'external_id']);
        });
    }
};
