<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retail_plans', function (Blueprint $table) {
            if (!Schema::hasColumn('retail_plans', 'queue_type')) {
                $table->string('queue_type')->default('retail')->after('status');
            }
        });

        if (Schema::hasColumn('retail_plans', 'queue_type')) {
            DB::table('retail_plans')
                ->whereNull('queue_type')
                ->update(['queue_type' => 'retail']);
        }
    }

    public function down(): void
    {
        Schema::table('retail_plans', function (Blueprint $table) {
            if (Schema::hasColumn('retail_plans', 'queue_type')) {
                $table->dropColumn('queue_type');
            }
        });
    }
};
