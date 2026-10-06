<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_profiles') || Schema::hasColumn('marketing_profiles', 'archived_at')) {
            return;
        }

        Schema::table('marketing_profiles', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('merged_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('marketing_profiles') || ! Schema::hasColumn('marketing_profiles', 'archived_at')) {
            return;
        }

        Schema::table('marketing_profiles', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
