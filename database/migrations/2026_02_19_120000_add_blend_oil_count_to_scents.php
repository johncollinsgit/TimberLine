<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scents', function (Blueprint $table) {
            if (!Schema::hasColumn('scents', 'blend_oil_count')) {
                $table->unsignedSmallInteger('blend_oil_count')->nullable()->after('is_blend');
            }
        });
    }

    public function down(): void
    {
        Schema::table('scents', function (Blueprint $table) {
            if (Schema::hasColumn('scents', 'blend_oil_count')) {
                $table->dropColumn('blend_oil_count');
            }
        });
    }
};
