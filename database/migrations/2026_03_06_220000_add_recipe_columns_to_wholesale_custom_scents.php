<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wholesale_custom_scents')) {
            return;
        }

        Schema::table('wholesale_custom_scents', function (Blueprint $table) {
            if (! Schema::hasColumn('wholesale_custom_scents', 'oil_1')) {
                $table->string('oil_1')->nullable()->after('custom_scent_name');
            }
            if (! Schema::hasColumn('wholesale_custom_scents', 'oil_2')) {
                $table->string('oil_2')->nullable()->after('oil_1');
            }
            if (! Schema::hasColumn('wholesale_custom_scents', 'oil_3')) {
                $table->string('oil_3')->nullable()->after('oil_2');
            }
            if (! Schema::hasColumn('wholesale_custom_scents', 'total_oils')) {
                $table->unsignedSmallInteger('total_oils')->nullable()->after('oil_3');
            }
            if (! Schema::hasColumn('wholesale_custom_scents', 'abbreviation')) {
                $table->string('abbreviation', 50)->nullable()->after('total_oils');
            }
            if (! Schema::hasColumn('wholesale_custom_scents', 'top_level_recipe_json')) {
                $table->json('top_level_recipe_json')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('wholesale_custom_scents', 'resolved_recipe_json')) {
                $table->json('resolved_recipe_json')->nullable()->after('top_level_recipe_json');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wholesale_custom_scents')) {
            return;
        }

        Schema::table('wholesale_custom_scents', function (Blueprint $table) {
            if (Schema::hasColumn('wholesale_custom_scents', 'resolved_recipe_json')) {
                $table->dropColumn('resolved_recipe_json');
            }
            if (Schema::hasColumn('wholesale_custom_scents', 'top_level_recipe_json')) {
                $table->dropColumn('top_level_recipe_json');
            }
            if (Schema::hasColumn('wholesale_custom_scents', 'abbreviation')) {
                $table->dropColumn('abbreviation');
            }
            if (Schema::hasColumn('wholesale_custom_scents', 'total_oils')) {
                $table->dropColumn('total_oils');
            }
            if (Schema::hasColumn('wholesale_custom_scents', 'oil_3')) {
                $table->dropColumn('oil_3');
            }
            if (Schema::hasColumn('wholesale_custom_scents', 'oil_2')) {
                $table->dropColumn('oil_2');
            }
            if (Schema::hasColumn('wholesale_custom_scents', 'oil_1')) {
                $table->dropColumn('oil_1');
            }
        });
    }
};
