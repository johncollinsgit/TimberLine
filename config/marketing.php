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

    'email' => [
        'enabled' => (bool) env('MARKETING_EMAIL_ENABLED', false),
        'dry_run' => (bool) env('MARKETING_EMAIL_DRY_RUN', false),
        'from_email' => env('MARKETING_EMAIL_FROM_EMAIL'),
        'from_name' => env('MARKETING_EMAIL_FROM_NAME', 'TimberLine Marketing'),
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

    'shopify' => [
        'contract_version' => env('MARKETING_SHOPIFY_CONTRACT_VERSION', 'v1'),
        'widget_token' => env('MARKETING_SHOPIFY_WIDGET_TOKEN'),
        'allow_legacy_token' => (bool) env('MARKETING_SHOPIFY_ALLOW_LEGACY_TOKEN', false),
        'signing_secret' => env('MARKETING_SHOPIFY_SIGNING_SECRET'),
        'signature_ttl_seconds' => (int) env('MARKETING_SHOPIFY_SIGNATURE_TTL_SECONDS', 300),
        'app_proxy_enabled' => (bool) env('MARKETING_SHOPIFY_APP_PROXY_ENABLED', true),
        'app_proxy_secret' => env('MARKETING_SHOPIFY_APP_PROXY_SECRET'),
        'app_proxy_ttl_seconds' => (int) env('MARKETING_SHOPIFY_APP_PROXY_TTL_SECONDS', 900),
        'birthday' => [
            'namespace' => env('MARKETING_SHOPIFY_BIRTHDAY_NAMESPACE', 'forestry_marketing'),
            'write_growave_aliases' => (bool) env('MARKETING_SHOPIFY_BIRTHDAY_WRITE_GROWAVE_ALIASES', true),
        ],
    ],

    'public' => [
        'event_flows_enabled' => (bool) env('MARKETING_PUBLIC_EVENT_FLOWS_ENABLED', true),
    ],

    'consent_bonus_points' => [
        'sms' => (int) env('MARKETING_SMS_CONSENT_BONUS_POINTS', 0),
    ],

    'candle_cash' => [
        'code_expiry_days' => (int) env('MARKETING_CANDLE_CASH_CODE_EXPIRY_DAYS', 30),
    ],

    'birthday_rewards' => [
        'enabled' => (bool) env('MARKETING_BIRTHDAY_REWARDS_ENABLED', true),
        'reward_type' => env('MARKETING_BIRTHDAY_REWARD_TYPE', 'points'),
        'points_amount' => (int) env('MARKETING_BIRTHDAY_POINTS_AMOUNT', 50),
        'discount_code_prefix' => env('MARKETING_BIRTHDAY_DISCOUNT_CODE_PREFIX', 'BDAY'),
        'free_shipping_code_prefix' => env('MARKETING_BIRTHDAY_FREE_SHIPPING_CODE_PREFIX', 'BDAYSHIP'),
        'claim_window_days_before' => (int) env('MARKETING_BIRTHDAY_CLAIM_WINDOW_DAYS_BEFORE', 0),
        'claim_window_days_after' => (int) env('MARKETING_BIRTHDAY_CLAIM_WINDOW_DAYS_AFTER', 14),
    ],
];
