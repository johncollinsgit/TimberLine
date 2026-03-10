<?php

return [
    'square' => [
        'enabled' => (bool) env('MARKETING_SQUARE_ENABLED', false),
        'base_url' => env('SQUARE_BASE_URL', 'https://connect.squareup.com'),
        'environment' => env('SQUARE_ENVIRONMENT', 'production'),
        'access_token' => env('SQUARE_ACCESS_TOKEN'),
        'location_id' => env('SQUARE_LOCATION_ID'),
        'location_ids' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env('SQUARE_LOCATION_IDS', ''))
        ))),
        'sync_customers_enabled' => (bool) env('MARKETING_SQUARE_SYNC_CUSTOMERS_ENABLED', true),
        'sync_orders_enabled' => (bool) env('MARKETING_SQUARE_SYNC_ORDERS_ENABLED', true),
        'sync_payments_enabled' => (bool) env('MARKETING_SQUARE_SYNC_PAYMENTS_ENABLED', true),
    ],

    'imports' => [
        'store_row_payloads' => (bool) env('MARKETING_IMPORTS_STORE_ROW_PAYLOADS', true),
    ],
];
