<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscription_candle_club_settings')) {
            DB::table('subscription_candle_club_settings')
                ->where(function ($query): void {
                    $query->whereNull('allowed_pauses_per_commitment')
                        ->orWhere('allowed_pauses_per_commitment', '<', 2);
                })
                ->update([
                    'allowed_pauses_per_commitment' => 2,
                    'updated_at' => now(),
                ]);
        }

        Schema::create('subscription_candle_club_monthly_scents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candle_club_scent_id')->nullable()->constrained('candle_club_scents')->nullOnDelete();
            $table->foreignId('scent_id')->nullable()->constrained('scents')->nullOnDelete();
            $table->unsignedSmallInteger('month');
            $table->unsignedSmallInteger('year');
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->string('status', 40)->default('chosen');
            $table->string('shopify_product_gid', 190)->nullable();
            $table->string('shopify_product_handle', 190)->nullable();
            $table->string('shopify_product_status', 40)->default('draft');
            $table->string('shopify_collection_gid', 190)->nullable();
            $table->string('photo_url', 500)->nullable();
            $table->string('photo_source', 80)->nullable();
            $table->string('photo_author', 190)->nullable();
            $table->string('photo_query', 255)->nullable();
            $table->json('photo_metadata')->nullable();
            $table->timestamp('selected_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'year', 'month'], 'subscription_cc_monthly_scents_period_unique');
            $table->index(['tenant_id', 'status'], 'subscription_cc_monthly_scents_status_idx');
            $table->index(['tenant_id', 'shopify_product_gid'], 'subscription_cc_monthly_scents_product_idx');
        });

        Schema::create('subscription_candle_club_scent_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_candle_club_monthly_scent_id')
                ->nullable()
                ->constrained('subscription_candle_club_monthly_scents')
                ->nullOnDelete();
            $table->foreignId('subscription_contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('marketing_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('title', 190)->nullable();
            $table->text('body')->nullable();
            $table->string('visibility', 40)->default('candle_club');
            $table->string('status', 40)->default('pending');
            $table->foreignId('exported_marketing_review_history_id')
                ->nullable()
                ->constrained('marketing_review_histories')
                ->nullOnDelete();
            $table->timestamp('exported_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'subscription_cc_scent_feedback_status_idx');
            $table->index(['tenant_id', 'subscription_candle_club_monthly_scent_id'], 'subscription_cc_scent_feedback_month_idx');
            $table->index(['tenant_id', 'marketing_profile_id'], 'subscription_cc_scent_feedback_profile_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_candle_club_scent_feedback');
        Schema::dropIfExists('subscription_candle_club_monthly_scents');
    }
};
