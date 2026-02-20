<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pour_batches', function (Blueprint $table) {
            $table->decimal('alcohol_total_grams', 10, 2)->default(0)->after('oil_total_grams');
            $table->decimal('water_total_grams', 10, 2)->default(0)->after('alcohol_total_grams');
        });

        Schema::table('pour_batch_lines', function (Blueprint $table) {
            $table->decimal('alcohol_grams', 10, 2)->default(0)->after('oil_grams');
            $table->decimal('water_grams', 10, 2)->default(0)->after('alcohol_grams');
        });
    }

    public function down(): void
    {
        Schema::table('pour_batch_lines', function (Blueprint $table) {
            $table->dropColumn(['alcohol_grams', 'water_grams']);
        });

        Schema::table('pour_batches', function (Blueprint $table) {
            $table->dropColumn(['alcohol_total_grams', 'water_total_grams']);
        });
    }
};
