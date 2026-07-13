<?php

namespace App\Services\Marketing;

class MarketingProfileMergeReferenceRegistry
{
    /**
     * Profile references that can be moved directly without creating a profile-based unique collision.
     *
     * @return array<string,array<int,string>>
     */
    public function directReferences(): array
    {
        return [
            'marketing_identity_reviews' => ['proposed_marketing_profile_id'],
            'marketing_recommendations' => ['marketing_profile_id'],
            'marketing_profile_scores' => ['marketing_profile_id'],
            'marketing_campaign_conversions' => ['marketing_profile_id'],
            'marketing_group_import_rows' => ['marketing_profile_id'],
            'candle_cash_redemptions' => ['marketing_profile_id'],
            'customer_birthday_profiles' => ['marketing_profile_id'],
            'customer_birthday_audits' => ['marketing_profile_id'],
            'marketing_message_group_members' => ['marketing_profile_id'],
            'marketing_review_summaries' => ['marketing_profile_id'],
            'birthday_message_events' => ['marketing_profile_id'],
            'candle_cash_task_completions' => ['marketing_profile_id'],
            'candle_cash_referrals' => ['referrer_marketing_profile_id', 'referred_marketing_profile_id'],
            'candle_cash_task_events' => ['marketing_profile_id'],
            'google_business_profile_reviews' => ['marketing_profile_id'],
            'marketing_profile_links' => ['marketing_profile_id'],
            'marketing_consent_requests' => ['marketing_profile_id'],
            'marketing_consent_events' => ['marketing_profile_id'],
            'customer_external_profiles' => ['marketing_profile_id'],
            'marketing_storefront_events' => ['marketing_profile_id'],
            'marketing_automation_events' => ['marketing_profile_id'],
            'marketing_email_deliveries' => ['marketing_profile_id'],
            'marketing_review_histories' => ['marketing_profile_id'],
            'marketing_wishlist_lists' => ['marketing_profile_id'],
            'marketing_profile_wishlist_items' => ['marketing_profile_id'],
            'marketing_wishlist_outreach_queue' => ['marketing_profile_id'],
            'marketing_message_deliveries' => ['marketing_profile_id'],
            'marketing_message_engagement_events' => ['marketing_profile_id'],
            'marketing_message_order_attributions' => ['marketing_profile_id'],
            'messaging_conversations' => ['marketing_profile_id'],
            'marketing_message_jobs' => ['marketing_profile_id'],
            'messaging_conversation_messages' => ['marketing_profile_id'],
            'messaging_contact_channel_states' => ['marketing_profile_id'],
            'subscription_customers' => ['marketing_profile_id'],
            'subscription_contracts' => ['marketing_profile_id'],
            'subscription_votes' => ['marketing_profile_id'],
            'subscription_candle_club_scent_feedback' => ['marketing_profile_id'],
            'mobile_push_devices' => ['marketing_profile_id'],
            'field_service_jobs' => ['marketing_profile_id'],
        ];
    }

    /**
     * Rows with these business keys must be collapsed before the profile FK moves.
     * An empty key list means at most one row may survive for the profile.
     *
     * @return array<string,array{column:string,keys:array<int,string>}>
     */
    public function conflictReferences(): array
    {
        return [
            'marketing_external_campaign_stats' => ['column' => 'marketing_profile_id', 'keys' => ['source_type', 'external_contact_id']],
            'marketing_campaign_recipients' => ['column' => 'marketing_profile_id', 'keys' => ['campaign_id']],
            'marketing_group_members' => ['column' => 'marketing_profile_id', 'keys' => ['marketing_group_id']],
            'birthday_reward_issuances' => ['column' => 'marketing_profile_id', 'keys' => ['cycle_year', 'reward_type']],
            'marketing_profile_scent_quiz_results' => ['column' => 'marketing_profile_id', 'keys' => []],
            'marketing_social_share_claims' => ['column' => 'marketing_profile_id', 'keys' => ['platform', 'target_type', 'target_id']],
            'modern_forestry_mobile_bag_snapshots' => ['column' => 'marketing_profile_id', 'keys' => []],
        ];
    }

    /** @return array<int,string> */
    public function handledTables(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->directReferences()),
            array_keys($this->conflictReferences()),
            ['candle_cash_transactions', 'candle_cash_balances']
        )));
    }
}
