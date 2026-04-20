<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_paid_media_daily_stats')) {
            return;
        }

        Schema::create('marketing_paid_media_daily_stats', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('store_key', 80)->nullable()->index();
            $table->string('platform', 40)->index();
            $table->string('account_id', 120)->nullable()->index();
            $table->date('metric_date')->index();

            $table->string('campaign_id', 120)->nullable()->index();
            $table->string('campaign_name', 255)->nullable();
            $table->string('ad_set_id', 120)->nullable()->index();
            $table->string('ad_set_name', 255)->nullable();
            $table->string('ad_id', 120)->nullable()->index();
            $table->string('ad_name', 255)->nullable();

            $table->decimal('spend', 12, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedInteger('purchases')->default(0);
            $table->decimal('purchase_value', 12, 2)->default(0);

            $table->string('utm_source', 120)->nullable()->index();
            $table->string('utm_medium', 120)->nullable()->index();
            $table->string('utm_campaign', 160)->nullable()->index();
            $table->string('utm_content', 160)->nullable();
            $table->string('utm_term', 160)->nullable();

            $table->string('row_fingerprint', 80)->unique();
            $table->json('raw_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->timestamps();

            $table->index([
                'tenant_id',
                'store_key',
                'platform',
                'metric_date',
            ], 'marketing_paid_media_daily_scope_idx');
        });

        try {
            Schema::table('marketing_paid_media_daily_stats', function (Blueprint $table): void {
                $table->foreign('tenant_id', 'marketing_paid_media_daily_stats_tenant_fk')
                    ->references('id')
                    ->on('tenants')
                    ->nullOnDelete();
            });
        } catch (\Throwable) {
            // Safe no-op when FK already exists.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('marketing_paid_media_daily_stats')) {
            return;
        }

        try {
            Schema::table('marketing_paid_media_daily_stats', function (Blueprint $table): void {
                $table->dropForeign('marketing_paid_media_daily_stats_tenant_fk');
            });
        } catch (\Throwable) {
            // Safe no-op when FK is absent.
        }

        Schema::dropIfExists('marketing_paid_media_daily_stats');
    }
};
