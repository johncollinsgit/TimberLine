<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_stores', function (Blueprint $table): void {
            $table->json('storefront_widget_settings')->nullable()->after('scopes');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_stores', function (Blueprint $table): void {
            $table->dropColumn('storefront_widget_settings');
        });
    }
};
