<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Access Context
    |--------------------------------------------------------------------------
    |
    | This foundation intentionally models capability access without coupling
    | to billing providers yet. Billing adapters can map into these plan/add-on
    | keys later without changing module checks across controllers/views.
    |
    */
    'default_plan' => env('TENANT_ENTITLEMENTS_DEFAULT_PLAN', 'shopify_proof_of_concept'),
    'default_operating_mode' => env('TENANT_ENTITLEMENTS_DEFAULT_MODE', 'shopify'),

    /*
    |--------------------------------------------------------------------------
    | Module Catalog
    |--------------------------------------------------------------------------
    |
    | "coming_soon" means the module can appear in navigation or placeholders
    | while still reporting a non-active UI state.
    |
    */
    'modules' => [
        'dashboard' => [
            'label' => 'Overview',
            'classification' => 'shopify-only',
            'default_setup_status' => 'configured',
            'coming_soon' => false,
            'supports_upgrade_prompt' => false,
        ],
        'customers' => [
            'label' => 'Customers',
            'classification' => 'shared-core',
            'default_setup_status' => 'configured',
            'coming_soon' => false,
            'supports_upgrade_prompt' => true,
        ],
        'activity' => [
            'label' => 'Activity',
            'classification' => 'shared-core',
            'default_setup_status' => 'not_started',
            'coming_soon' => true,
            'supports_upgrade_prompt' => false,
        ],
        'questions' => [
            'label' => 'Questions',
            'classification' => 'internal-admin',
            'default_setup_status' => 'not_started',
            'coming_soon' => true,
            'supports_upgrade_prompt' => false,
        ],
        'rewards' => [
            'label' => 'Rewards',
            'classification' => 'shared-core',
            'default_setup_status' => 'configured',
            'coming_soon' => false,
            'supports_upgrade_prompt' => true,
        ],
        'birthdays' => [
            'label' => 'Birthdays / Lifecycle',
            'classification' => 'shared-core',
            'default_setup_status' => 'configured',
            'coming_soon' => false,
            'supports_upgrade_prompt' => true,
        ],
        'reviews' => [
            'label' => 'Reviews',
            'classification' => 'shared-core',
            'default_setup_status' => 'configured',
            'coming_soon' => false,
            'supports_upgrade_prompt' => true,
        ],
        'wishlist' => [
            'label' => 'Wishlist',
            'classification' => 'shared-core',
            'default_setup_status' => 'configured',
            'coming_soon' => false,
            'supports_upgrade_prompt' => true,
        ],
        'referrals' => [
            'label' => 'Referrals',
            'classification' => 'add-on',
            'default_setup_status' => 'not_started',
            'coming_soon' => true,
            'supports_upgrade_prompt' => false,
        ],
        'vip' => [
            'label' => 'VIP',
            'classification' => 'add-on',
            'default_setup_status' => 'not_started',
            'coming_soon' => true,
            'supports_upgrade_prompt' => false,
        ],
        'notifications' => [
            'label' => 'Notifications',
            'classification' => 'shared-core',
            'default_setup_status' => 'not_started',
            'coming_soon' => true,
            'supports_upgrade_prompt' => false,
        ],
        'campaigns' => [
            'label' => 'Campaigns',
            'classification' => 'shared-core',
            'default_setup_status' => 'not_started',
            'coming_soon' => false,
            'supports_upgrade_prompt' => true,
        ],
        'reporting' => [
            'label' => 'Reporting',
            'classification' => 'shared-core',
            'default_setup_status' => 'configured',
            'coming_soon' => false,
            'supports_upgrade_prompt' => true,
        ],
        'integrations' => [
            'label' => 'Integrations',
            'classification' => 'integration-layer',
            'default_setup_status' => 'not_started',
            'coming_soon' => false,
            'supports_upgrade_prompt' => true,
        ],
        'uploads' => [
            'label' => 'Uploads / Imports',
            'classification' => 'shared-core',
            'default_setup_status' => 'not_started',
            'coming_soon' => false,
            'supports_upgrade_prompt' => true,
        ],
        'onboarding' => [
            'label' => 'Onboarding',
            'classification' => 'shared-core',
            'default_setup_status' => 'not_started',
            'coming_soon' => false,
            'supports_upgrade_prompt' => false,
        ],
        'settings' => [
            'label' => 'Settings',
            'classification' => 'shared-core',
            'default_setup_status' => 'configured',
            'coming_soon' => false,
            'supports_upgrade_prompt' => false,
        ],
        'shopify' => [
            'label' => 'Shopify',
            'classification' => 'shopify-only',
            'default_setup_status' => 'configured',
            'coming_soon' => false,
            'supports_upgrade_prompt' => true,
        ],
        'quickbooks' => [
            'label' => 'QuickBooks',
            'classification' => 'integration-layer',
            'default_setup_status' => 'not_started',
            'coming_soon' => true,
            'supports_upgrade_prompt' => true,
        ],
        'wix' => [
            'label' => 'Wix',
            'classification' => 'integration-layer',
            'default_setup_status' => 'not_started',
            'coming_soon' => true,
            'supports_upgrade_prompt' => true,
        ],
        'square' => [
            'label' => 'Square',
            'classification' => 'integration-layer',
            'default_setup_status' => 'not_started',
            'coming_soon' => false,
            'supports_upgrade_prompt' => true,
        ],
        'email' => [
            'label' => 'Email',
            'classification' => 'integration-layer',
            'default_setup_status' => 'configured',
            'coming_soon' => false,
            'supports_upgrade_prompt' => true,
        ],
        'sms' => [
            'label' => 'SMS',
            'classification' => 'integration-layer',
            'default_setup_status' => 'configured',
            'coming_soon' => false,
            'supports_upgrade_prompt' => true,
        ],
        'mobile_connection' => [
            'label' => 'Mobile Connection',
            'classification' => 'integration-layer',
            'default_setup_status' => 'not_started',
            'coming_soon' => true,
            'supports_upgrade_prompt' => true,
        ],
        'ai' => [
            'label' => 'AI / Intelligence',
            'classification' => 'add-on',
            'default_setup_status' => 'not_started',
            'coming_soon' => true,
            'supports_upgrade_prompt' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Plan Grants
    |--------------------------------------------------------------------------
    */
    'plans' => [
        'shopify_proof_of_concept' => [
            'label' => 'Shopify Proof Of Concept',
            'track' => 'shopify',
            'includes' => [
                'dashboard',
                'customers',
                'activity',
                'questions',
                'rewards',
                'birthdays',
                'reviews',
                'wishlist',
                'referrals',
                'vip',
                'notifications',
                'campaigns',
                'reporting',
                'integrations',
                'uploads',
                'onboarding',
                'settings',
                'shopify',
                'email',
                'sms',
                'square',
            ],
        ],
        'shopify_growth' => [
            'label' => 'Shopify Growth',
            'track' => 'shopify',
            'includes' => [
                'dashboard',
                'customers',
                'activity',
                'questions',
                'rewards',
                'birthdays',
                'reviews',
                'wishlist',
                'campaigns',
                'reporting',
                'integrations',
                'uploads',
                'onboarding',
                'settings',
                'shopify',
                'email',
                'sms',
                'square',
            ],
        ],
        'direct_starter' => [
            'label' => 'Direct Starter',
            'track' => 'broader-business-systems',
            'includes' => [
                'customers',
                'rewards',
                'birthdays',
                'reviews',
                'wishlist',
                'campaigns',
                'reporting',
                'integrations',
                'uploads',
                'onboarding',
                'settings',
                'email',
                'sms',
                'square',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Add-On Grants
    |--------------------------------------------------------------------------
    */
    'addons' => [
        'advanced_reporting' => [
            'label' => 'Advanced Reporting',
            'includes' => ['reporting'],
        ],
        'referrals_pack' => [
            'label' => 'Referrals Pack',
            'includes' => ['referrals'],
        ],
        'vip_pack' => [
            'label' => 'VIP Pack',
            'includes' => ['vip', 'notifications'],
        ],
        'integrations_pack' => [
            'label' => 'Integrations Pack',
            'includes' => ['integrations', 'quickbooks', 'wix', 'mobile_connection'],
        ],
        'ai_brain' => [
            'label' => 'AI Brain',
            'includes' => ['ai'],
        ],
    ],

    'setup_statuses' => [
        'not_started',
        'in_progress',
        'configured',
        'blocked',
    ],
];

