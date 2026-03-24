<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_profile_wishlist_items')) {
            return;
        }

        Schema::create('marketing_profile_wishlist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('marketing_profile_id')->constrained('marketing_profiles');
            $table->string('provider', 80)->default('backstage')->index();
            $table->string('integration', 80)->default('native')->index();
            $table->string('store_key', 80)->index();
            $table->string('product_id', 120)->index();
            $table->string('product_variant_id', 120)->nullable()->index();
            $table->string('product_handle', 160)->nullable()->index();
            $table->string('product_title')->nullable();
            $table->string('product_url', 500)->nullable();
            $table->string('status', 40)->default('active')->index();
            $table->string('source', 120)->nullable()->index();
            $table->string('source_surface', 120)->nullable()->index();
            $table->string('source_ref', 190)->nullable()->index();
            $table->timestamp('added_at')->nullable()->index();
            $table->timestamp('last_added_at')->nullable()->index();
            $table->timestamp('removed_at')->nullable()->index();
            $table->timestamp('source_synced_at')->nullable()->index();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(
                ['marketing_profile_id', 'store_key', 'product_id'],
                'mpwi_profile_store_product_unique'
            );
            $table->index(['marketing_profile_id', 'status'], 'mpwi_profile_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_profile_wishlist_items');
    }
};
