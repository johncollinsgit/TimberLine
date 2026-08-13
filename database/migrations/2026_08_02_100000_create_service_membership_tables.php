<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_membership_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->string('terms_contact_email')->nullable();
            $table->string('terms_contact_phone', 40)->nullable();
            $table->string('service_area_label')->nullable();
            $table->json('service_area')->nullable();
            $table->json('customer_experience')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id', 'sms_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('service_plan_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('slug', 120);
            $table->string('name');
            $table->string('badge', 80)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 40)->default('draft');
            $table->unsignedInteger('current_version')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'slug'], 'spt_tenant_slug_uq');
            $table->index(['tenant_id', 'status', 'sort_order'], 'spt_tenant_status_idx');
            $table->foreign('tenant_id', 'spt_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'spt_creator_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('service_plan_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('service_plan_template_id');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->char('content_hash', 64);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['service_plan_template_id', 'version'], 'spv_template_version_uq');
            $table->index(['tenant_id', 'published_at'], 'spv_tenant_published_idx');
            $table->foreign('tenant_id', 'spv_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('service_plan_template_id', 'spv_template_fk')->references('id')->on('service_plan_templates')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'spv_creator_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('service_plan_version_addons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('service_plan_version_id');
            $table->unsignedBigInteger('field_service_price_book_item_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('billing_frequency', 30)->default('one_time');
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedSmallInteger('max_quantity')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['service_plan_version_id', 'sort_order'], 'spva_version_sort_idx');
            $table->foreign('tenant_id', 'spva_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('service_plan_version_id', 'spva_version_fk')->references('id')->on('service_plan_versions')->cascadeOnDelete();
            $table->foreign('field_service_price_book_item_id', 'spva_price_item_fk')->references('id')->on('field_service_price_book_items')->nullOnDelete();
        });

        Schema::create('service_plan_version_media', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('service_plan_version_id');
            $table->unsignedBigInteger('workspace_asset_id');
            $table->string('visibility', 30)->default('customer_offer');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('caption', 500)->nullable();
            $table->string('alt_text', 500);
            $table->timestamps();
            $table->unique(['service_plan_version_id', 'workspace_asset_id'], 'spvm_version_asset_uq');
            $table->foreign('tenant_id', 'spvm_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('service_plan_version_id', 'spvm_version_fk')->references('id')->on('service_plan_versions')->cascadeOnDelete();
            $table->foreign('workspace_asset_id', 'spvm_asset_fk')->references('id')->on('workspace_assets')->cascadeOnDelete();
        });

        Schema::create('service_plan_offers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('marketing_profile_id');
            $table->unsignedBigInteger('service_plan_version_id');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->char('portal_token_hash', 64)->unique();
            $table->string('status', 40)->default('draft');
            $table->json('snapshot');
            $table->json('selected_addons')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_name')->nullable();
            $table->string('accepted_ip', 45)->nullable();
            $table->string('accepted_user_agent', 500)->nullable();
            $table->timestamp('invoice_requested_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'expires_at'], 'spo_tenant_status_idx');
            $table->foreign('tenant_id', 'spo_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('marketing_profile_id', 'spo_profile_fk')->references('id')->on('marketing_profiles')->cascadeOnDelete();
            $table->foreign('service_plan_version_id', 'spo_version_fk')->references('id')->on('service_plan_versions')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'spo_creator_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('customer_service_memberships', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('marketing_profile_id');
            $table->unsignedBigInteger('service_plan_offer_id')->nullable();
            $table->unsignedBigInteger('service_plan_version_id');
            $table->unsignedBigInteger('activated_by_user_id')->nullable();
            $table->string('status', 40)->default('pending_activation');
            $table->json('snapshot');
            $table->json('selected_addons')->nullable();
            $table->string('external_invoice_reference', 255)->nullable();
            $table->string('external_invoice_url', 2048)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('renews_on')->nullable();
            $table->date('next_visit_due_on')->nullable();
            $table->string('priority', 30)->default('normal');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 1000)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'next_visit_due_on'], 'csm_tenant_due_idx');
            $table->foreign('tenant_id', 'csm_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('marketing_profile_id', 'csm_profile_fk')->references('id')->on('marketing_profiles')->cascadeOnDelete();
            $table->foreign('service_plan_offer_id', 'csm_offer_fk')->references('id')->on('service_plan_offers')->nullOnDelete();
            $table->foreign('service_plan_version_id', 'csm_version_fk')->references('id')->on('service_plan_versions')->restrictOnDelete();
            $table->foreign('activated_by_user_id', 'csm_activator_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('customer_service_membership_visits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('customer_service_membership_id');
            $table->unsignedBigInteger('customer_equipment_id')->nullable();
            $table->unsignedBigInteger('field_service_job_id')->nullable();
            $table->string('period_key', 20);
            $table->date('due_on');
            $table->string('status', 40)->default('due');
            $table->timestamp('credited_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->string('exception_reason', 1000)->nullable();
            $table->timestamps();
            $table->unique(['customer_service_membership_id', 'period_key'], 'csmv_membership_period_uq');
            $table->index(['tenant_id', 'status', 'due_on'], 'csmv_tenant_due_idx');
            $table->foreign('tenant_id', 'csmv_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('customer_service_membership_id', 'csmv_membership_fk')->references('id')->on('customer_service_memberships')->cascadeOnDelete();
            $table->foreign('customer_equipment_id', 'csmv_equipment_fk')->references('id')->on('customer_equipment')->nullOnDelete();
            $table->foreign('field_service_job_id', 'csmv_job_fk')->references('id')->on('field_service_jobs')->nullOnDelete();
        });

        Schema::create('service_membership_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('customer_service_membership_id')->nullable();
            $table->unsignedBigInteger('service_plan_offer_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('event_type', 100);
            $table->json('context')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'event_type', 'created_at'], 'sme_tenant_event_idx');
            $table->foreign('tenant_id', 'sme_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('customer_service_membership_id', 'sme_membership_fk')->references('id')->on('customer_service_memberships')->nullOnDelete();
            $table->foreign('service_plan_offer_id', 'sme_offer_fk')->references('id')->on('service_plan_offers')->nullOnDelete();
            $table->foreign('actor_user_id', 'sme_actor_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_membership_events');
        Schema::dropIfExists('customer_service_membership_visits');
        Schema::dropIfExists('customer_service_memberships');
        Schema::dropIfExists('service_plan_offers');
        Schema::dropIfExists('service_plan_version_media');
        Schema::dropIfExists('service_plan_version_addons');
        Schema::dropIfExists('service_plan_versions');
        Schema::dropIfExists('service_plan_templates');
        Schema::dropIfExists('service_membership_settings');
    }
};
