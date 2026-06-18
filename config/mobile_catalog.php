<?php

return [
    'fake_enabled' => (bool) env('MOBILE_CATALOG_FAKE_ENABLED', false),
    'storefront_base_url' => env(
        'MOBILE_CATALOG_STOREFRONT_BASE_URL',
        env('MARKETING_CANDLE_CASH_STOREFRONT_BASE_URL', 'https://theforestrystudio.com')
    ),
    'catalog' => [
        'default_limit' => (int) env('MOBILE_CATALOG_DEFAULT_LIMIT', 24),
        'max_limit' => (int) env('MOBILE_CATALOG_MAX_LIMIT', 250),
        'page_size' => (int) env('MOBILE_CATALOG_PAGE_SIZE', 50),
        'featured_limit' => (int) env('MOBILE_CATALOG_FEATURED_LIMIT', 6),
    ],
    'candle_club' => [
        'product_handle' => env(
            'MOBILE_CATALOG_CANDLE_CLUB_PRODUCT_HANDLE',
            'modern-forestry-candle-club-16oz-subscription-with-gifts'
        ),
        'join_path' => env(
            'MOBILE_CATALOG_CANDLE_CLUB_JOIN_PATH',
            '/products/modern-forestry-candle-club-16oz-subscription-with-gifts?selling_plan=11300438275'
        ),
        'collection_handle' => env('MOBILE_CATALOG_CANDLE_CLUB_COLLECTION_HANDLE', 'candle-club'),
    ],
];
