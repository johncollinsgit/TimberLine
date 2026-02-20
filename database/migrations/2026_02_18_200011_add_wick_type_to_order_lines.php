<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            if (!Schema::hasColumn('order_lines', 'wick_type')) {
                $table->string('wick_type')->nullable()->after('raw_variant');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            if (Schema::hasColumn('order_lines', 'wick_type')) {
                $table->dropColumn('wick_type');
            }
        });
    }
};
