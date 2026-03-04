<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('blend_components', ['blend_id', 'base_oil_id'], 'unique')) {
            return;
        }

        Schema::table('blend_components', function (Blueprint $table): void {
            $table->unique(['blend_id', 'base_oil_id'], 'blend_components_blend_baseoil_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('blend_components', 'blend_components_blend_baseoil_unique')) {
            return;
        }

        Schema::table('blend_components', function (Blueprint $table): void {
            $table->dropUnique('blend_components_blend_baseoil_unique');
        });
    }
};
