<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retail_plan_items', function (Blueprint $table) {
            if (!Schema::hasColumn('retail_plan_items', 'inventory_quantity')) {
                $table->unsignedInteger('inventory_quantity')->default(0)->after('quantity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('retail_plan_items', function (Blueprint $table) {
            if (Schema::hasColumn('retail_plan_items', 'inventory_quantity')) {
                $table->dropColumn('inventory_quantity');
            }
        });
    }
};
