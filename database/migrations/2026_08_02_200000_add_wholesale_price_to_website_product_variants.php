<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('website_product_variants') && ! Schema::hasColumn('website_product_variants', 'wholesale_price_cents')) {
            Schema::table('website_product_variants', function (Blueprint $table): void {
                $table->unsignedInteger('wholesale_price_cents')->nullable()->after('price_cents');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('website_product_variants') && Schema::hasColumn('website_product_variants', 'wholesale_price_cents')) {
            Schema::table('website_product_variants', function (Blueprint $table): void {
                $table->dropColumn('wholesale_price_cents');
            });
        }
    }
};
