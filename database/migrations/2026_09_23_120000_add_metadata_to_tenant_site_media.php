<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_site_media') && ! Schema::hasColumn('tenant_site_media', 'metadata')) {
            Schema::table('tenant_site_media', function (Blueprint $table): void {
                $table->json('metadata')->nullable()->after('is_starter');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tenant_site_media') && Schema::hasColumn('tenant_site_media', 'metadata')) {
            Schema::table('tenant_site_media', function (Blueprint $table): void {
                $table->dropColumn('metadata');
            });
        }
    }
};
