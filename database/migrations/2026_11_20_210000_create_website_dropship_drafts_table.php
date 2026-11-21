<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('website_dropship_drafts')) {
            $claim = collect(Schema::getIndexes('website_dropship_drafts'))->firstWhere('name', 'wdd_seller_order_uq');
            $tag = collect(Schema::getIndexes('website_dropship_drafts'))->firstWhere('name', 'wdd_correlation_tag_uq');
            if (($claim['unique'] ?? false) !== true || $claim['columns'] !== ['tenant_id', 'tenant_site_id', 'website_order_id']
                || ($tag['unique'] ?? false) !== true || $tag['columns'] !== ['correlation_tag']) {
                throw new RuntimeException('Unexpected supplier draft claim indexes.');
            }

            return;
        }

        Schema::create('website_dropship_drafts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('tenant_site_id');
            $table->unsignedBigInteger('website_order_id');
            $table->string('supplier_key', 120);
            $table->string('shop_id', 80);
            $table->string('shop_domain', 100);
            $table->string('correlation_tag', 60)->unique('wdd_correlation_tag_uq');
            $table->string('shopify_draft_id', 80)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'tenant_site_id', 'website_order_id'], 'wdd_seller_order_uq');
        });
    }

    public function down(): void
    {
        // Preserve claims and references across rollbacks. Removing them could allow duplicate drafts.
    }
};
