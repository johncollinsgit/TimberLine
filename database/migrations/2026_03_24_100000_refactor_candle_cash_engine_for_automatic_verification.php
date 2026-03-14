<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('candle_cash_tasks')) {
            Schema::table('candle_cash_tasks', function (Blueprint $table): void {
                if (! Schema::hasColumn('candle_cash_tasks', 'verification_mode')) {
                    $table->string('verification_mode')->default('manual_review_fallback')->index()->after('task_type');
                }
                if (! Schema::hasColumn('candle_cash_tasks', 'auto_award')) {
                    $table->boolean('auto_award')->default(false)->index()->after('verification_mode');
                }
                if (! Schema::hasColumn('candle_cash_tasks', 'campaign_key')) {
                    $table->string('campaign_key')->nullable()->index()->after('auto_award');
                }
                if (! Schema::hasColumn('candle_cash_tasks', 'external_object_id')) {
                    $table->string('external_object_id')->nullable()->index()->after('campaign_key');
                }
                if (! Schema::hasColumn('candle_cash_tasks', 'verification_window_hours')) {
                    $table->unsignedInteger('verification_window_hours')->nullable()->after('external_object_id');
                }
                if (! Schema::hasColumn('candle_cash_tasks', 'matching_rules')) {
                    $table->json('matching_rules')->nullable()->after('verification_window_hours');
                }
                if (! Schema::hasColumn('candle_cash_tasks', 'metadata')) {
                    $table->json('metadata')->nullable()->after('matching_rules');
                }
            });
        }

        if (! Schema::hasTable('candle_cash_task_events')) {
            Schema::create('candle_cash_task_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('candle_cash_task_id')->constrained('candle_cash_tasks')->cascadeOnDelete();
                $table->foreignId('marketing_profile_id')->nullable()->constrained('marketing_profiles')->nullOnDelete();
                $table->foreignId('candle_cash_task_completion_id')->nullable()->constrained('candle_cash_task_completions')->nullOnDelete();
                $table->string('verification_mode')->index();
                $table->string('source_type')->nullable()->index();
                $table->string('source_id')->nullable()->index();
                $table->string('source_event_key', 190);
                $table->string('status', 40)->default('received')->index();
                $table->boolean('reward_awarded')->default(false)->index();
                $table->string('blocked_reason')->nullable()->index();
                $table->unsignedInteger('duplicate_hits')->default(0);
                $table->timestamp('duplicate_last_seen_at')->nullable()->index();
                $table->timestamp('occurred_at')->nullable()->index();
                $table->timestamp('processed_at')->nullable()->index();
                $table->timestamp('awarded_at')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['candle_cash_task_id', 'source_event_key'], 'ccte_task_source_event_unique');
                $table->index(['marketing_profile_id', 'status'], 'ccte_profile_status_idx');
            });
        }

        $now = now();

        DB::table('marketing_settings')->upsert([
            [
                'key' => 'candle_cash_program_config',
                'value' => json_encode([
                    'label' => 'Candle Cash',
                    'points_per_dollar' => 10,
                    'email_signup_reward_amount' => 5,
                    'sms_signup_reward_amount' => 2,
                    'google_review_reward_amount' => 3,
                    'birthday_signup_reward_amount' => 2,
                    'candle_club_join_reward_amount' => 2,
                    'candle_club_vote_reward_amount' => 1,
                    'second_order_reward_amount' => 5,
                    'homepage_signup_copy' => 'Join and get $5 in Candle Cash instantly.',
                    'homepage_central_title' => 'Candle Cash Central',
                    'homepage_central_copy' => 'Earn Candle Cash through verified actions like signups, reviews, referrals, and member-only perks.',
                    'birthday_reward_frequency' => 'once_per_year',
                ]),
                'description' => 'Automatic-first Candle Cash program settings.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'candle_cash_frontend_config',
                'value' => json_encode([
                    'central_title' => 'Candle Cash Central',
                    'central_subtitle' => 'Earn Candle Cash through verified actions like signups, reviews, referrals, and Candle Club perks.',
                    'faq_approval_copy' => 'Most rewards land automatically as soon as the matching event is verified.',
                    'faq_stack_copy' => 'Only one discount code can be used at a time unless a reward card says otherwise.',
                    'faq_pending_copy' => 'If a reward is still pending, we are waiting on the matching system event or integration to confirm it.',
                    'faq_verification_copy' => 'Every standard Candle Cash reward is tied to a verified event, not a manual claim.',
                ]),
                'description' => 'Customer-facing Candle Cash copy for the automatic-first program.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'candle_cash_integration_config',
                'value' => json_encode([
                    'google_review_enabled' => false,
                    'google_review_url' => null,
                    'google_business_location_id' => null,
                    'google_review_matching_strategy' => 'email_phone_or_profile',
                    'product_review_enabled' => false,
                    'product_review_platform' => null,
                    'product_review_matching_strategy' => 'profile_or_external_customer',
                    'sms_signup_enabled' => true,
                    'email_signup_enabled' => true,
                    'vote_locked_join_url' => '/products/modern-forestry-candle-club-16oz-subscription-with-gifts',
                ]),
                'description' => 'Integration and verification settings for automatic-first Candle Cash tasks.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['key'], ['value', 'description', 'updated_at']);

        $tasks = [
            [
                'handle' => 'email-signup',
                'title' => 'Email signup',
                'description' => 'Join our email list and get $5 in Candle Cash.',
                'reward_amount' => 5.00,
                'enabled' => true,
                'display_order' => 10,
                'task_type' => 'subscription_event',
                'verification_mode' => 'subscription_event',
                'auto_award' => true,
                'action_url' => '/pages/rewards?task=email-signup',
                'button_text' => 'Get $5',
                'completion_rule' => json_encode(['trigger' => 'email_consent_confirmed', 'channel' => 'email']),
                'max_completions_per_customer' => 1,
                'requires_manual_approval' => false,
                'requires_customer_submission' => false,
                'icon' => 'mail',
                'eligibility_type' => 'everyone',
                'required_customer_tags' => null,
                'required_membership_status' => null,
                'visible_to_noneligible_customers' => false,
                'locked_message' => null,
                'locked_cta_text' => null,
                'locked_cta_url' => null,
                'campaign_key' => null,
                'external_object_id' => null,
                'verification_window_hours' => 24,
                'matching_rules' => json_encode(['dedupe_scope' => 'email_or_profile', 'allow_manual_submit' => false]),
                'metadata' => json_encode(['customer_visible' => true]),
                'admin_notes' => 'Auto-awarded once when email marketing consent is confirmed through a first-party flow.',
                'archived_at' => null,
            ],
            [
                'handle' => 'sms-signup',
                'title' => 'SMS signup',
                'description' => 'Opt into texts and earn a small Candle Cash bonus automatically.',
                'reward_amount' => 2.00,
                'enabled' => true,
                'display_order' => 20,
                'task_type' => 'subscription_event',
                'verification_mode' => 'subscription_event',
                'auto_award' => true,
                'action_url' => '/pages/rewards?task=sms-signup',
                'button_text' => 'Join text deals',
                'completion_rule' => json_encode(['trigger' => 'sms_consent_confirmed', 'channel' => 'sms']),
                'max_completions_per_customer' => 1,
                'requires_manual_approval' => false,
                'requires_customer_submission' => false,
                'icon' => 'message',
                'eligibility_type' => 'everyone',
                'required_customer_tags' => null,
                'required_membership_status' => null,
                'visible_to_noneligible_customers' => false,
                'locked_message' => null,
                'locked_cta_text' => null,
                'locked_cta_url' => null,
                'campaign_key' => null,
                'external_object_id' => null,
                'verification_window_hours' => 24,
                'matching_rules' => json_encode(['dedupe_scope' => 'phone_or_profile', 'allow_manual_submit' => false]),
                'metadata' => json_encode(['customer_visible' => true]),
                'admin_notes' => 'Auto-awarded once when SMS consent is confirmed through an integrated capture flow.',
                'archived_at' => null,
            ],
            [
                'handle' => 'google-review',
                'title' => 'Leave a Google review',
                'description' => 'Leave a verified Google review and earn $3 in Candle Cash.',
                'reward_amount' => 3.00,
                'enabled' => true,
                'display_order' => 30,
                'task_type' => 'review_event',
                'verification_mode' => 'google_business_review',
                'auto_award' => true,
                'action_url' => null,
                'button_text' => 'Leave a review',
                'completion_rule' => json_encode(['trigger' => 'google_business_review_matched']),
                'max_completions_per_customer' => 1,
                'requires_manual_approval' => false,
                'requires_customer_submission' => false,
                'icon' => 'star',
                'eligibility_type' => 'everyone',
                'required_customer_tags' => null,
                'required_membership_status' => null,
                'visible_to_noneligible_customers' => false,
                'locked_message' => null,
                'locked_cta_text' => null,
                'locked_cta_url' => null,
                'campaign_key' => null,
                'external_object_id' => null,
                'verification_window_hours' => 336,
                'matching_rules' => json_encode(['match_by' => ['profile_id', 'normalized_email', 'normalized_phone'], 'allow_manual_submit' => false]),
                'metadata' => json_encode(['customer_visible' => true]),
                'admin_notes' => 'Auto-awards only when a matched Google Business review event is received.',
                'archived_at' => null,
            ],
            [
                'handle' => 'refer-a-friend',
                'title' => 'Refer a friend who places a qualifying first order',
                'description' => 'Share your link and earn $10 in Candle Cash when a friend places their first qualifying order.',
                'reward_amount' => 10.00,
                'enabled' => true,
                'display_order' => 40,
                'task_type' => 'referral_event',
                'verification_mode' => 'referral_conversion',
                'auto_award' => true,
                'action_url' => '#candle-cash-referrals',
                'button_text' => 'Share your link',
                'completion_rule' => json_encode(['trigger' => 'first_qualifying_order']),
                'max_completions_per_customer' => 999999,
                'requires_manual_approval' => false,
                'requires_customer_submission' => false,
                'icon' => 'gift',
                'eligibility_type' => 'everyone',
                'required_customer_tags' => null,
                'required_membership_status' => null,
                'visible_to_noneligible_customers' => false,
                'locked_message' => null,
                'locked_cta_text' => null,
                'locked_cta_url' => null,
                'campaign_key' => null,
                'external_object_id' => null,
                'verification_window_hours' => 720,
                'matching_rules' => json_encode(['match_by' => ['referral_code', 'qualifying_order_id'], 'allow_manual_submit' => false]),
                'metadata' => json_encode(['customer_visible' => true, 'growth_priority' => 'primary']),
                'admin_notes' => 'Primary growth engine. Award only after the referred customer completes a qualifying first order.',
                'archived_at' => null,
            ],
            [
                'handle' => 'referred-friend-bonus',
                'title' => 'Friend bonus after first order',
                'description' => 'Friends who use a referral and place a qualifying first order earn $5 in Candle Cash too.',
                'reward_amount' => 5.00,
                'enabled' => true,
                'display_order' => 50,
                'task_type' => 'referral_event',
                'verification_mode' => 'referral_conversion',
                'auto_award' => true,
                'action_url' => '#candle-cash-referrals',
                'button_text' => 'See referral details',
                'completion_rule' => json_encode(['trigger' => 'referred_customer_qualifies']),
                'max_completions_per_customer' => 999999,
                'requires_manual_approval' => false,
                'requires_customer_submission' => false,
                'icon' => 'spark',
                'eligibility_type' => 'everyone',
                'required_customer_tags' => null,
                'required_membership_status' => null,
                'visible_to_noneligible_customers' => false,
                'locked_message' => null,
                'locked_cta_text' => null,
                'locked_cta_url' => null,
                'campaign_key' => null,
                'external_object_id' => null,
                'verification_window_hours' => 720,
                'matching_rules' => json_encode(['match_by' => ['referral_code', 'qualifying_order_id'], 'allow_manual_submit' => false]),
                'metadata' => json_encode(['customer_visible' => true]),
                'admin_notes' => 'Friend-side bonus after the qualifying referral event lands.',
                'archived_at' => null,
            ],
            [
                'handle' => 'birthday-signup',
                'title' => 'Add birthday',
                'description' => 'Save your birthday and we will add Candle Cash automatically.',
                'reward_amount' => 2.00,
                'enabled' => true,
                'display_order' => 60,
                'task_type' => 'profile_event',
                'verification_mode' => 'system_event',
                'auto_award' => true,
                'action_url' => '#candle-cash-birthday',
                'button_text' => 'Add birthday',
                'completion_rule' => json_encode(['trigger' => 'birthday_saved']),
                'max_completions_per_customer' => 1,
                'requires_manual_approval' => false,
                'requires_customer_submission' => false,
                'icon' => 'cake',
                'eligibility_type' => 'everyone',
                'required_customer_tags' => null,
                'required_membership_status' => null,
                'visible_to_noneligible_customers' => false,
                'locked_message' => null,
                'locked_cta_text' => null,
                'locked_cta_url' => null,
                'campaign_key' => null,
                'external_object_id' => null,
                'verification_window_hours' => 24,
                'matching_rules' => json_encode(['dedupe_scope' => 'profile_or_year', 'allow_manual_submit' => false]),
                'metadata' => json_encode(['customer_visible' => true]),
                'admin_notes' => 'Auto-awarded when birthday is saved according to the configured business rule.',
                'archived_at' => null,
            ],
            [
                'handle' => 'candle-club-join',
                'title' => 'Join Candle Club',
                'description' => 'Become an active Candle Club member and unlock a small Candle Cash thank-you.',
                'reward_amount' => 2.00,
                'enabled' => true,
                'display_order' => 70,
                'task_type' => 'membership_event',
                'verification_mode' => 'system_event',
                'auto_award' => true,
                'action_url' => '/products/modern-forestry-candle-club-16oz-subscription-with-gifts',
                'button_text' => 'Join Candle Club',
                'completion_rule' => json_encode(['trigger' => 'candle_club_membership_active']),
                'max_completions_per_customer' => 1,
                'requires_manual_approval' => false,
                'requires_customer_submission' => false,
                'icon' => 'club',
                'eligibility_type' => 'everyone',
                'required_customer_tags' => null,
                'required_membership_status' => null,
                'visible_to_noneligible_customers' => false,
                'locked_message' => null,
                'locked_cta_text' => null,
                'locked_cta_url' => null,
                'campaign_key' => null,
                'external_object_id' => null,
                'verification_window_hours' => 168,
                'matching_rules' => json_encode(['dedupe_scope' => 'profile', 'allow_manual_submit' => false]),
                'metadata' => json_encode(['customer_visible' => true]),
                'admin_notes' => 'Auto-awarded when a customer becomes an active Candle Club member.',
                'archived_at' => null,
            ],
            [
                'handle' => 'candle-club-vote',
                'title' => 'Candle Club voting reward',
                'description' => 'Vote on a new scent, seasonal release, or label option and earn $1 in Candle Cash.',
                'reward_amount' => 1.00,
                'enabled' => true,
                'display_order' => 80,
                'task_type' => 'onsite_action',
                'verification_mode' => 'onsite_action',
                'auto_award' => true,
                'action_url' => '#candle-cash-vote',
                'button_text' => 'Vote now',
                'completion_rule' => json_encode(['trigger' => 'onsite_vote_recorded']),
                'max_completions_per_customer' => 999999,
                'requires_manual_approval' => false,
                'requires_customer_submission' => false,
                'icon' => 'spark',
                'eligibility_type' => 'candle_club_only',
                'required_customer_tags' => null,
                'required_membership_status' => 'active_candle_club_member',
                'visible_to_noneligible_customers' => true,
                'locked_message' => 'Candle Club exclusive',
                'locked_cta_text' => 'Join Candle Club',
                'locked_cta_url' => '/products/modern-forestry-candle-club-16oz-subscription-with-gifts',
                'campaign_key' => 'default-vote-campaign',
                'external_object_id' => null,
                'verification_window_hours' => 72,
                'matching_rules' => json_encode(['dedupe_scope' => 'profile_per_campaign', 'allow_manual_submit' => true]),
                'metadata' => json_encode(['customer_visible' => true]),
                'admin_notes' => 'Candle Club exclusive. Backend only awards this when the vote is recorded through a first-party on-site action.',
                'archived_at' => null,
            ],
            [
                'handle' => 'second-order',
                'title' => 'Place a second order',
                'description' => 'Come back for a second order and earn $5 in Candle Cash automatically.',
                'reward_amount' => 5.00,
                'enabled' => true,
                'display_order' => 90,
                'task_type' => 'order_event',
                'verification_mode' => 'system_event',
                'auto_award' => true,
                'action_url' => null,
                'button_text' => 'Keep shopping',
                'completion_rule' => json_encode(['trigger' => 'second_order']),
                'max_completions_per_customer' => 1,
                'requires_manual_approval' => false,
                'requires_customer_submission' => false,
                'icon' => 'repeat',
                'eligibility_type' => 'everyone',
                'required_customer_tags' => null,
                'required_membership_status' => null,
                'visible_to_noneligible_customers' => false,
                'locked_message' => null,
                'locked_cta_text' => null,
                'locked_cta_url' => null,
                'campaign_key' => null,
                'external_object_id' => null,
                'verification_window_hours' => 720,
                'matching_rules' => json_encode(['dedupe_scope' => 'trigger_order', 'allow_manual_submit' => false]),
                'metadata' => json_encode(['customer_visible' => true]),
                'admin_notes' => 'System-awarded once when a linked customer completes order number two.',
                'archived_at' => null,
            ],
            [
                'handle' => 'product-review',
                'title' => 'Product review through supported review platform',
                'description' => 'Leave a verified product review through our supported review platform and earn a small Candle Cash reward.',
                'reward_amount' => 2.00,
                'enabled' => false,
                'display_order' => 100,
                'task_type' => 'review_event',
                'verification_mode' => 'product_review_platform_event',
                'auto_award' => true,
                'action_url' => null,
                'button_text' => 'Write a review',
                'completion_rule' => json_encode(['trigger' => 'product_review_verified']),
                'max_completions_per_customer' => 999999,
                'requires_manual_approval' => false,
                'requires_customer_submission' => false,
                'icon' => 'review',
                'eligibility_type' => 'everyone',
                'required_customer_tags' => null,
                'required_membership_status' => null,
                'visible_to_noneligible_customers' => false,
                'locked_message' => null,
                'locked_cta_text' => null,
                'locked_cta_url' => null,
                'campaign_key' => null,
                'external_object_id' => null,
                'verification_window_hours' => 336,
                'matching_rules' => json_encode(['match_by' => ['profile_id', 'external_customer_id'], 'allow_manual_submit' => false]),
                'metadata' => json_encode(['customer_visible' => true, 'inactive_reason' => 'awaiting_verified_review_integration']),
                'admin_notes' => 'Leave inactive until a verified review integration is connected.',
                'archived_at' => null,
            ],
        ];

        foreach ($tasks as $task) {
            DB::table('candle_cash_tasks')->updateOrInsert(
                ['handle' => $task['handle']],
                array_merge($task, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }

        DB::table('candle_cash_tasks')
            ->whereIn('handle', ['photo-review', 'instagram-follow', 'share-post'])
            ->update([
                'enabled' => false,
                'archived_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('candle_cash_task_events');

        if (Schema::hasTable('candle_cash_tasks')) {
            Schema::table('candle_cash_tasks', function (Blueprint $table): void {
                foreach (['verification_mode', 'auto_award', 'campaign_key', 'external_object_id', 'verification_window_hours', 'matching_rules', 'metadata'] as $column) {
                    if (Schema::hasColumn('candle_cash_tasks', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        DB::table('marketing_settings')->where('key', 'candle_cash_integration_config')->delete();
    }
};
