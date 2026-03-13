<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_review_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_profile_id')
                ->nullable()
                ->constrained('marketing_profiles')
                ->nullOnDelete();
            $table->string('provider')->index();
            $table->string('integration')->index();
            $table->string('store_key')->nullable()->index();
            $table->string('external_customer_id')->index();
            $table->string('external_customer_email')->nullable()->index();
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('published_review_count')->default(0);
            $table->decimal('average_rating', 5, 2)->nullable();
            $table->timestamp('last_reviewed_at')->nullable()->index();
            $table->timestamp('source_synced_at')->nullable()->index();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(
                ['provider', 'integration', 'store_key', 'external_customer_id'],
                'mrs_provider_integration_store_customer_unique'
            );
        });

        Schema::create('marketing_review_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_profile_id')
                ->nullable()
                ->constrained('marketing_profiles')
                ->nullOnDelete();
            $table->foreignId('marketing_review_summary_id')
                ->nullable()
                ->constrained('marketing_review_summaries')
                ->nullOnDelete();
            $table->string('provider')->index();
            $table->string('integration')->index();
            $table->string('store_key')->nullable()->index();
            $table->string('external_customer_id')->index();
            $table->string('external_review_id')->index();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->boolean('is_published')->nullable()->index();
            $table->boolean('is_pinned')->nullable();
            $table->boolean('is_verified_buyer')->nullable();
            $table->integer('votes')->nullable();
            $table->boolean('has_media')->default(false)->index();
            $table->unsignedInteger('media_count')->default(0);
            $table->string('product_id')->nullable()->index();
            $table->string('product_title')->nullable();
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->timestamp('source_synced_at')->nullable()->index();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(
                ['provider', 'integration', 'store_key', 'external_review_id'],
                'mrh_provider_integration_store_review_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_review_histories');
        Schema::dropIfExists('marketing_review_summaries');
    }
};
