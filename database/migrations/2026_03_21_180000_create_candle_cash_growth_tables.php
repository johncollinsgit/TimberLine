<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('candle_cash_tasks')) {
            Schema::create('candle_cash_tasks', function (Blueprint $table): void {
                $table->id();
                $table->string('handle')->unique();
                $table->string('title');
                $table->text('description')->nullable();
                $table->decimal('reward_amount', 8, 2)->default(0);
                $table->boolean('enabled')->default(true)->index();
                $table->unsignedInteger('display_order')->default(0)->index();
                $table->string('task_type')->index();
                $table->string('action_url')->nullable();
                $table->string('button_text')->nullable();
                $table->json('completion_rule')->nullable();
                $table->unsignedInteger('max_completions_per_customer')->default(1);
                $table->boolean('requires_manual_approval')->default(false)->index();
                $table->boolean('requires_customer_submission')->default(false)->index();
                $table->string('icon')->nullable();
                $table->date('start_date')->nullable()->index();
                $table->date('end_date')->nullable()->index();
                $table->string('eligibility_type')->default('everyone')->index();
                $table->json('required_customer_tags')->nullable();
                $table->string('required_membership_status')->nullable()->index();
                $table->boolean('visible_to_noneligible_customers')->default(false);
                $table->string('locked_message')->nullable();
                $table->string('locked_cta_text')->nullable();
                $table->string('locked_cta_url')->nullable();
                $table->text('admin_notes')->nullable();
                $table->timestamp('archived_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('candle_cash_task_completions')) {
            Schema::create('candle_cash_task_completions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('candle_cash_task_id')->constrained('candle_cash_tasks')->cascadeOnDelete();
                $table->foreignId('marketing_profile_id')->constrained('marketing_profiles')->cascadeOnDelete();
                $table->string('status')->index();
                $table->string('completion_key')->nullable()->unique();
                $table->string('request_key')->nullable()->index();
                $table->decimal('reward_amount', 8, 2)->default(0);
                $table->integer('reward_points')->default(0);
                $table->string('source_type')->nullable()->index();
                $table->string('source_id')->nullable()->index();
                $table->string('proof_url')->nullable();
                $table->text('proof_text')->nullable();
                $table->json('submission_payload')->nullable();
                $table->string('blocked_reason')->nullable()->index();
                $table->text('review_notes')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('candle_cash_transaction_id')->nullable()->constrained('candle_cash_transactions')->nullOnDelete();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('submitted_at')->nullable()->index();
                $table->timestamp('reviewed_at')->nullable()->index();
                $table->timestamp('awarded_at')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['candle_cash_task_id', 'marketing_profile_id', 'status'], 'cctc_task_profile_status_idx');
            });
        }

        if (! Schema::hasTable('candle_cash_referrals')) {
            Schema::create('candle_cash_referrals', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('referrer_marketing_profile_id')->constrained('marketing_profiles')->cascadeOnDelete();
                $table->foreignId('referred_marketing_profile_id')->nullable()->constrained('marketing_profiles')->nullOnDelete();
                $table->string('referral_code')->index();
                $table->string('referred_identity_key')->nullable();
                $table->string('referred_email')->nullable()->index();
                $table->string('normalized_email')->nullable()->index();
                $table->string('referred_phone')->nullable()->index();
                $table->string('normalized_phone')->nullable()->index();
                $table->string('status')->default('captured')->index();
                $table->string('qualifying_order_source')->nullable()->index();
                $table->string('qualifying_order_id')->nullable()->index();
                $table->string('qualifying_order_number')->nullable();
                $table->decimal('qualifying_order_total', 10, 2)->nullable();
                $table->foreignId('referrer_completion_id')->nullable()->constrained('candle_cash_task_completions')->nullOnDelete();
                $table->foreignId('referred_completion_id')->nullable()->constrained('candle_cash_task_completions')->nullOnDelete();
                $table->foreignId('referrer_transaction_id')->nullable()->constrained('candle_cash_transactions')->nullOnDelete();
                $table->foreignId('referred_transaction_id')->nullable()->constrained('candle_cash_transactions')->nullOnDelete();
                $table->string('referrer_reward_status')->default('pending')->index();
                $table->string('referred_reward_status')->default('pending')->index();
                $table->timestamp('first_seen_at')->nullable()->index();
                $table->timestamp('qualified_at')->nullable()->index();
                $table->timestamp('rewarded_at')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['referral_code', 'referred_identity_key'], 'ccr_code_identity_unique');
            });
        }

        $now = now();

        DB::table('marketing_settings')->upsert([
            [
                'key' => 'candle_cash_program_config',
                'value' => json_encode([
                    'label' => 'Candle Cash',
                    'points_per_dollar' => 10,
                    'google_review_requires_manual_approval' => true,
                    'email_signup_auto_award' => true,
                    'instagram_follow_approval_mode' => 'honor',
                    'birthday_reward_frequency' => 'once_per_year',
                    'homepage_signup_copy' => 'Join and get $5 in Candle Cash instantly.',
                    'homepage_central_title' => 'Candle Cash Central',
                    'homepage_central_copy' => 'Earn rewards through reviews, referrals, and a few easy wins.',
                ]),
                'description' => 'Core Candle Cash program settings for label text, reward math, and frontend messaging.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'candle_cash_referral_config',
                'value' => json_encode([
                    'enabled' => true,
                    'referrer_reward_amount' => 10,
                    'referred_reward_amount' => 5,
                    'qualifying_event' => 'first_order',
                    'qualifying_min_order_total' => null,
                    'program_headline' => 'Share Candle Cash with a friend',
                    'program_copy' => 'Give a friend an easy first reward and earn Candle Cash when they place their first qualifying order.',
                ]),
                'description' => 'Referral program settings for Candle Cash growth tasks.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'candle_cash_frontend_config',
                'value' => json_encode([
                    'central_title' => 'Candle Cash Central',
                    'central_subtitle' => 'Earn low-lift rewards by helping us grow through reviews, referrals, and simple engagement.',
                    'faq_approval_copy' => 'Manual approvals usually land within 1 to 3 business days.',
                    'faq_stack_copy' => 'One Candle Cash code can be used per order. It may combine with other discounts based on the current rewards stacking setting.',
                    'faq_pending_copy' => 'Pending tasks stay in your history until they are approved or declined.',
                ]),
                'description' => 'Customer-facing Candle Cash page copy and support text.',
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
                'task_type' => 'auto_event',
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
                'admin_notes' => 'Auto-awarded once when a customer confirms email marketing consent through the Forestry capture flow.',
                'archived_at' => null,
            ],
            [
                'handle' => 'google-review',
                'title' => 'Leave a Google review',
                'description' => 'Tell shoppers what you think and earn $3 in Candle Cash.',
                'reward_amount' => 3.00,
                'enabled' => true,
                'display_order' => 20,
                'task_type' => 'external_link',
                'action_url' => null,
                'button_text' => 'Leave a review',
                'completion_rule' => json_encode(['trigger' => 'customer_confirmation']),
                'max_completions_per_customer' => 1,
                'requires_manual_approval' => true,
                'requires_customer_submission' => true,
                'icon' => 'star',
                'eligibility_type' => 'everyone',
                'required_customer_tags' => null,
                'required_membership_status' => null,
                'visible_to_noneligible_customers' => false,
                'locked_message' => null,
                'locked_cta_text' => null,
                'locked_cta_url' => null,
                'admin_notes' => 'Set the live Google review URL in admin before promoting this task broadly.',
                'archived_at' => null,
            ],
            [
                'handle' => 'product-review',
                'title' => 'Leave a product review',
                'description' => 'Review a product you bought and earn $2 in Candle Cash.',
                'reward_amount' => 2.00,
                'enabled' => true,
                'display_order' => 30,
                'task_type' => 'review_triggered',
                'action_url' => null,
                'button_text' => 'Write a review',
                'completion_rule' => json_encode(['trigger' => 'manual_submission']),
                'max_completions_per_customer' => 1,
                'requires_manual_approval' => true,
                'requires_customer_submission' => true,
                'icon' => 'review',
                'eligibility_type' => 'everyone',
                'required_customer_tags' => null,
                'required_membership_status' => null,
                'visible_to_noneligible_customers' => false,
                'locked_message' => null,
                'locked_cta_text' => null,
                'locked_cta_url' => null,
                'admin_notes' => 'Use proof submission until direct product review events are wired.',
                'archived_at' => null,
            ],
            [
                'handle' => 'photo-review',
                'title' => 'Upload a photo review',
                'description' => 'Share a photo with your review and earn $4 in Candle Cash.',
                'reward_amount' => 4.00,
                'enabled' => true,
                'display_order' => 40,
                'task_type' => 'manual_submission',
                'action_url' => null,
                'button_text' => 'Submit photo',
                'completion_rule' => json_encode(['trigger' => 'manual_submission']),
                'max_completions_per_customer' => 1,
                'requires_manual_approval' => true,
                'requires_customer_submission' => true,
                'icon' => 'camera',
                'eligibility_type' => 'everyone',
                'required_customer_tags' => null,
                'required_membership_status' => null,
                'visible_to_noneligible_customers' => false,
                'locked_message' => null,
                'locked_cta_text' => null,
                'locked_cta_url' => null,
                'admin_notes' => 'Best used when customers can drop a review URL or image proof link.',
                'archived_at' => null,
            ],
            [
                'handle' => 'instagram-follow',
                'title' => 'Follow on Instagram',
                'description' => 'Follow us on Instagram and earn $1 in Candle Cash.',
                'reward_amount' => 1.00,
                'enabled' => true,
                'display_order' => 50,
                'task_type' => 'external_link',
                'action_url' => 'https://www.instagram.com/theforestrystudio/',
                'button_text' => 'Follow us',
                'completion_rule' => json_encode(['trigger' => 'customer_confirmation']),
                'max_completions_per_customer' => 1,
                'requires_manual_approval' => false,
                'requires_customer_submission' => false,
                'icon' => 'instagram',
                'eligibility_type' => 'everyone',
                'required_customer_tags' => null,
                'required_membership_status' => null,
                'visible_to_noneligible_customers' => false,
                'locked_message' => null,
                'locked_cta_text' => null,
                'locked_cta_url' => null,
                'admin_notes' => 'Honor-based by default. Switch to manual approval if abuse appears.',
                'archived_at' => null,
            ],
            [
                'handle' => 'share-post',
                'title' => 'Share a product or brand post',
                'description' => 'Share something you love and earn $2 in Candle Cash.',
                'reward_amount' => 2.00,
                'enabled' => true,
                'display_order' => 60,
                'task_type' => 'external_link',
                'action_url' => null,
                'button_text' => 'Share now',
                'completion_rule' => json_encode(['trigger' => 'customer_confirmation']),
                'max_completions_per_customer' => 1,
                'requires_manual_approval' => false,
                'requires_customer_submission' => false,
                'icon' => 'share',
                'eligibility_type' => 'everyone',
                'required_customer_tags' => null,
                'required_membership_status' => null,
                'visible_to_noneligible_customers' => false,
                'locked_message' => null,
                'locked_cta_text' => null,
                'locked_cta_url' => null,
                'admin_notes' => 'Use action_url for a campaign page or brand post if you want a guided flow.',
                'archived_at' => null,
            ],
            [
                'handle' => 'refer-a-friend',
                'title' => 'Refer a friend who makes a purchase',
                'description' => 'Share your link. You earn $10 when their first qualifying order lands, and they can earn $5 too.',
                'reward_amount' => 10.00,
                'enabled' => true,
                'display_order' => 70,
                'task_type' => 'referral_triggered',
                'action_url' => '#candle-cash-referrals',
                'button_text' => 'Share your link',
                'completion_rule' => json_encode(['trigger' => 'first_qualifying_order', 'referred_reward_amount' => 5]),
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
                'admin_notes' => 'Primary growth task. Award only after a qualifying first order is reconciled.',
                'archived_at' => null,
            ],
            [
                'handle' => 'birthday-signup',
                'title' => 'Birthday signup',
                'description' => 'Save your birthday and earn $2 in Candle Cash.',
                'reward_amount' => 2.00,
                'enabled' => true,
                'display_order' => 80,
                'task_type' => 'auto_event',
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
                'admin_notes' => 'Auto-awarded once when a customer adds a birthday through the customer-facing flow.',
                'archived_at' => null,
            ],
            [
                'handle' => 'candle-club-vote',
                'title' => 'Candle Club voting reward',
                'description' => 'Vote on a new scent, seasonal release, or label option and earn $1 in Candle Cash.',
                'reward_amount' => 1.00,
                'enabled' => true,
                'display_order' => 90,
                'task_type' => 'survey',
                'action_url' => null,
                'button_text' => 'Vote now',
                'completion_rule' => json_encode(['trigger' => 'manual_submission']),
                'max_completions_per_customer' => 1,
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
                'admin_notes' => 'Membership-gated. Non-members can see the locked card but cannot claim it.',
                'archived_at' => null,
            ],
            [
                'handle' => 'second-order',
                'title' => 'Place a second order',
                'description' => 'Come back for a second order and earn $5 in Candle Cash.',
                'reward_amount' => 5.00,
                'enabled' => true,
                'display_order' => 100,
                'task_type' => 'order_triggered',
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
                'admin_notes' => 'Award once when a linked customer completes order number two.',
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
    }

    public function down(): void
    {
        DB::table('marketing_settings')->whereIn('key', [
            'candle_cash_program_config',
            'candle_cash_referral_config',
            'candle_cash_frontend_config',
        ])->delete();

        Schema::dropIfExists('candle_cash_referrals');
        Schema::dropIfExists('candle_cash_task_completions');
        Schema::dropIfExists('candle_cash_tasks');
    }
};
