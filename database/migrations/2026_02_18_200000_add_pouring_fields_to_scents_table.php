<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scents', function (Blueprint $table) {
            if (!Schema::hasColumn('scents', 'display_name')) {
                $table->string('display_name')->nullable()->after('name');
            }
            if (!Schema::hasColumn('scents', 'oil_reference_name')) {
                $table->string('oil_reference_name')->nullable()->after('display_name');
            }
            if (!Schema::hasColumn('scents', 'abbreviation')) {
                $table->string('abbreviation')->nullable()->after('oil_reference_name');
            }
            if (!Schema::hasColumn('scents', 'is_blend')) {
                $table->boolean('is_blend')->default(false)->after('abbreviation');
            }
            if (!Schema::hasColumn('scents', 'is_wholesale_custom')) {
                $table->boolean('is_wholesale_custom')->default(false)->after('is_blend');
            }
            if (!Schema::hasColumn('scents', 'is_candle_club')) {
                $table->boolean('is_candle_club')->default(false)->after('is_wholesale_custom');
            }
        });
    }

    public function down(): void
    {
        Schema::table('scents', function (Blueprint $table) {
            foreach (['display_name', 'oil_reference_name', 'abbreviation', 'is_blend', 'is_wholesale_custom', 'is_candle_club'] as $col) {
                if (Schema::hasColumn('scents', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
