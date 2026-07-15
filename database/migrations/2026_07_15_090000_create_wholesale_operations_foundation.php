<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wholesale_order_classifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('status', 40)->default('pending_review');
            $table->string('classification_basis', 120)->nullable();
            $table->json('evidence')->nullable();
            $table->foreignId('classified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('classified_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'order_id'], 'wholesale_order_classifications_tenant_order_unique');
            $table->index(['tenant_id', 'status'], 'wholesale_order_classifications_tenant_status_idx');
        });

        Schema::create('wholesale_prospect_discovery_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('status', 40)->default('queued');
            $table->string('search_region', 190);
            $table->unsignedInteger('radius_meters')->nullable();
            $table->json('categories')->nullable();
            $table->json('search_phrases');
            $table->unsignedInteger('maximum_results')->default(20);
            $table->boolean('website_enrichment')->default(false);
            $table->boolean('instagram_enrichment')->default(false);
            $table->foreignId('assigned_owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('campaign_name', 190)->nullable();
            $table->decimal('estimated_api_cost', 10, 4)->default(0);
            $table->decimal('actual_api_cost', 10, 4)->default(0);
            $table->unsignedInteger('api_request_count')->default(0);
            $table->unsignedInteger('results_discovered')->default(0);
            $table->unsignedInteger('results_created')->default(0);
            $table->unsignedInteger('duplicates_suppressed')->default(0);
            $table->boolean('large_search_confirmed')->default(false);
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->json('source_log')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'wholesale_prospect_runs_tenant_status_idx');
        });

        Schema::create('wholesale_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('canonical_key', 255);
            $table->string('company_name', 190);
            $table->string('contact_name', 190)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 80)->nullable();
            $table->string('website', 500)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 80)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->string('status', 40)->default('confirmed');
            $table->string('source_prospect_public_id', 36)->nullable();
            $table->string('existing_customer_key', 255)->nullable();
            $table->string('original_discovery_source', 80)->nullable();
            $table->json('conversion_snapshot')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'canonical_key'], 'wholesale_accounts_tenant_key_unique');
            $table->index(['tenant_id', 'status'], 'wholesale_accounts_tenant_status_idx');
        });

        Schema::create('wholesale_prospects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('business_name', 190);
            $table->string('status', 60)->default('newly_discovered');
            $table->string('primary_category', 120)->nullable();
            $table->json('secondary_categories')->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 80)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('phone', 80)->nullable();
            $table->string('website', 500)->nullable();
            $table->string('public_business_email', 190)->nullable();
            $table->string('contact_form_url', 500)->nullable();
            $table->string('instagram_handle', 120)->nullable();
            $table->string('facebook_page', 500)->nullable();
            $table->string('google_place_id', 255)->nullable();
            $table->string('google_maps_url', 500)->nullable();
            $table->string('operational_status', 80)->nullable();
            $table->string('discovery_source', 80);
            $table->string('discovery_query', 255)->nullable();
            $table->timestamp('discovered_at')->nullable();
            $table->foreignId('discovery_run_id')->nullable()->constrained('wholesale_prospect_discovery_runs')->nullOnDelete();
            $table->foreignId('assigned_owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('fit_score')->default(0);
            $table->unsignedTinyInteger('fit_confidence')->default(0);
            $table->json('fit_explanation')->nullable();
            $table->string('opportunity_priority', 40)->default('normal');
            $table->text('suggested_product_positioning')->nullable();
            $table->text('suggested_opening_message_topic')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamp('last_contact_at')->nullable();
            $table->timestamp('next_action_at')->nullable();
            $table->boolean('do_not_contact')->default(false);
            $table->string('rejection_reason', 255)->nullable();
            $table->string('duplicate_status', 60)->nullable();
            $table->string('existing_customer_match', 255)->nullable();
            $table->foreignId('converted_wholesale_account_id')->nullable()->constrained('wholesale_accounts')->nullOnDelete();
            $table->foreignId('converted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('source_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'google_place_id'], 'wholesale_prospects_tenant_place_unique');
            $table->index(['tenant_id', 'status', 'fit_score'], 'wholesale_prospects_tenant_review_idx');
        });

        Schema::create('wholesale_prospect_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('wholesale_prospect_id')->constrained('wholesale_prospects')->cascadeOnDelete();
            $table->string('source_type', 80);
            $table->string('source_url', 1000)->nullable();
            $table->string('signal_type', 120);
            $table->text('summary');
            $table->boolean('supports_fit')->nullable();
            $table->timestamp('observed_at');
            $table->json('source_reference')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'wholesale_prospect_id'], 'wholesale_prospect_evidence_tenant_prospect_idx');
        });

        Schema::create('wholesale_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('target_type', 40);
            $table->string('target_key', 255);
            $table->string('suggestion_type', 100);
            $table->string('title', 255);
            $table->text('recommended_action');
            $table->string('priority', 40)->default('normal');
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->json('supporting_evidence');
            $table->decimal('estimated_opportunity', 12, 2)->nullable();
            $table->timestamp('suggested_follow_up_at')->nullable();
            $table->text('reason');
            $table->string('evidence_fingerprint', 64);
            $table->string('status', 40)->default('pending');
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('last_evaluated_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'evidence_fingerprint'], 'wholesale_suggestions_tenant_fingerprint_unique');
            $table->index(['tenant_id', 'status', 'priority'], 'wholesale_suggestions_tenant_queue_idx');
            $table->index(['tenant_id', 'target_type', 'target_key'], 'wholesale_suggestions_tenant_target_idx');
        });

        Schema::create('wholesale_follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->foreignId('wholesale_suggestion_id')->nullable()->constrained('wholesale_suggestions')->nullOnDelete();
            $table->string('target_type', 40);
            $table->string('target_key', 255);
            $table->string('follow_up_type', 80)->default('sales_review');
            $table->string('title', 255);
            $table->string('status', 40)->default('open');
            $table->string('priority', 40)->default('normal');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('outcome', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'due_at'], 'wholesale_follow_ups_tenant_due_idx');
            $table->index(['tenant_id', 'target_type', 'target_key'], 'wholesale_follow_ups_tenant_target_idx');
        });

        Schema::create('wholesale_suggestion_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('wholesale_suggestion_id')->constrained('wholesale_suggestions')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 60);
            $table->text('note')->nullable();
            $table->string('dismissal_reason', 255)->nullable();
            $table->foreignId('resulting_follow_up_id')->nullable()->constrained('wholesale_follow_ups')->nullOnDelete();
            $table->json('original_suggestion');
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['tenant_id', 'wholesale_suggestion_id'], 'wholesale_suggestion_decisions_tenant_suggestion_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_suggestion_decisions');
        Schema::dropIfExists('wholesale_follow_ups');
        Schema::dropIfExists('wholesale_suggestions');
        Schema::dropIfExists('wholesale_prospect_evidence');
        Schema::dropIfExists('wholesale_prospects');
        Schema::dropIfExists('wholesale_accounts');
        Schema::dropIfExists('wholesale_prospect_discovery_runs');
        Schema::dropIfExists('wholesale_order_classifications');
    }
};
