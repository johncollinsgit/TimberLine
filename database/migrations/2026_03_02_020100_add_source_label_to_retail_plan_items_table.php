<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('retail_plan_items', 'source_label')) {
            Schema::table('retail_plan_items', function (Blueprint $table): void {
                $table->string('source_label')->nullable()->after('source');
                $table->index('source_label');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('retail_plan_items', 'source_label')) {
            Schema::table('retail_plan_items', function (Blueprint $table): void {
                $table->dropIndex(['source_label']);
                $table->dropColumn('source_label');
            });
        }
    }
};
