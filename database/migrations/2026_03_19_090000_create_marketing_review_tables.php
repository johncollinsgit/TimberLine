<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_review_summaries')) {
            Schema::create('marketing_review_summaries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('marketing_profile_id')
                    ->nullable()
                    ->constrained('marketing_profiles')
                    ->nullOnDelete();
                $table->string('provider', 80)->index();
                $table->string('integration', 80)->index();
                $table->string('store_key', 80)->nullable()->index();
                $table->string('external_customer_id', 120)->index();
                $table->string('external_customer_email', 190)->nullable()->index();
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
        } else {
            DB::statement("
                ALTER TABLE marketing_review_summaries
                MODIFY provider VARCHAR(80) NOT NULL,
                MODIFY integration VARCHAR(80) NOT NULL,
                MODIFY store_key VARCHAR(80) NULL,
                MODIFY external_customer_id VARCHAR(120) NOT NULL,
                MODIFY external_customer_email VARCHAR(190) NULL
            ");

            if (! $this->indexExists('marketing_review_summaries', 'mrs_provider_integration_store_customer_unique')) {
                Schema::table('marketing_review_summaries', function (Blueprint $table): void {
                    $table->unique(
                        ['provider', 'integration', 'store_key', 'external_customer_id'],
                        'mrs_provider_integration_store_customer_unique'
                    );
                });
            }
        }

        if (! Schema::hasTable('marketing_review_histories')) {
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
                $table->string('provider', 80)->index();
                $table->string('integration', 80)->index();
                $table->string('store_key', 80)->nullable()->index();
                $table->string('external_customer_id', 120)->index();
                $table->string('external_review_id', 120)->index();
                $table->unsignedTinyInteger('rating')->nullable();
                $table->string('title')->nullable();
                $table->text('body')->nullable();
                $table->boolean('is_published')->nullable()->index();
                $table->boolean('is_pinned')->nullable();
                $table->boolean('is_verified_buyer')->nullable();
                $table->integer('votes')->nullable();
                $table->boolean('has_media')->default(false)->index();
                $table->unsignedInteger('media_count')->default(0);
                $table->string('product_id', 120)->nullable()->index();
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
        } else {
            DB::statement("
                ALTER TABLE marketing_review_histories
                MODIFY provider VARCHAR(80) NOT NULL,
                MODIFY integration VARCHAR(80) NOT NULL,
                MODIFY store_key VARCHAR(80) NULL,
                MODIFY external_customer_id VARCHAR(120) NOT NULL,
                MODIFY external_review_id VARCHAR(120) NOT NULL,
                MODIFY product_id VARCHAR(120) NULL
            ");

            if (! $this->indexExists('marketing_review_histories', 'mrh_provider_integration_store_review_unique')) {
                Schema::table('marketing_review_histories', function (Blueprint $table): void {
                    $table->unique(
                        ['provider', 'integration', 'store_key', 'external_review_id'],
                        'mrh_provider_integration_store_review_unique'
                    );
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_review_histories');
        Schema::dropIfExists('marketing_review_summaries');
    }

    protected function indexExists(string $table, string $index): bool
    {
        return collect(DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$index]))->isNotEmpty();
    }
};
