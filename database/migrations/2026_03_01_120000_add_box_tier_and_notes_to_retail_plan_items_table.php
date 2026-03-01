<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retail_plan_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('retail_plan_items', 'box_tier')) {
                $table->string('box_tier')->nullable()->after('status');
            }

            if (! Schema::hasColumn('retail_plan_items', 'notes')) {
                $table->text('notes')->nullable()->after('box_tier');
            }
        });
    }

    public function down(): void
    {
        Schema::table('retail_plan_items', function (Blueprint $table): void {
            if (Schema::hasColumn('retail_plan_items', 'notes')) {
                $table->dropColumn('notes');
            }

            if (Schema::hasColumn('retail_plan_items', 'box_tier')) {
                $table->dropColumn('box_tier');
            }
        });
    }
};
