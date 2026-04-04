<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_message_engagement_events')) {
            Schema::create('marketing_message_engagement_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('store_key', 80)->nullable()->index();
                $table->foreignId('marketing_email_delivery_id')->nullable()->constrained('marketing_email_deliveries')->nullOnDelete();
                $table->foreignId('marketing_message_delivery_id')->nullable()->constrained('marketing_message_deliveries')->nullOnDelete();
                $table->foreignId('marketing_profile_id')->nullable()->constrained('marketing_profiles')->nullOnDelete();
                $table->string('channel', 20)->index();
                $table->string('event_type', 20)->index();
                $table->string('event_hash', 64)->unique();
                $table->string('provider', 60)->nullable()->index();
                $table->string('provider_event_id', 190)->nullable()->index();
                $table->string('provider_message_id', 190)->nullable()->index();
                $table->string('link_label', 190)->nullable();
                $table->text('url')->nullable();
                $table->text('normalized_url')->nullable();
                $table->string('url_domain', 190)->nullable()->index();
                $table->string('ip_address', 80)->nullable();
                $table->text('user_agent')->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('occurred_at')->nullable()->index();
                $table->timestamps();

                $table->index(['tenant_id', 'store_key', 'event_type', 'occurred_at'], 'mm_engagement_tenant_store_type_time_idx');
                $table->index(['tenant_id', 'marketing_profile_id', 'event_type', 'occurred_at'], 'mm_engagement_tenant_profile_type_time_idx');
                $table->index(['tenant_id', 'marketing_email_delivery_id', 'event_type'], 'mm_engagement_tenant_email_type_idx');
            });

            try {
                Schema::table('marketing_message_engagement_events', function (Blueprint $table): void {
                    $table->foreign('tenant_id', 'mm_engagement_events_tenant_fk')
                        ->references('id')
                        ->on('tenants')
                        ->nullOnDelete();
                });
            } catch (\Throwable) {
                // Safe no-op when FK already exists.
            }
        }

        if (! Schema::hasTable('marketing_message_order_attributions')) {
            Schema::create('marketing_message_order_attributions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('store_key', 80)->nullable()->index();
                $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->foreignId('marketing_profile_id')->nullable()->constrained('marketing_profiles')->nullOnDelete();
                $table->foreignId('marketing_email_delivery_id')->nullable()->constrained('marketing_email_deliveries')->nullOnDelete();
                $table->foreignId('marketing_message_engagement_event_id')->nullable()->constrained('marketing_message_engagement_events')->nullOnDelete();
                $table->string('channel', 20)->nullable()->index();
                $table->string('attribution_model', 40)->default('last_click')->index();
                $table->unsignedSmallInteger('attribution_window_days')->default(7);
                $table->text('attributed_url')->nullable();
                $table->text('normalized_url')->nullable();
                $table->timestamp('click_occurred_at')->nullable()->index();
                $table->timestamp('order_occurred_at')->nullable()->index();
                $table->integer('revenue_cents')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'store_key', 'order_id', 'attribution_model'], 'mm_order_attribution_order_unique');
                $table->index(['tenant_id', 'store_key', 'marketing_email_delivery_id'], 'mm_order_attribution_tenant_store_delivery_idx');
                $table->index(['tenant_id', 'marketing_message_engagement_event_id'], 'mm_order_attribution_tenant_event_idx');
            });

            try {
                Schema::table('marketing_message_order_attributions', function (Blueprint $table): void {
                    $table->foreign('tenant_id', 'mm_order_attribution_tenant_fk')
                        ->references('id')
                        ->on('tenants')
                        ->nullOnDelete();
                });
            } catch (\Throwable) {
                // Safe no-op when FK already exists.
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('marketing_message_order_attributions')) {
            try {
                Schema::table('marketing_message_order_attributions', function (Blueprint $table): void {
                    $table->dropForeign('mm_order_attribution_tenant_fk');
                });
            } catch (\Throwable) {
                // Safe no-op when FK is absent.
            }
        }

        if (Schema::hasTable('marketing_message_engagement_events')) {
            try {
                Schema::table('marketing_message_engagement_events', function (Blueprint $table): void {
                    $table->dropForeign('mm_engagement_events_tenant_fk');
                });
            } catch (\Throwable) {
                // Safe no-op when FK is absent.
            }
        }

        Schema::dropIfExists('marketing_message_order_attributions');
        Schema::dropIfExists('marketing_message_engagement_events');
    }
};
