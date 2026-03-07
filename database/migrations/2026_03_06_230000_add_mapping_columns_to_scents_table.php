<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scents')) {
            return;
        }

        Schema::table('scents', function (Blueprint $table): void {
            if (! Schema::hasColumn('scents', 'canonical_scent_id')) {
                $table->foreignId('canonical_scent_id')
                    ->nullable()
                    ->after('blend_oil_count')
                    ->constrained('scents')
                    ->nullOnDelete();
            }

            if (Schema::hasTable('wholesale_custom_scents') && ! Schema::hasColumn('scents', 'source_wholesale_custom_scent_id')) {
                $table->foreignId('source_wholesale_custom_scent_id')
                    ->nullable()
                    ->after('canonical_scent_id')
                    ->constrained('wholesale_custom_scents')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('scents', 'recipe_components_json')) {
                $table->json('recipe_components_json')->nullable()->after('source_wholesale_custom_scent_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('scents')) {
            return;
        }

        Schema::table('scents', function (Blueprint $table): void {
            if (Schema::hasColumn('scents', 'recipe_components_json')) {
                $table->dropColumn('recipe_components_json');
            }

            if (Schema::hasColumn('scents', 'source_wholesale_custom_scent_id')) {
                $table->dropConstrainedForeignId('source_wholesale_custom_scent_id');
            }

            if (Schema::hasColumn('scents', 'canonical_scent_id')) {
                $table->dropConstrainedForeignId('canonical_scent_id');
            }
        });
    }
};

