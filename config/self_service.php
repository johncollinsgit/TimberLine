<?php

return [
    // Account creation does not grant paid branches or publishing access.
    'registration_enabled' => filter_var(env('EVERBRANCH_SELF_SERVICE_REGISTRATION_ENABLED', true), FILTER_VALIDATE_BOOL),
    // Templates select a starting point only; purchase/provider gates retain authority.
    'templates' => [
        'generic' => [
            'label' => 'General business', 'description' => 'Customers & files',
            'image' => 'images/public-site/guide-studios.webp', 'blueprint_template' => 'generic',
            'branches' => ['customers', 'documents', 'reporting', 'managed_website'],
        ],
        'candle_maker' => [
            'label' => 'Makers & retail', 'description' => 'Products & customers',
            'image' => 'images/public-site/guide-retail.webp', 'blueprint_template' => 'candle_maker',
            'branches' => ['customers', 'rewards', 'documents', 'reporting', 'managed_website'],
        ],
        'landscaping' => [
            'label' => 'Landscaping', 'description' => 'Jobs & crews',
            'image' => 'images/website-themes/outdoor-elements-hero.png', 'blueprint_template' => 'landscaping',
            'branches' => ['customers', 'field_service', 'documents', 'managed_website'],
        ],
        'electrician' => [
            'label' => 'Electrical', 'description' => 'Jobs & billing',
            'image' => 'images/public-site/everbranch-hvac-electrical-field.jpg', 'blueprint_template' => 'electrician',
            'branches' => ['customers', 'field_service', 'everbranch_billing', 'documents', 'managed_website'],
        ],
        'law' => [
            'label' => 'Professional practice', 'description' => 'Clients & files',
            'image' => 'images/public-site/guide-projects.webp', 'blueprint_template' => 'law',
            'branches' => ['customers', 'lead_capture', 'documents', 'managed_website'],
        ],
        'apparel' => [
            'label' => 'Retail & apparel', 'description' => 'Products & customers',
            'image' => 'images/public-site/everbranch-industry-retail.jpg', 'blueprint_template' => 'apparel',
            'branches' => ['customers', 'rewards', 'wishlist', 'reporting', 'managed_website'],
        ],
        'custom' => [
            'label' => 'Start simple', 'description' => 'Customers & website',
            'image' => 'images/public-site/guide-studios.webp', 'blueprint_template' => 'custom',
            'branches' => ['customers', 'managed_website'],
        ],
    ],
];
