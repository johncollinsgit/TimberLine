<?php

return [
    // Readiness work is safe while customer-facing providers remain unchanged.
    // This independent release gate stays false until an approved cutover.
    'activation_enabled' => (bool) env('REPLACEMENT_ACTIVATION_ENABLED', false),
    'confirmed_annual_savings' => 1475.28,
    'allowed_activation_shopify_admin_ids' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('REPLACEMENT_ACTIVATION_SHOPIFY_ADMIN_IDS', ''))
    ))),
    'allowed_activation_admin_emails' => array_values(array_filter(array_map(
        static fn (string $value): string => strtolower(trim($value)),
        explode(',', (string) env('REPLACEMENT_ACTIVATION_ADMIN_EMAILS', ''))
    ))),

    'modules' => [
        'storeify_forms' => [
            'label' => 'Retail forms',
            'source_app' => 'S: Contact Form Builder (Storeify)',
            'replacement_name' => 'Everbranch Forms',
            'store_roles' => ['retail', 'mixed'],
            'activation_mode' => 'single_click',
            'expected_records' => 338,
            'savings_per_year' => 59.88,
            'required_checks' => ['source_snapshot', 'data_reconciliation', 'settings_reconciliation', 'admin_workflows', 'customer_preview', 'notifications', 'spam_controls', 'accessibility', 'performance', 'rollback', 'theme_candidate', 'site_integrity'],
        ],
        'omnium_locator' => [
            'label' => 'Store locator',
            'source_app' => 'Omnium Maps',
            'replacement_name' => 'Everbranch Stockist Locator',
            'store_roles' => ['retail', 'mixed'],
            'activation_mode' => 'single_click',
            'expected_records' => 44,
            'savings_per_year' => 0.0,
            'required_checks' => ['source_snapshot', 'data_reconciliation', 'settings_reconciliation', 'admin_workflows', 'customer_preview', 'map_search', 'directions', 'accessibility', 'performance', 'rollback', 'theme_candidate', 'site_integrity'],
        ],
        'shop_calendar' => [
            'label' => 'Public calendar',
            'source_app' => 'The Shop Calendar',
            'replacement_name' => 'Everbranch Public Events',
            'store_roles' => ['retail', 'mixed'],
            'activation_mode' => 'single_click',
            'savings_per_year' => 60.0,
            'required_checks' => ['source_snapshot', 'data_reconciliation', 'settings_reconciliation', 'public_publish_separation', 'admin_workflows', 'customer_preview', 'timezone_recurrence', 'accessibility', 'performance', 'rollback', 'theme_candidate', 'site_integrity'],
        ],
        'tnc_consent' => [
            'label' => 'Commerce consent',
            'source_app' => 'TnC',
            'replacement_name' => 'Everbranch Commerce Consent',
            'store_roles' => ['retail', 'mixed'],
            'activation_mode' => 'single_click',
            'savings_per_year' => 35.88,
            'required_checks' => ['source_snapshot', 'data_reconciliation', 'settings_reconciliation', 'versioned_terms', 'admin_workflows', 'customer_preview', 'server_enforcement', 'accelerated_checkout', 'subscriptions', 'accessibility', 'rollback', 'theme_candidate', 'site_integrity'],
        ],
        'shipping' => [
            'label' => 'Shipping',
            'source_app' => 'Existing shipping stack',
            'replacement_name' => 'Everbranch Shipping Operations',
            'store_roles' => ['retail', 'mixed', 'wholesale'],
            'activation_mode' => 'single_click',
            'savings_per_year' => 228.0,
            'required_checks' => ['source_snapshot', 'data_reconciliation', 'settings_reconciliation', 'carrier_credentials', 'admin_workflows', 'shadow_rates', 'labels', 'tracking', 'open_shipments', 'duplicate_rate_prevention', 'rollback', 'site_integrity'],
        ],
        'recharge' => [
            'label' => 'Subscriptions',
            'source_app' => 'Recharge',
            'replacement_name' => 'Everbranch Subscriptions',
            'store_roles' => ['retail', 'mixed'],
            'activation_mode' => 'controlled_pilot',
            'savings_per_year' => 0.0,
            'required_checks' => ['source_snapshot', 'data_reconciliation', 'settings_reconciliation', 'payment_method_migration', 'webhook_mirror', 'customer_portal', 'admin_workflows', 'billing_engine', 'dunning', 'notifications', 'ledger_reconciliation', 'duplicate_charge_prevention', 'rollback', 'pilot_cohort', 'site_integrity'],
        ],
        'product_options' => [
            'label' => 'Product options',
            'source_app' => 'Infinite Options',
            'replacement_name' => 'Everbranch Product Options',
            'store_roles' => ['retail', 'mixed'],
            'activation_mode' => 'already_live',
            'savings_per_year' => 0.0,
            'required_checks' => ['data_reconciliation', 'settings_reconciliation', 'admin_workflows', 'customer_flow', 'server_enforcement', 'rollback', 'site_integrity'],
        ],
        'birthday_rewards' => [
            'label' => 'Birthday and rewards',
            'source_app' => 'Happy Birthday / legacy rewards',
            'replacement_name' => 'Everbranch Birthday and Rewards',
            'store_roles' => ['retail', 'mixed'],
            'activation_mode' => 'already_live',
            'savings_per_year' => 0.0,
            'required_checks' => ['data_reconciliation', 'settings_reconciliation', 'admin_workflows', 'suppression', 'issuance', 'redemption', 'duplicate_prevention', 'rollback', 'site_integrity'],
        ],
        'wholesale_gateway' => [
            'label' => 'Retail wholesale gateway',
            'source_app' => 'Legacy wholesale storefront apps',
            'replacement_name' => 'Native Wholesale Gateway',
            'store_roles' => ['retail', 'mixed'],
            'activation_mode' => 'already_live',
            'savings_per_year' => 0.0,
            'required_checks' => ['customer_flow', 'route_scope', 'site_integrity', 'rollback'],
        ],
        'wholesale_backstage' => [
            'label' => 'Wholesale Backstage',
            'source_app' => 'Legacy wholesale operations',
            'replacement_name' => 'Modern Forestry Backstage',
            'store_roles' => ['wholesale'],
            'activation_mode' => 'already_live',
            'savings_per_year' => 0.0,
            'required_checks' => ['data_reconciliation', 'admin_workflows', 'store_isolation', 'permissions', 'rollback', 'site_integrity'],
        ],
    ],
];
