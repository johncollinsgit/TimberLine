<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_module_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('module_key', 80)->default('subscriptions');
            $table->string('status', 40)->default('setup');
            $table->boolean('billing_scheduler_enabled')->default(false);
            $table->timestamp('billing_scheduler_enabled_at')->nullable();
            $table->string('shopify_store_key', 80)->nullable();
            $table->json('shopify_settings')->nullable();
            $table->json('recharge_settings')->nullable();
            $table->json('notification_settings')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'module_key'], 'subscription_module_settings_unique');
        });

        Schema::create('subscription_candle_club_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('commitment_months')->default(6);
            $table->unsignedInteger('allowed_pauses_per_commitment')->default(2);
            $table->json('pause_duration_options')->nullable();
            $table->unsignedInteger('renewal_reward_months')->default(6);
            $table->string('first_gift_product_variant_gid', 190)->nullable();
            $table->string('first_gift_label', 190)->default('Free 8oz Coffeehouse candle');
            $table->string('renewal_gift_product_variant_gid', 190)->nullable();
            $table->string('renewal_gift_label', 190)->default('Free renewal candle');
            $table->text('cancellation_prompt')->nullable();
            $table->unsignedInteger('voting_reward_candle_cash')->default(0);
            $table->json('poll_defaults')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('tenant_id', 'subscription_candle_club_settings_tenant_unique');
        });

        Schema::create('subscription_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketing_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shopify_customer_gid', 190)->nullable();
            $table->string('recharge_customer_id', 190)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('normalized_email', 190)->nullable();
            $table->string('phone', 80)->nullable();
            $table->string('normalized_phone', 80)->nullable();
            $table->string('status', 40)->default('active');
            $table->json('billing_address')->nullable();
            $table->json('shipping_address')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'marketing_profile_id'], 'subscription_customers_profile_idx');
            $table->unique(['tenant_id', 'shopify_customer_gid'], 'subscription_customers_shopify_unique');
            $table->unique(['tenant_id', 'recharge_customer_id'], 'subscription_customers_recharge_unique');
            $table->index(['tenant_id', 'normalized_email'], 'subscription_customers_email_idx');
            $table->index(['tenant_id', 'normalized_phone'], 'subscription_customers_phone_idx');
        });

        Schema::create('subscription_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('marketing_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shopify_subscription_contract_gid', 190)->nullable();
            $table->string('recharge_subscription_id', 190)->nullable();
            $table->string('shopify_customer_gid', 190)->nullable();
            $table->string('shopify_payment_method_gid', 190)->nullable();
            $table->string('status', 40)->default('active');
            $table->boolean('is_candle_club')->default(false);
            $table->date('next_billing_date')->nullable();
            $table->date('next_shipping_date')->nullable();
            $table->unsignedInteger('billing_interval_count')->default(1);
            $table->string('billing_interval', 40)->default('month');
            $table->unsignedInteger('delivery_interval_count')->default(1);
            $table->string('delivery_interval', 40)->default('month');
            $table->unsignedInteger('completed_cycles')->default(0);
            $table->unsignedInteger('pause_count_current_commitment')->default(0);
            $table->date('commitment_started_on')->nullable();
            $table->date('commitment_ends_on')->nullable();
            $table->json('shipping_address')->nullable();
            $table->json('billing_address')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'shopify_subscription_contract_gid'], 'subscription_contracts_shopify_unique');
            $table->unique(['tenant_id', 'recharge_subscription_id'], 'subscription_contracts_recharge_unique');
            $table->index(['tenant_id', 'status', 'is_candle_club'], 'subscription_contracts_status_idx');
            $table->index(['tenant_id', 'next_billing_date'], 'subscription_contracts_billing_idx');
            $table->index(['tenant_id', 'marketing_profile_id'], 'subscription_contracts_profile_idx');
        });

        Schema::create('subscription_contract_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_contract_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_subscription_line_gid', 190)->nullable();
            $table->string('shopify_product_gid', 190)->nullable();
            $table->string('shopify_product_variant_gid', 190)->nullable();
            $table->string('shopify_selling_plan_gid', 190)->nullable();
            $table->string('product_title', 255)->nullable();
            $table->string('variant_title', 255)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('currency', 8)->default('USD');
            $table->json('custom_attributes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'shopify_subscription_line_gid'], 'subscription_lines_shopify_unique');
            $table->index(['tenant_id', 'shopify_product_variant_gid'], 'subscription_lines_variant_idx');
        });

        Schema::create('subscription_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shopify_payment_method_gid', 190);
            $table->string('shopify_customer_gid', 190)->nullable();
            $table->string('status', 40)->default('active');
            $table->string('brand', 80)->nullable();
            $table->string('last_digits', 16)->nullable();
            $table->string('expiry_month', 8)->nullable();
            $table->string('expiry_year', 8)->nullable();
            $table->timestamp('last_update_email_sent_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'shopify_payment_method_gid'], 'subscription_payment_methods_unique');
            $table->index(['tenant_id', 'status'], 'subscription_payment_methods_status_idx');
        });

        Schema::create('subscription_billing_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_contract_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shopify_subscription_contract_gid', 190)->nullable();
            $table->string('shopify_billing_attempt_gid', 190)->nullable();
            $table->string('shopify_order_gid', 190)->nullable();
            $table->string('idempotency_key', 190);
            $table->string('status', 40)->default('pending');
            $table->date('billing_date')->nullable();
            $table->unsignedInteger('amount_cents')->default(0);
            $table->string('currency', 8)->default('USD');
            $table->text('error_message')->nullable();
            $table->string('next_action_url', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key'], 'subscription_billing_attempts_idempotency_unique');
            $table->unique(['tenant_id', 'shopify_billing_attempt_gid'], 'subscription_billing_attempts_shopify_unique');
            $table->index(['tenant_id', 'status', 'billing_date'], 'subscription_billing_attempts_status_idx');
        });

        Schema::create('subscription_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 80);
            $table->string('source', 80)->default('evergrove');
            $table->string('status', 40)->default('recorded');
            $table->json('before_payload')->nullable();
            $table->json('after_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'event_type'], 'subscription_events_type_idx');
            $table->index(['tenant_id', 'subscription_contract_id'], 'subscription_events_contract_idx');
        });

        Schema::create('subscription_migration_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 40)->default('recharge_api');
            $table->string('mode', 40)->default('dry_run');
            $table->string('status', 40)->default('pending');
            $table->boolean('recharge_billing_paused_confirmed')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cutover_enabled_at')->nullable();
            $table->json('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'subscription_migration_batches_status_idx');
        });

        Schema::create('subscription_migration_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_migration_batch_id');
            $table->string('source_type', 80)->default('subscription');
            $table->string('source_id', 190)->nullable();
            $table->string('status', 40)->default('pending');
            $table->string('shopify_customer_gid', 190)->nullable();
            $table->string('shopify_subscription_contract_gid', 190)->nullable();
            $table->string('recharge_customer_id', 190)->nullable();
            $table->string('recharge_subscription_id', 190)->nullable();
            $table->json('mapped_payload')->nullable();
            $table->json('errors')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['subscription_migration_batch_id', 'source_type', 'source_id'], 'subscription_migration_rows_source_unique');
            $table->index(['tenant_id', 'status'], 'subscription_migration_rows_status_idx');
            $table->foreign('subscription_migration_batch_id', 'subscription_migration_rows_batch_fk')
                ->references('id')
                ->on('subscription_migration_batches')
                ->cascadeOnDelete();
        });

        Schema::create('subscription_polls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('poll_type', 80)->default('candle_club_scent');
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->string('status', 40)->default('draft');
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->string('share_token', 120)->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'poll_type'], 'subscription_polls_status_idx');
        });

        Schema::create('subscription_poll_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_poll_id')->constrained()->cascadeOnDelete();
            $table->string('label', 190);
            $table->string('shopify_product_variant_gid', 190)->nullable();
            $table->foreignId('scent_id')->nullable()->constrained('scents')->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'subscription_poll_id'], 'subscription_poll_options_poll_idx');
        });

        Schema::create('subscription_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_poll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_poll_option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('marketing_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shopify_subscription_contract_gid', 190);
            $table->string('shopify_customer_gid', 190)->nullable();
            $table->string('normalized_email', 190)->nullable();
            $table->string('normalized_phone', 80)->nullable();
            $table->string('source', 40)->default('storefront');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'subscription_poll_id', 'shopify_subscription_contract_gid'], 'subscription_votes_contract_unique');
            $table->index(['tenant_id', 'subscription_poll_option_id'], 'subscription_votes_option_idx');
            $table->index(['tenant_id', 'normalized_email'], 'subscription_votes_email_idx');
        });

        Schema::create('subscription_voter_verification_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_poll_id')->nullable();
            $table->foreignId('subscription_contract_id')->nullable();
            $table->string('identifier_type', 20);
            $table->string('identifier_hash', 190);
            $table->string('code_hash', 190);
            $table->string('delivery_channel', 20);
            $table->string('status', 40)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'identifier_hash', 'status'], 'subscription_tokens_identifier_idx');
            $table->index(['tenant_id', 'subscription_poll_id'], 'subscription_tokens_poll_idx');
            $table->foreign('subscription_poll_id', 'subscription_tokens_poll_fk')
                ->references('id')
                ->on('subscription_polls')
                ->cascadeOnDelete();
            $table->foreign('subscription_contract_id', 'subscription_tokens_contract_fk')
                ->references('id')
                ->on('subscription_contracts')
                ->cascadeOnDelete();
        });

        Schema::create('subscription_announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_poll_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 190);
            $table->text('body')->nullable();
            $table->string('status', 40)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->json('channels')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'subscription_announcements_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_announcements');
        Schema::dropIfExists('subscription_voter_verification_tokens');
        Schema::dropIfExists('subscription_votes');
        Schema::dropIfExists('subscription_poll_options');
        Schema::dropIfExists('subscription_polls');
        Schema::dropIfExists('subscription_migration_rows');
        Schema::dropIfExists('subscription_migration_batches');
        Schema::dropIfExists('subscription_lifecycle_events');
        Schema::dropIfExists('subscription_billing_attempts');
        Schema::dropIfExists('subscription_payment_methods');
        Schema::dropIfExists('subscription_contract_lines');
        Schema::dropIfExists('subscription_contracts');
        Schema::dropIfExists('subscription_customers');
        Schema::dropIfExists('subscription_candle_club_settings');
        Schema::dropIfExists('subscription_module_settings');
    }
};
