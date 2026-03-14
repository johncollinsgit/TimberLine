<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('google_business_profile_connections')) {
            Schema::create('google_business_profile_connections', function (Blueprint $table): void {
                $table->id();
                $table->string('provider_key', 120)->unique();
                $table->string('connection_status', 40)->default('disconnected')->index('gbp_connections_status_idx');
                $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('google_subject')->nullable()->index('gbp_connections_subject_idx');
                $table->string('google_account_label')->nullable();
                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->string('token_type', 40)->nullable();
                $table->timestamp('expires_at')->nullable()->index('gbp_connections_expires_idx');
                $table->json('granted_scopes')->nullable();
                $table->string('linked_account_name')->nullable();
                $table->string('linked_account_id')->nullable()->index('gbp_connections_account_idx');
                $table->string('linked_account_display_name')->nullable();
                $table->string('linked_location_name')->nullable();
                $table->string('linked_location_id')->nullable()->index('gbp_connections_location_idx');
                $table->string('linked_location_title')->nullable();
                $table->string('linked_location_place_id')->nullable();
                $table->string('linked_location_maps_uri')->nullable();
                $table->string('project_approval_status', 40)->default('unknown')->index('gbp_connections_approval_idx');
                $table->timestamp('connected_at')->nullable()->index('gbp_connections_connected_idx');
                $table->timestamp('last_synced_at')->nullable()->index('gbp_connections_synced_idx');
                $table->string('last_error_code', 120)->nullable()->index('gbp_connections_error_idx');
                $table->text('last_error_message')->nullable();
                $table->timestamp('last_error_at')->nullable()->index('gbp_connections_error_at_idx');
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('google_business_profile_locations')) {
            Schema::create('google_business_profile_locations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('google_business_profile_connection_id');
                $table->string('account_name');
                $table->string('account_id')->index('gbp_locations_account_idx');
                $table->string('account_display_name')->nullable();
                $table->string('location_name');
                $table->string('location_id')->index('gbp_locations_location_idx');
                $table->string('title')->nullable();
                $table->string('store_code')->nullable();
                $table->string('website_uri')->nullable();
                $table->string('place_id')->nullable();
                $table->string('maps_uri')->nullable();
                $table->json('storefront_address')->nullable();
                $table->boolean('is_selected')->default(false)->index('gbp_locations_selected_idx');
                $table->timestamp('selected_at')->nullable()->index('gbp_locations_selected_at_idx');
                $table->timestamp('last_seen_at')->nullable()->index('gbp_locations_seen_idx');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('google_business_profile_connection_id', 'gbp_locations_connection_fk')
                    ->references('id')
                    ->on('google_business_profile_connections')
                    ->cascadeOnDelete();
                $table->unique(['google_business_profile_connection_id', 'location_name'], 'gbp_locations_connection_location_unique');
            });
        }

        if (! Schema::hasTable('google_business_profile_sync_runs')) {
            Schema::create('google_business_profile_sync_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('google_business_profile_connection_id');
                $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('trigger_type', 40)->default('manual')->index('gbp_sync_runs_trigger_idx');
                $table->string('status', 40)->default('running')->index('gbp_sync_runs_status_idx');
                $table->unsignedInteger('fetched_reviews_count')->default(0);
                $table->unsignedInteger('new_reviews_count')->default(0);
                $table->unsignedInteger('updated_reviews_count')->default(0);
                $table->unsignedInteger('matched_reviews_count')->default(0);
                $table->unsignedInteger('awarded_reviews_count')->default(0);
                $table->unsignedInteger('duplicate_reviews_count')->default(0);
                $table->unsignedInteger('unmatched_reviews_count')->default(0);
                $table->string('error_code', 120)->nullable()->index('gbp_sync_runs_error_idx');
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable()->index('gbp_sync_runs_started_idx');
                $table->timestamp('finished_at')->nullable()->index('gbp_sync_runs_finished_idx');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('google_business_profile_connection_id', 'gbp_sync_runs_connection_fk')
                    ->references('id')
                    ->on('google_business_profile_connections')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('google_business_profile_reviews')) {
            Schema::create('google_business_profile_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('google_business_profile_connection_id');
                $table->foreignId('marketing_profile_id')->nullable()->constrained('marketing_profiles')->nullOnDelete();
                $table->foreignId('candle_cash_task_event_id')->nullable();
                $table->foreignId('candle_cash_task_completion_id')->nullable();
                $table->foreignId('marketing_storefront_event_id')->nullable();
                $table->string('external_review_id', 190);
                $table->string('review_name')->nullable();
                $table->string('account_id')->nullable()->index('gbp_reviews_account_idx');
                $table->string('account_name')->nullable();
                $table->string('location_id')->nullable()->index('gbp_reviews_location_idx');
                $table->string('location_name')->nullable();
                $table->unsignedTinyInteger('star_rating')->nullable();
                $table->string('reviewer_name')->nullable()->index('gbp_reviews_reviewer_idx');
                $table->string('reviewer_profile_photo_url')->nullable();
                $table->boolean('reviewer_is_anonymous')->default(false)->index('gbp_reviews_anon_idx');
                $table->text('comment')->nullable();
                $table->text('review_reply_comment')->nullable();
                $table->timestamp('created_time')->nullable()->index('gbp_reviews_created_idx');
                $table->timestamp('updated_time')->nullable()->index('gbp_reviews_updated_idx');
                $table->string('sync_status', 40)->default('synced')->index('gbp_reviews_sync_status_idx');
                $table->timestamp('matched_at')->nullable()->index('gbp_reviews_matched_idx');
                $table->timestamp('awarded_at')->nullable()->index('gbp_reviews_awarded_idx');
                $table->json('metadata')->nullable();
                $table->json('raw_payload')->nullable();
                $table->timestamps();

                $table->foreign('google_business_profile_connection_id', 'gbp_reviews_connection_fk')
                    ->references('id')
                    ->on('google_business_profile_connections')
                    ->cascadeOnDelete();
                $table->foreign('candle_cash_task_event_id', 'gbp_reviews_task_event_fk')
                    ->references('id')
                    ->on('candle_cash_task_events')
                    ->nullOnDelete();
                $table->foreign('candle_cash_task_completion_id', 'gbp_reviews_completion_fk')
                    ->references('id')
                    ->on('candle_cash_task_completions')
                    ->nullOnDelete();
                $table->foreign('marketing_storefront_event_id', 'gbp_reviews_storefront_fk')
                    ->references('id')
                    ->on('marketing_storefront_events')
                    ->nullOnDelete();
                $table->unique(['google_business_profile_connection_id', 'external_review_id'], 'gbp_reviews_connection_external_unique');
            });
        }

        $integrationConfig = (array) optional(DB::table('marketing_settings')->where('key', 'candle_cash_integration_config')->first())->value;
        if (is_string($integrationConfig)) {
            $decoded = json_decode($integrationConfig, true);
            $integrationConfig = is_array($decoded) ? $decoded : [];
        }

        $integrationConfig = array_merge([
            'google_review_enabled' => false,
            'google_review_url' => null,
            'google_business_location_id' => null,
            'google_review_matching_strategy' => 'recent_click_name_match',
            'google_business_qna_deprecated' => true,
        ], $integrationConfig);
        $integrationConfig['google_business_qna_deprecated'] = true;
        if (blank($integrationConfig['google_review_matching_strategy'] ?? null)) {
            $integrationConfig['google_review_matching_strategy'] = 'recent_click_name_match';
        }

        DB::table('marketing_settings')->updateOrInsert(
            ['key' => 'candle_cash_integration_config'],
            [
                'value' => json_encode($integrationConfig),
                'description' => 'Integration and verification settings for automatic-first Candle Cash tasks.',
                'updated_at' => now(),
                'created_at' => optional(DB::table('marketing_settings')->where('key', 'candle_cash_integration_config')->first())->created_at ?? now(),
            ]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('google_business_profile_reviews');
        Schema::dropIfExists('google_business_profile_sync_runs');
        Schema::dropIfExists('google_business_profile_locations');
        Schema::dropIfExists('google_business_profile_connections');
    }
};
