<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_import_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_import_runs', 'tenant_id')) {
                $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
                $table->index('tenant_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketing_import_runs', function (Blueprint $table): void {
            if (Schema::hasColumn('marketing_import_runs', 'tenant_id')) {
                $table->dropForeign(['tenant_id']);
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
        });
    }
};
