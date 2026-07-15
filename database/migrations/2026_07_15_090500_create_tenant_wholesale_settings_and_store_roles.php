<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shopify_stores', 'store_role')) {
            Schema::table('shopify_stores', function (Blueprint $table): void {
                $table->string('store_role', 24)->default('retail')->after('store_key');
                $table->index(['tenant_id', 'store_role'], 'shopify_stores_tenant_role_index');
            });

            DB::table('shopify_stores')
                ->whereRaw("LOWER(COALESCE(store_key, '')) = 'wholesale'")
                ->update(['store_role' => 'wholesale']);
        }

        if (! Schema::hasTable('tenant_wholesale_settings')) {
            Schema::create('tenant_wholesale_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('shopify_store_id')->unique()->constrained('shopify_stores')->cascadeOnDelete();
                $table->string('qualification_mode', 40)->default('dedicated_store');
                $table->json('product_categories')->nullable();
                $table->json('discovery_keywords')->nullable();
                $table->boolean('website_enrichment_enabled')->default(false);
                $table->timestamp('confirmed_at');
                $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['qualification_mode', 'confirmed_at'], 'tenant_wholesale_settings_active_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_wholesale_settings');

        if (Schema::hasColumn('shopify_stores', 'store_role')) {
            Schema::table('shopify_stores', function (Blueprint $table): void {
                $table->dropIndex('shopify_stores_tenant_role_index');
                $table->dropColumn('store_role');
            });
        }
    }
};
