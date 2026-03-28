<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('landlord_catalog_entries')) {
            Schema::create('landlord_catalog_entries', function (Blueprint $table): void {
                $table->id();
                $table->string('entry_type', 50);
                $table->string('entry_key', 120);
                $table->string('name', 190);
                $table->string('status', 40)->default('active');
                $table->boolean('is_active')->default(true);
                $table->boolean('is_public')->default(true);
                $table->unsignedInteger('position')->default(100);
                $table->string('currency', 3)->default('USD');
                $table->integer('recurring_price_cents')->nullable();
                $table->string('recurring_interval', 40)->default('month');
                $table->integer('setup_price_cents')->nullable();
                $table->json('payload')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['entry_type', 'entry_key'], 'landlord_catalog_entries_type_key_unique');
                $table->index(['entry_type', 'status'], 'landlord_catalog_entries_type_status_index');
            });
        }

        if (! Schema::hasTable('tenant_commercial_overrides')) {
            Schema::create('tenant_commercial_overrides', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->string('template_key', 120)->nullable();
                $table->unsignedInteger('store_channel_allowance')->nullable();
                $table->json('plan_pricing_overrides')->nullable();
                $table->json('addon_pricing_overrides')->nullable();
                $table->json('included_usage_overrides')->nullable();
                $table->json('display_labels')->nullable();
                $table->json('billing_mapping')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('tenant_id', 'tenant_commercial_overrides_tenant_fk')
                    ->references('id')
                    ->on('tenants')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('tenant_usage_counters')) {
            Schema::create('tenant_usage_counters', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('metric_key', 80);
                $table->unsignedBigInteger('metric_value')->default(0);
                $table->unsignedBigInteger('included_limit')->nullable();
                $table->string('source', 40)->default('computed');
                $table->timestamp('last_recorded_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'metric_key'], 'tenant_usage_counters_tenant_metric_unique');
                $table->index(['metric_key', 'metric_value'], 'tenant_usage_counters_metric_value_index');
                $table->foreign('tenant_id', 'tenant_usage_counters_tenant_fk')
                    ->references('id')
                    ->on('tenants')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_usage_counters');
        Schema::dropIfExists('tenant_commercial_overrides');
        Schema::dropIfExists('landlord_catalog_entries');
    }
};
