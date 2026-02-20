<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sizes', function (Blueprint $table) {
            if (!Schema::hasColumn('sizes', 'wholesale_price')) {
                $table->decimal('wholesale_price', 8, 2)->nullable()->after('label');
            }
            if (!Schema::hasColumn('sizes', 'retail_price')) {
                $table->decimal('retail_price', 8, 2)->nullable()->after('wholesale_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sizes', function (Blueprint $table) {
            if (Schema::hasColumn('sizes', 'retail_price')) {
                $table->dropColumn('retail_price');
            }
            if (Schema::hasColumn('sizes', 'wholesale_price')) {
                $table->dropColumn('wholesale_price');
            }
        });
    }
};
