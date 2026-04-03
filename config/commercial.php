<?php

$catalog = require __DIR__.'/module_catalog.php';

$canonicalPlans = [];
foreach ((array) ($catalog['plans'] ?? []) as $planKey => $definition) {
    if (! is_array($definition)) {
        continue;
    }

    $canonicalPlans[$planKey] = [
        'modules' => array_values(array_map('strval', (array) ($definition['included_modules'] ?? []))),
        'eligible_addons' => array_values(array_map('strval', (array) ($definition['eligible_addons'] ?? []))),
        'track' => (string) ($definition['track'] ?? 'shopify'),
        'name' => (string) ($definition['display_name'] ?? $planKey),
        'position' => (int) ($definition['position'] ?? 100),
        'is_public' => (bool) ($definition['is_public'] ?? true),
    ];
}

$canonicalAddons = [];
foreach ((array) ($catalog['addons'] ?? []) as $addonKey => $definition) {
    if (! is_array($definition)) {
        continue;
    }

    $canonicalAddons[$addonKey] = [
        'modules' => array_values(array_map('strval', (array) ($definition['modules'] ?? []))),
        'legacy_grants' => array_values(array_map('strval', (array) ($definition['legacy_grants'] ?? []))),
        'name' => (string) ($definition['label'] ?? $definition['display_name'] ?? $addonKey),
    ];
}

