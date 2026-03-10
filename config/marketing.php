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

    'sms' => [
        'enabled' => (bool) env('MARKETING_SMS_ENABLED', false),
        'dry_run' => (bool) env('MARKETING_SMS_DRY_RUN', false),
        'default_country' => strtoupper((string) env('MARKETING_SMS_DEFAULT_COUNTRY', 'US')),
        'send_window_fallback' => [
            'start' => (string) env('MARKETING_SMS_FALLBACK_SEND_WINDOW_START', '09:00'),
            'end' => (string) env('MARKETING_SMS_FALLBACK_SEND_WINDOW_END', '18:00'),
            'timezone' => (string) env('MARKETING_SMS_FALLBACK_SEND_WINDOW_TIMEZONE', 'America/New_York'),
        ],
        'quiet_hours_fallback' => [
            'start' => (string) env('MARKETING_SMS_FALLBACK_QUIET_HOURS_START', '20:00'),
            'end' => (string) env('MARKETING_SMS_FALLBACK_QUIET_HOURS_END', '09:00'),
            'timezone' => (string) env('MARKETING_SMS_FALLBACK_QUIET_HOURS_TIMEZONE', 'America/New_York'),
        ],
    ],

    'twilio' => [
        'enabled' => (bool) env('MARKETING_TWILIO_ENABLED', false),
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
        'from_number' => env('TWILIO_FROM_NUMBER'),
        'status_callback_url' => env('TWILIO_STATUS_CALLBACK_URL'),
        'verify_signature' => (bool) env('MARKETING_TWILIO_VERIFY_SIGNATURE', false),
    ],
];
