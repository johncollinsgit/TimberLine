<?php

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
    'public_tier_order' => [
        'starter',
        'growth',
        'pro',
    ],

    'legacy_plan_aliases' => [
        'shopify_proof_of_concept' => 'starter',
        'shopify_growth' => 'growth',
        'direct_starter' => 'starter',
    ],

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
    ],

    'plans' => [
        'starter' => [
            'name' => 'Starter',
            'track' => 'shopify',
            'is_public' => true,
            'is_highest_standard' => false,
            'position' => 10,
            'currency' => 'USD',
            'recurring_price_cents' => 14900,
            'setup_price_cents' => 4900,
            'included_usage' => [
                'store_channels' => 1,
                'contact_count' => 2000,
            ],
            'modules' => [
                'dashboard',
                'customers',
                'reviews',
                'lead_capture',
                'reporting',
                'integrations',
                'uploads',
                'onboarding',
                'settings',
                'shopify',
            ],
            'eligible_addons' => [
                'referrals',
                'sms',
                'additional_channels',
                'bulk_email_marketing',
            ],
        ],
        'growth' => [
            'name' => 'Growth',
            'track' => 'shopify',
            'is_public' => true,
            'is_highest_standard' => false,
            'position' => 20,
            'currency' => 'USD',
            'recurring_price_cents' => 24900,
            'setup_price_cents' => 7900,
            'included_usage' => [
                'store_channels' => 1,
                'contact_count' => 10000,
            ],
            'modules' => [
                'dashboard',
                'customers',
                'reviews',
                'lead_capture',
                'reporting',
                'integrations',
                'uploads',
                'onboarding',
                'settings',
                'shopify',
                'rewards',
                'birthdays',
                'campaigns',
                'email',
            ],
            'eligible_addons' => [
                'referrals',
                'sms',
                'additional_channels',
                'bulk_email_marketing',
            ],
        ],
        'pro' => [
            'name' => 'Pro',
            'track' => 'shopify',
            'is_public' => true,
            'is_highest_standard' => true,
            'position' => 30,
            'currency' => 'USD',
            'recurring_price_cents' => 39900,
            'setup_price_cents' => 9900,
            'included_usage' => [
                'store_channels' => 1,
                'contact_count' => 25000,
            ],
            'modules' => [
                'dashboard',
                'customers',
                'reviews',
                'lead_capture',
                'reporting',
                'diagnostics_advanced',
                'integrations',
                'uploads',
                'onboarding',
                'settings',
                'shopify',
                'rewards',
                'birthdays',
                'campaigns',
                'email',
                'wishlist',
            ],
            'eligible_addons' => [
                'referrals',
                'sms',
                'additional_channels',
                'bulk_email_marketing',
            ],
        ],
    ],

    'addons' => [
        'referrals' => [
            'name' => 'Referrals',
            'position' => 10,
            'currency' => 'USD',
            'recurring_price_cents' => 7900,
            'setup_price_cents' => 0,
            'modules' => ['referrals'],
        ],
        'sms' => [
            'name' => 'SMS',
            'position' => 20,
            'currency' => 'USD',
            'recurring_price_cents' => 9900,
            'setup_price_cents' => 0,
            'modules' => ['sms'],
        ],
        'additional_channels' => [
            'name' => 'Additional Stores/Channels',
            'position' => 30,
            'currency' => 'USD',
            'recurring_price_cents' => 5900,
            'setup_price_cents' => 0,
            'modules' => ['shopify'],
        ],
        'bulk_email_marketing' => [
            'name' => 'Bulk Marketing Email',
            'position' => 40,
            'currency' => 'USD',
            'recurring_price_cents' => 12900,
            'setup_price_cents' => 0,
            'modules' => ['email'],
        ],
        'future_niche_modules' => [
            'name' => 'Future Niche Modules',
            'position' => 90,
            'currency' => 'USD',
            'recurring_price_cents' => 0,
            'setup_price_cents' => 0,
            'modules' => [],
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
                'birthdays' => 'Birthday Rewards',
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
                'birthdays' => 'Milestone Outreach',
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
                'birthdays' => 'Seasonal Milestones',
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
                'birthdays' => 'Birthday Perks',
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
                'birthdays' => 'Lifecycle',
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