return [
    /*
    |--------------------------------------------------------------------------
    | Public Product Structure
    |--------------------------------------------------------------------------
    |
    | These keys define the canonical commercial model that landlord and tenant
    | experiences should reference. Billing lifecycle writes remain disabled.
    |
    */
    'public_tier_order' => array_values(array_keys($canonicalPlans)),

    'legacy_plan_aliases' => is_array(($catalog['legacy'] ?? [])['plan_aliases'] ?? null)
        ? (array) ($catalog['legacy'] ?? [])['plan_aliases']
        : [],

    'usage_metrics' => [
        'contact_count' => [
            'label' => 'Contacts',
            'track_only' => true,
        ],
        'sms_usage' => [
            'label' => 'SMS Usage',
            'track_only' => true,
        ],
        'email_usage' => [
            'label' => 'Email Usage',
            'track_only' => true,
        ],
        'rewards_issued' => [
            'label' => 'Rewards Issued',
            'track_only' => true,
        ],
        'reward_reminder_sends' => [
            'label' => 'Reward Reminder Sends',
            'track_only' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Display Label Defaults
    |--------------------------------------------------------------------------
    |
    | User-facing loyalty/rewards wording is tenant-controlled. Labels resolve
    | in this order: tenant override -> template default -> global fallback.
    |
    */
    'display_label_defaults' => [
        'rewards' => 'Rewards',
        'rewards_label' => 'Rewards',
        'rewards_balance_label' => 'Rewards balance',
        'rewards_program_label' => 'Rewards program',
        'rewards_redemption_label' => 'Rewards redemption',
        'reward_credit_label' => 'reward credit',
        'birthdays' => 'Birthdays / Lifecycle',
        'birthdays_label' => 'Birthdays / Lifecycle',
        'birthday_reward_label' => 'Birthday reward',
    ],

    'plans' => [
        'starter' => [
            'name' => (string) ($canonicalPlans['starter']['name'] ?? 'Starter'),
            'track' => (string) ($canonicalPlans['starter']['track'] ?? 'shopify'),
            'is_public' => (bool) ($canonicalPlans['starter']['is_public'] ?? true),
            'is_highest_standard' => false,
            'position' => (int) ($canonicalPlans['starter']['position'] ?? 10),
            'currency' => 'USD',
            'recurring_price_cents' => 14900,
            'setup_price_cents' => 4900,
            'included_usage' => [
                'store_channels' => 1,
                'contact_count' => 2000,
            ],
            'modules' => (array) ($canonicalPlans['starter']['modules'] ?? []),
            'eligible_addons' => (array) ($canonicalPlans['starter']['eligible_addons'] ?? []),
        ],
        'growth' => [
            'name' => (string) ($canonicalPlans['growth']['name'] ?? 'Growth'),
            'track' => (string) ($canonicalPlans['growth']['track'] ?? 'shopify'),
            'is_public' => (bool) ($canonicalPlans['growth']['is_public'] ?? true),
            'is_highest_standard' => false,
            'position' => (int) ($canonicalPlans['growth']['position'] ?? 20),
            'currency' => 'USD',
            'recurring_price_cents' => 24900,
            'setup_price_cents' => 7900,
            'included_usage' => [
                'store_channels' => 1,
                'contact_count' => 10000,
            ],
            'modules' => (array) ($canonicalPlans['growth']['modules'] ?? []),
            'eligible_addons' => (array) ($canonicalPlans['growth']['eligible_addons'] ?? []),
        ],
        'pro' => [
            'name' => (string) ($canonicalPlans['pro']['name'] ?? 'Pro'),
            'track' => (string) ($canonicalPlans['pro']['track'] ?? 'shopify'),
            'is_public' => (bool) ($canonicalPlans['pro']['is_public'] ?? true),
            'is_highest_standard' => true,
            'position' => (int) ($canonicalPlans['pro']['position'] ?? 30),
            'currency' => 'USD',
            'recurring_price_cents' => 39900,
            'setup_price_cents' => 9900,
            'included_usage' => [
                'store_channels' => 1,
                'contact_count' => 25000,
            ],
            'modules' => (array) ($canonicalPlans['pro']['modules'] ?? []),
            'eligible_addons' => (array) ($canonicalPlans['pro']['eligible_addons'] ?? []),
        ],
    ],

    'addons' => [
        'referrals' => [
            'name' => (string) ($canonicalAddons['referrals']['name'] ?? 'Referrals'),
            'position' => 10,
            'currency' => 'USD',
            'recurring_price_cents' => 7900,
            'setup_price_cents' => 0,
            'modules' => (array) ($canonicalAddons['referrals']['modules'] ?? ['referrals']),
        ],
        'sms' => [
            'name' => (string) ($canonicalAddons['sms']['name'] ?? 'SMS'),
            'position' => 20,
            'currency' => 'USD',
            'recurring_price_cents' => 9900,
            'setup_price_cents' => 0,
            'modules' => (array) ($canonicalAddons['sms']['modules'] ?? ['sms']),
        ],
        'messaging' => [
            'name' => (string) ($canonicalAddons['messaging']['name'] ?? 'Messaging'),
            'position' => 30,
            'currency' => 'USD',
            'recurring_price_cents' => 11900,
            'setup_price_cents' => 0,
            'modules' => (array) ($canonicalAddons['messaging']['modules'] ?? ['messaging']),
        ],
        'additional_channels' => [
            'name' => (string) ($canonicalAddons['additional_channels']['name'] ?? 'Additional Stores/Channels'),
            'position' => 40,
            'currency' => 'USD',
            'recurring_price_cents' => 5900,
            'setup_price_cents' => 0,
            'modules' => (array) ($canonicalAddons['additional_channels']['modules'] ?? ['additional_channels']),
            'legacy_grants' => (array) ($canonicalAddons['additional_channels']['legacy_grants'] ?? ['shopify']),
        ],
        'bulk_email_marketing' => [
            'name' => (string) ($canonicalAddons['bulk_email_marketing']['name'] ?? 'Bulk Marketing Email'),
            'position' => 50,
            'currency' => 'USD',
            'recurring_price_cents' => 12900,
            'setup_price_cents' => 0,
            'modules' => (array) ($canonicalAddons['bulk_email_marketing']['modules'] ?? ['bulk_email_marketing']),
            'legacy_grants' => (array) ($canonicalAddons['bulk_email_marketing']['legacy_grants'] ?? ['email']),
        ],
        'future_niche_modules' => [
            'name' => (string) ($canonicalAddons['future_niche_modules']['name'] ?? 'Future Niche Modules'),
            'position' => 90,
            'currency' => 'USD',
            'recurring_price_cents' => 0,
            'setup_price_cents' => 0,
            'modules' => (array) ($canonicalAddons['future_niche_modules']['modules'] ?? ['future_niche_modules']),
            'legacy_grants' => (array) ($canonicalAddons['future_niche_modules']['legacy_grants'] ?? ['ai']),
        ],
    ],

    'setup_packages' => [
        'guided_launch' => [
            'name' => 'Guided Launch',
            'position' => 10,
            'currency' => 'USD',
            'setup_price_cents' => 14900,
        ],
        'accelerated_migration' => [
            'name' => 'Accelerated Migration',
            'position' => 20,
            'currency' => 'USD',
            'setup_price_cents' => 29900,
        ],
    ],

    'support_tiers' => [
        'priority_support' => [
            'name' => 'Priority Support',
            'position' => 10,
            'currency' => 'USD',
            'recurring_price_cents' => 6900,
            'setup_price_cents' => 0,
        ],
    ],

    'templates' => [
        'candle' => [
            'name' => 'Candle',
            'position' => 10,
            'active' => true,
            'default_labels' => [
                'rewards' => 'Candle Cash',
                'rewards_label' => 'Candle Cash',
                'rewards_balance_label' => 'Candle Cash balance',
                'rewards_program_label' => 'Candle Cash program',
                'rewards_redemption_label' => 'Candle Cash redemption',
                'reward_credit_label' => 'Candle Cash credit',
                'birthdays' => 'Birthday Rewards',
                'birthdays_label' => 'Birthday Rewards',
                'birthday_reward_label' => 'Birthday reward',
            ],
            'recommended_modules' => [
                'starter' => ['reviews', 'lead_capture'],
                'growth' => ['rewards', 'birthdays', 'campaigns'],
                'pro' => ['wishlist', 'diagnostics_advanced'],
            ],
            'dashboard_layout' => ['rewards', 'birthdays', 'customers', 'reporting'],
            'navigation_emphasis' => ['rewards', 'customers', 'settings'],
            'onboarding_checklist' => [
                'Verify storefront reward contract parity',
                'Confirm birthday capture and redemption parity',
                'Validate email readiness for reward workflows',
            ],
        ],
        'law' => [
            'name' => 'Law',
            'position' => 20,
            'active' => true,
            'default_labels' => [
                'rewards' => 'Client Credits',
                'rewards_label' => 'Client Credits',
                'rewards_balance_label' => 'Client credit balance',
                'rewards_program_label' => 'Client credit program',
                'rewards_redemption_label' => 'Client credit redemption',
                'reward_credit_label' => 'credit',
                'birthdays' => 'Milestone Outreach',
                'birthdays_label' => 'Milestone Outreach',
                'birthday_reward_label' => 'Milestone reward',
            ],
            'recommended_modules' => [
                'starter' => ['lead_capture', 'reviews'],
                'growth' => ['campaigns', 'email'],
                'pro' => ['diagnostics_advanced'],
            ],
            'dashboard_layout' => ['customers', 'campaigns', 'reporting', 'settings'],
            'navigation_emphasis' => ['customers', 'campaigns', 'settings'],
            'onboarding_checklist' => [
                'Configure intake and lead capture terms',
                'Set communication approval workflow',
                'Review diagnostics baseline before launch',
            ],
        ],
        'landscaping' => [
            'name' => 'Landscaping',
            'position' => 30,
            'active' => true,
            'default_labels' => [
                'rewards' => 'Service Credits',
                'rewards_label' => 'Service Credits',
                'rewards_balance_label' => 'Service credit balance',
                'rewards_program_label' => 'Service credit program',
                'rewards_redemption_label' => 'Service credit redemption',
                'reward_credit_label' => 'credit',
                'birthdays' => 'Seasonal Milestones',
                'birthdays_label' => 'Seasonal Milestones',
                'birthday_reward_label' => 'Seasonal reward',
            ],
            'recommended_modules' => [
                'starter' => ['lead_capture', 'reviews'],
                'growth' => ['rewards', 'campaigns'],
                'pro' => ['diagnostics_advanced', 'wishlist'],
            ],
            'dashboard_layout' => ['customers', 'rewards', 'campaigns', 'reporting'],
            'navigation_emphasis' => ['customers', 'rewards', 'campaigns'],
            'onboarding_checklist' => [
                'Confirm seasonal campaign presets',
                'Validate referral and review follow-up prompts',
                'Check communications readiness',
            ],
        ],
        'apparel' => [
            'name' => 'Apparel',
            'position' => 40,
            'active' => true,
            'default_labels' => [
                'rewards' => 'Style Credits',
                'rewards_label' => 'Style Credits',
                'rewards_balance_label' => 'Style credit balance',
                'rewards_program_label' => 'Style credit program',
                'rewards_redemption_label' => 'Style credit redemption',
                'reward_credit_label' => 'credit',
                'birthdays' => 'Birthday Perks',
                'birthdays_label' => 'Birthday Perks',
                'birthday_reward_label' => 'Birthday perk',
            ],
            'recommended_modules' => [
                'starter' => ['reviews', 'lead_capture'],
                'growth' => ['rewards', 'birthdays', 'campaigns'],
                'pro' => ['wishlist', 'diagnostics_advanced'],
            ],
            'dashboard_layout' => ['rewards', 'wishlist', 'customers', 'reporting'],
            'navigation_emphasis' => ['rewards', 'wishlist', 'customers'],
            'onboarding_checklist' => [
                'Configure catalog-linked rewards messaging',
                'Verify birthday perk automation',
                'Confirm diagnostics export baseline',
            ],
        ],
        'generic' => [
            'name' => 'Generic',
            'position' => 50,
            'active' => true,
            'default_labels' => [
                'rewards' => 'Rewards',
                'rewards_label' => 'Rewards',
                'rewards_balance_label' => 'Rewards balance',
                'rewards_program_label' => 'Rewards program',
                'rewards_redemption_label' => 'Rewards redemption',
                'reward_credit_label' => 'reward credit',
                'birthdays' => 'Lifecycle',
                'birthdays_label' => 'Lifecycle',
                'birthday_reward_label' => 'Birthday reward',
            ],
            'recommended_modules' => [
                'starter' => ['lead_capture', 'reviews'],
                'growth' => ['rewards', 'birthdays', 'campaigns'],
                'pro' => ['wishlist', 'diagnostics_advanced'],
            ],
            'dashboard_layout' => ['customers', 'rewards', 'reporting', 'settings'],
            'navigation_emphasis' => ['customers', 'rewards', 'reporting'],
            'onboarding_checklist' => [
                'Set default labels and dashboard order',
                'Confirm communication readiness and fallbacks',
                'Verify module access against assigned tier',
            ],
        ],
    ],

    'billing_readiness' => [
        'checkout_active' => false,
        'lifecycle_mutations_enabled' => false,
        'provider_priority' => ['stripe', 'braintree'],
        'providers' => [
            'stripe' => [
                'role' => 'primary',
                'status' => 'guarded_customer_sync',
            ],
            'braintree' => [
                'role' => 'secondary',
                'status' => 'future',
            ],
        ],
        'guarded_actions' => [
            'stripe_customer_sync' => [
                'enabled' => (bool) env('COMMERCIAL_STRIPE_CUSTOMER_SYNC_ENABLED', true),
                'requires_lifecycle_disabled' => true,
                'landlord_only' => true,
            ],
            'stripe_subscription_prep' => [
                'enabled' => (bool) env('COMMERCIAL_STRIPE_SUBSCRIPTION_PREP_ENABLED', true),
                'requires_lifecycle_disabled' => true,
                'landlord_only' => true,
                'requires_customer_reference' => true,
            ],
            'stripe_live_subscription_sync' => [
                'enabled' => (bool) env('COMMERCIAL_STRIPE_LIVE_SUBSCRIPTION_SYNC_ENABLED', false),
                'requires_lifecycle_disabled' => true,
                'landlord_only' => true,
                'requires_customer_reference' => true,
                'requires_subscription_prep' => true,
                'requires_prep_hash' => true,
                'allow_sync_existing_reference' => true,
                'collection_method' => 'send_invoice',
                'days_until_due' => 30,
            ],
        ],
        'required_evidence_docs' => [
            'docs/operations/staging-commercial-uat-runbook.md',
            'docs/operations/staging-commercial-uat-evidence-template.md',
            'docs/operations/pre-billing-readiness-gate.md',
            'docs/operations/billing-activation-checklist.md',
        ],
        'required_tenant_billing_fields' => [
            'stripe.customer_reference',
            'stripe.subscription_reference',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe-First Billing Map (Configuration Only)
    |--------------------------------------------------------------------------
    |
    | This map formalizes future provider lookup keys while lifecycle writes are
    | disabled. These keys should be treated as readiness metadata only.
    |
    */
    'stripe_mapping' => [
        'status' => 'configuration_only',
        'currency' => 'USD',
        'tiers' => [
            'starter' => [
                'product_lookup_key' => 'tier_starter',
                'recurring_price_lookup_key' => 'tier_starter_monthly',
                'setup_price_lookup_key' => 'tier_starter_setup',
            ],
            'growth' => [
                'product_lookup_key' => 'tier_growth',
                'recurring_price_lookup_key' => 'tier_growth_monthly',
                'setup_price_lookup_key' => 'tier_growth_setup',
            ],
            'pro' => [
                'product_lookup_key' => 'tier_pro',
                'recurring_price_lookup_key' => 'tier_pro_monthly',
                'setup_price_lookup_key' => 'tier_pro_setup',
            ],
        ],
        'addons' => [
            'referrals' => [
                'product_lookup_key' => 'addon_referrals',
                'recurring_price_lookup_key' => 'addon_referrals_monthly',
            ],
            'sms' => [
                'product_lookup_key' => 'addon_sms',
                'recurring_price_lookup_key' => 'addon_sms_monthly',
            ],
            'messaging' => [
                'product_lookup_key' => 'addon_messaging',
                'recurring_price_lookup_key' => 'addon_messaging_monthly',
            ],
            'additional_channels' => [
                'product_lookup_key' => 'addon_additional_channels',
                'recurring_price_lookup_key' => 'addon_additional_channels_monthly',
            ],
            'bulk_email_marketing' => [
                'product_lookup_key' => 'addon_bulk_email_marketing',
                'recurring_price_lookup_key' => 'addon_bulk_email_marketing_monthly',
            ],
            'future_niche_modules' => [
                'product_lookup_key' => 'addon_future_niche_modules',
                'recurring_price_lookup_key' => 'addon_future_niche_modules_monthly',
            ],
        ],
        'setup_packages' => [
            'guided_launch' => [
                'price_lookup_key' => 'setup_guided_launch',
            ],
            'accelerated_migration' => [
                'price_lookup_key' => 'setup_accelerated_migration',
            ],
        ],
        'support_tiers' => [
            'priority_support' => [
                'product_lookup_key' => 'support_priority',
                'recurring_price_lookup_key' => 'support_priority_monthly',
            ],
        ],
        'usage_metrics' => [
            'contact_count' => [
                'meter_lookup_key' => 'usage_contacts',
                'billing_mode' => 'track_only',
            ],
            'sms_usage' => [
                'meter_lookup_key' => 'usage_sms',
                'billing_mode' => 'track_only',
            ],
            'email_usage' => [
                'meter_lookup_key' => 'usage_email',
                'billing_mode' => 'track_only',
            ],
            'rewards_issued' => [
                'meter_lookup_key' => 'usage_rewards_issued',
                'billing_mode' => 'track_only',
            ],
            'reward_reminder_sends' => [
                'meter_lookup_key' => 'usage_reward_reminders',
                'billing_mode' => 'track_only',
            ],
        ],
        'store_channels' => [
            'starter_included' => 1,
            'additional_channels_addon_key' => 'additional_channels',
            'additional_channels_lookup_key' => 'addon_additional_channels_monthly',
        ],
        'tenant_override_fields' => [
            'plan_pricing_overrides',
            'addon_pricing_overrides',
            'included_usage_overrides',
            'billing_mapping',
        ],
    ],
];
