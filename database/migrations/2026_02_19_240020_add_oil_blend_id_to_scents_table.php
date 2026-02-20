<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scents', function (Blueprint $table) {
            if (!Schema::hasColumn('scents', 'oil_blend_id')) {
                $table->foreignId('oil_blend_id')->nullable()->after('is_blend')->constrained('blends')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('scents', function (Blueprint $table) {
            if (Schema::hasColumn('scents', 'oil_blend_id')) {
                $table->dropConstrainedForeignId('oil_blend_id');
            }
        });
    }
};
