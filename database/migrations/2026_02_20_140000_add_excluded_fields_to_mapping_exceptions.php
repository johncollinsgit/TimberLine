<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mapping_exceptions', function (Blueprint $table) {
            if (!Schema::hasColumn('mapping_exceptions', 'excluded_at')) {
                $table->timestamp('excluded_at')->nullable()->after('resolved_at');
            }
            if (!Schema::hasColumn('mapping_exceptions', 'excluded_by')) {
                $table->string('excluded_by')->nullable()->after('excluded_at');
            }
            if (!Schema::hasColumn('mapping_exceptions', 'excluded_reason')) {
                $table->string('excluded_reason')->nullable()->after('excluded_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mapping_exceptions', function (Blueprint $table) {
            if (Schema::hasColumn('mapping_exceptions', 'excluded_reason')) {
                $table->dropColumn('excluded_reason');
            }
            if (Schema::hasColumn('mapping_exceptions', 'excluded_by')) {
                $table->dropColumn('excluded_by');
            }
            if (Schema::hasColumn('mapping_exceptions', 'excluded_at')) {
                $table->dropColumn('excluded_at');
            }
        });
    }
};
