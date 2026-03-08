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
            if (! Schema::hasColumn('scents', 'notes')) {
                $table->text('notes')->nullable()->after('oil_reference_name');
            }

            if (! Schema::hasColumn('scents', 'availability_json')) {
                $table->json('availability_json')->nullable()->after('recipe_components_json');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('scents')) {
            return;
        }

        Schema::table('scents', function (Blueprint $table): void {
            if (Schema::hasColumn('scents', 'availability_json')) {
                $table->dropColumn('availability_json');
            }

            if (Schema::hasColumn('scents', 'notes')) {
                $table->dropColumn('notes');
            }
        });
    }
};

