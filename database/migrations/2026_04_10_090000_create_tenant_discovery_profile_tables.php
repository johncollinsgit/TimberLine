<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_discovery_profiles')) {
            Schema::create('tenant_discovery_profiles', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('primary_brand_name')->nullable();
                $table->json('alternate_brand_names')->nullable();
                $table->string('wholesale_brand_label')->nullable();
                $table->string('retail_brand_label')->nullable();
                $table->text('short_brand_summary')->nullable();
                $table->text('long_form_description')->nullable();
                $table->string('support_email')->nullable();
                $table->string('support_phone')->nullable();
                $table->json('social_profiles')->nullable();
                $table->string('primary_logo_url')->nullable();
                $table->json('brand_keywords')->nullable();
                $table->json('why_choose_us_bullets')->nullable();
                $table->json('domain_map')->nullable();
                $table->json('canonical_rules')->nullable();
                $table->json('geography')->nullable();
                $table->json('audience_map')->nullable();
                $table->json('trust_facts')->nullable();
                $table->json('merchant_signals')->nullable();
                $table->json('placeholders')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['tenant_id'], 'tenant_discovery_profiles_tenant_unique');
                $table->index(['is_active'], 'tenant_discovery_profiles_active_idx');
            });
        }

        if (! Schema::hasTable('tenant_discovery_pages')) {
            Schema::create('tenant_discovery_pages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('page_key');
                $table->string('page_type', 80)->nullable();
                $table->string('title');
                $table->text('meta_description')->nullable();
                $table->text('summary')->nullable();
                $table->string('intent_label', 120)->nullable();
                $table->string('audience_type', 120)->nullable();
                $table->string('recommended_domain_role', 80)->nullable();
                $table->string('canonical_path')->nullable();
                $table->string('cta_label')->nullable();
                $table->string('cta_url')->nullable();
                $table->json('service_regions')->nullable();
                $table->json('keywords')->nullable();
                $table->json('faq_items')->nullable();
                $table->json('metadata')->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->boolean('is_public')->default(true);
                $table->boolean('is_indexable')->default(true);
                $table->timestamps();

                $table->unique(['tenant_id', 'page_key'], 'tenant_discovery_pages_tenant_key_unique');
                $table->index(['tenant_id', 'is_public', 'is_indexable'], 'tenant_discovery_pages_public_idx');
                $table->index(['tenant_id', 'page_type'], 'tenant_discovery_pages_type_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_discovery_pages');
        Schema::dropIfExists('tenant_discovery_profiles');
    }
};
