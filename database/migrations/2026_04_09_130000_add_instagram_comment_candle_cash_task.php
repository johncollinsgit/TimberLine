<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('candle_cash_tasks')) {
            return;
        }

        $now = now();

        DB::table('candle_cash_tasks')->updateOrInsert(
            ['handle' => 'instagram-comment'],
            [
                'title' => 'Leave an Instagram comment',
                'description' => 'Comment on a qualifying Instagram post or reel and earn Candle Cash after our team reviews it.',
                'reward_amount' => 2.00,
                'enabled' => true,
                'display_order' => 35,
                'task_type' => 'manual_submission',
                'verification_mode' => 'manual_review_fallback',
                'auto_award' => false,
                'action_url' => 'https://www.instagram.com/theforestrystudio/',
                'button_text' => 'Open Instagram',
                'completion_rule' => json_encode([
                    'trigger' => 'manual_submission',
                ], JSON_UNESCAPED_SLASHES),
                'max_completions_per_customer' => 1,
                'requires_manual_approval' => true,
                'requires_customer_submission' => true,
                'icon' => 'instagram',
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
                'matching_rules' => json_encode([
                    'dedupe_scope' => 'profile',
                    'allow_manual_submit' => true,
                ], JSON_UNESCAPED_SLASHES),
                'metadata' => json_encode([
                    'customer_visible' => true,
                    'manual_submission' => [
                        'open_label' => 'Open Instagram',
                        'submit_label' => 'Submit comment details',
                        'proof_url_label' => 'Instagram post or reel URL',
                        'proof_url_placeholder' => 'https://www.instagram.com/p/... or /reel/...',
                        'proof_url_required' => true,
                        'proof_url_required_message' => 'Add the Instagram post or reel URL where you left your comment.',
                        'proof_text_label' => 'Comment text or summary',
                        'proof_text_placeholder' => 'Paste your comment text or summarize what you posted.',
                        'proof_text_required' => true,
                        'proof_text_required_message' => 'Add your comment text or a short summary so we can verify it.',
                        'extra_field_key' => 'instagram_handle',
                        'extra_field_label' => 'Instagram handle',
                        'extra_field_placeholder' => '@yourhandle',
                        'extra_field_required' => true,
                        'extra_field_required_message' => 'Add the Instagram handle you used for the comment.',
                        'pending_success_copy' => 'We saved your submission. Candle Cash lands after our team reviews your Instagram comment.',
                    ],
                ], JSON_UNESCAPED_SLASHES),
                'admin_notes' => 'Manual proof submission only. Team review in the Candle Cash queue is required before reward is awarded.',
                'archived_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('candle_cash_tasks')) {
            return;
        }

        DB::table('candle_cash_tasks')
            ->where('handle', 'instagram-comment')
            ->delete();
    }
};
