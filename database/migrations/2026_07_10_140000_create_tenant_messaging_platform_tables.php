<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_messaging_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('provider', 40);
            $table->string('mode', 30)->default('platform_managed');
            $table->string('status', 40)->default('not_configured');
            $table->string('provider_account_id')->nullable();
            $table->string('provider_resource_id')->nullable();
            $table->string('sender_identifier')->nullable();
            $table->string('authenticated_domain')->nullable();
            $table->text('credentials')->nullable();
            $table->text('provider_config')->nullable();
            $table->json('dns_records')->nullable();
            $table->json('registration')->nullable();
            $table->json('diagnostics')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'channel'], 'tenant_messaging_accounts_tenant_channel_unique');
            $table->index(['provider', 'provider_account_id'], 'tenant_messaging_accounts_provider_account_idx');
            $table->index(['tenant_id', 'status'], 'tenant_messaging_accounts_tenant_status_idx');
        });

        Schema::create('tenant_messaging_sender_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tenant_messaging_account_id')->nullable()
                ->constrained('tenant_messaging_accounts')->nullOnDelete();
            $table->string('channel', 20)->default('email');
            $table->string('store_key', 80)->nullable();
            $table->string('label', 120);
            $table->string('display_name', 160);
            $table->string('from_email');
            $table->string('reply_to_email')->nullable();
            $table->string('authenticated_domain');
            $table->string('reply_mode', 30)->default('everbranch_inbox');
            $table->string('verification_status', 30)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'store_key', 'from_email'], 'tenant_sender_profiles_tenant_store_from_unique');
            $table->index(['tenant_id', 'channel', 'is_default'], 'tenant_sender_profiles_default_idx');
        });

        Schema::create('tenant_messaging_credit_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('currency', 3)->default('USD');
            $table->unsignedBigInteger('balance_micros')->default(0);
            $table->unsignedBigInteger('reserved_micros')->default(0);
            $table->unsignedBigInteger('low_balance_threshold_micros')->default(5000000);
            $table->timestamp('last_funded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_messaging_usage_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('included_units')->default(0);
            $table->unsignedBigInteger('used_units')->default(0);
            $table->unsignedBigInteger('reserved_units')->default(0);
            $table->unsignedBigInteger('provider_cost_micros')->default(0);
            $table->unsignedBigInteger('buyer_charge_micros')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'channel', 'period_start'], 'tenant_messaging_usage_period_unique');
            $table->index(['tenant_id', 'period_end'], 'tenant_messaging_usage_period_end_idx');
        });

        Schema::create('tenant_messaging_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tenant_messaging_credit_account_id')->nullable()
                ->constrained('tenant_messaging_credit_accounts')->nullOnDelete();
            $table->foreignId('tenant_messaging_usage_period_id')->nullable()
                ->constrained('tenant_messaging_usage_periods')->nullOnDelete();
            $table->string('entry_type', 40);
            $table->string('status', 30)->default('settled');
            $table->string('channel', 20)->nullable();
            $table->string('unit_type', 30)->nullable();
            $table->unsignedBigInteger('units')->default(0);
            $table->bigInteger('amount_micros')->default(0);
            $table->unsignedBigInteger('provider_cost_micros')->default(0);
            $table->string('pricing_version', 40);
            $table->string('idempotency_key', 160);
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key'], 'tenant_messaging_ledger_idempotency_unique');
            $table->index(['tenant_id', 'occurred_at'], 'tenant_messaging_ledger_tenant_date_idx');
            $table->index(['source_type', 'source_id'], 'tenant_messaging_ledger_source_idx');
        });

        Schema::table('marketing_message_order_attributions', function (Blueprint $table): void {
            $table->string('source_module_key', 80)->nullable()->index()->after('channel');
            $table->unsignedBigInteger('source_campaign_id')->nullable()->after('source_module_key');
            $table->string('source_campaign_label', 180)->nullable()->after('source_campaign_id');
            $table->string('attribution_type', 20)->default('direct')->index()->after('attribution_model');
            $table->unsignedTinyInteger('confidence_percent')->default(100)->after('attribution_type');
            $table->string('currency_code', 8)->default('USD')->after('revenue_cents');
            $table->integer('gross_revenue_cents')->default(0)->after('currency_code');
            $table->integer('refund_cents')->default(0)->after('gross_revenue_cents');
            $table->integer('net_revenue_cents')->default(0)->after('refund_cents');
            $table->unsignedBigInteger('provider_cost_micros')->default(0)->after('net_revenue_cents');
            $table->unsignedBigInteger('buyer_spend_micros')->default(0)->after('provider_cost_micros');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_message_order_attributions', function (Blueprint $table): void {
            $table->dropIndex('marketing_message_order_attributions_source_module_key_index');
            $table->dropIndex('marketing_message_order_attributions_attribution_type_index');
            $table->dropColumn([
                'source_module_key',
                'source_campaign_id',
                'source_campaign_label',
                'attribution_type',
                'confidence_percent',
                'currency_code',
                'gross_revenue_cents',
                'refund_cents',
                'net_revenue_cents',
                'provider_cost_micros',
                'buyer_spend_micros',
            ]);
        });

        Schema::dropIfExists('tenant_messaging_ledger_entries');
        Schema::dropIfExists('tenant_messaging_usage_periods');
        Schema::dropIfExists('tenant_messaging_credit_accounts');
        Schema::dropIfExists('tenant_messaging_sender_profiles');
        Schema::dropIfExists('tenant_messaging_accounts');
    }
};
