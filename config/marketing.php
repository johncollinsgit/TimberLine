<?php

$parseTwilioSenders = static function (): array {
    $raw = trim((string) env('MARKETING_TWILIO_SENDERS', ''));
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
};

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

    'growave' => [
        'enabled' => (bool) env('MARKETING_GROWAVE_ENABLED', false),
        'base_url' => env('MARKETING_GROWAVE_BASE_URL', 'https://api.growave.io'),
        'client_id' => env('MARKETING_GROWAVE_CLIENT_ID'),
        'client_secret' => env('MARKETING_GROWAVE_CLIENT_SECRET'),
        'scope' => env('MARKETING_GROWAVE_SCOPE', 'read_customer read_review read_reward'),
        'shop' => env('MARKETING_GROWAVE_SHOP'),
        'timeout_seconds' => (int) env('MARKETING_GROWAVE_TIMEOUT_SECONDS', 20),
        'retry_attempts' => (int) env('MARKETING_GROWAVE_RETRY_ATTEMPTS', 3),
        'request_min_interval_ms' => (int) env('MARKETING_GROWAVE_REQUEST_MIN_INTERVAL_MS', 300),
        'request_jitter_ms' => (int) env('MARKETING_GROWAVE_REQUEST_JITTER_MS', 100),
        'backoff_base_ms' => (int) env('MARKETING_GROWAVE_BACKOFF_BASE_MS', 1000),
        'backoff_max_ms' => (int) env('MARKETING_GROWAVE_BACKOFF_MAX_MS', 15000),
        'candidate_delay_ms' => (int) env('MARKETING_GROWAVE_CANDIDATE_DELAY_MS', 50),
        'page_delay_ms' => (int) env('MARKETING_GROWAVE_PAGE_DELAY_MS', 150),
    ],

    'imports' => [
        'store_row_payloads' => (bool) env('MARKETING_IMPORTS_STORE_ROW_PAYLOADS', true),
    ],

    'sms' => [
        'enabled' => (bool) env('MARKETING_SMS_ENABLED', false),
        'dry_run' => (bool) env('MARKETING_SMS_DRY_RUN', false),
        'default_country' => strtoupper((string) env('MARKETING_SMS_DEFAULT_COUNTRY', 'US')),
        'test_number' => env('MARKETING_SMS_TEST_NUMBER'),
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
        'reply_to_email' => env('MARKETING_EMAIL_REPLY_TO_EMAIL'),
        'smoke_test_recipient_email' => env('MARKETING_EMAIL_SMOKE_TEST_RECIPIENT'),
        'candle_cash_reminder' => [
            'cooldown_days' => (int) env('MARKETING_EMAIL_CANDLE_CASH_REMINDER_COOLDOWN_DAYS', 14),
            'max_send_limit' => (int) env('MARKETING_EMAIL_CANDLE_CASH_REMINDER_MAX_SEND_LIMIT', 200),
        ],
    ],

    'twilio' => [
        'enabled' => (bool) env('MARKETING_TWILIO_ENABLED', false),
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
        'from_number' => env('TWILIO_FROM_NUMBER'),
        'default_sender_key' => env('MARKETING_TWILIO_DEFAULT_SENDER'),
        'senders' => $parseTwilioSenders(),
        'status_callback_url' => env('TWILIO_STATUS_CALLBACK_URL'),
        'verify_signature' => (bool) env('MARKETING_TWILIO_VERIFY_SIGNATURE', false),
    ],

    'shopify' => [
        'contract_version' => env('MARKETING_SHOPIFY_CONTRACT_VERSION', 'v1'),
        // Storefront verification should track the canonical retail Shopify app by default.
        'signing_secret' => env('MARKETING_SHOPIFY_SIGNING_SECRET', env('SHOPIFY_RETAIL_CLIENT_SECRET')),
        'signature_ttl_seconds' => (int) env('MARKETING_SHOPIFY_SIGNATURE_TTL_SECONDS', 300),
        'app_proxy_enabled' => (bool) env('MARKETING_SHOPIFY_APP_PROXY_ENABLED', true),
        'app_proxy_secret' => env('MARKETING_SHOPIFY_APP_PROXY_SECRET', env('SHOPIFY_RETAIL_CLIENT_SECRET')),
        'app_proxy_ttl_seconds' => (int) env('MARKETING_SHOPIFY_APP_PROXY_TTL_SECONDS', 900),
        'birthday' => [
            'namespace' => env('MARKETING_SHOPIFY_BIRTHDAY_NAMESPACE', 'forestry_marketing'),
            'write_growave_aliases' => (bool) env('MARKETING_SHOPIFY_BIRTHDAY_WRITE_GROWAVE_ALIASES', true),
        ],
    ],

    'public' => [
        'event_flows_enabled' => (bool) env('MARKETING_PUBLIC_EVENT_FLOWS_ENABLED', true),
    ],

    'candle_cash_consent_bonus' => [
        'sms' => (int) env('MARKETING_SMS_CONSENT_BONUS_CANDLE_CASH', env('MARKETING_SMS_CONSENT_BONUS_POINTS', 0)),
    ],

    'candle_cash' => [
        'code_expiry_days' => (int) env('MARKETING_CANDLE_CASH_CODE_EXPIRY_DAYS', 30),
        'storefront_base_url' => env('MARKETING_CANDLE_CASH_STOREFRONT_BASE_URL', 'https://theforestrystudio.com'),
        'legacy_points_per_candle_cash' => (int) env('MARKETING_CANDLE_CASH_LEGACY_POINTS_PER_CANDLE_CASH', env('MARKETING_CANDLE_CASH_POINTS_PER_DOLLAR', 1)),
        'redeem_increment_dollars' => (float) env('MARKETING_CANDLE_CASH_REDEEM_INCREMENT_DOLLARS', 10),
        'max_redeemable_per_order_dollars' => (float) env('MARKETING_CANDLE_CASH_MAX_REDEEMABLE_PER_ORDER_DOLLARS', 10),
        'max_open_codes' => (int) env('MARKETING_CANDLE_CASH_MAX_OPEN_CODES', 1),
        'storefront_reward_type' => env('MARKETING_CANDLE_CASH_STOREFRONT_REWARD_TYPE', 'coupon'),
        'storefront_reward_value' => env('MARKETING_CANDLE_CASH_STOREFRONT_REWARD_VALUE', '10USD'),
        'temporary_storefront_live_email_allowlist' => array_values(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            explode(',', (string) env('MARKETING_CANDLE_CASH_TEMP_LIVE_EMAIL_ALLOWLIST', 'sarahcollins0816@gmail.com,info@theforestrystudio.com,randbhendrich@yahoo.com,pjordan.mail@gmail.com,marah.jordan@csuglobal.edu'))
        ))),
        'password_protection' => [
            'enabled' => (bool) env('MARKETING_CANDLE_CASH_PASSWORD_ENABLED', true),
            'password' => env('MARKETING_CANDLE_CASH_PASSWORD', 'johnnycash'),
            'unlock_ttl_minutes' => (int) env('MARKETING_CANDLE_CASH_PASSWORD_TTL_MINUTES', 480),
        ],
        'lifecycle' => [
            'earned_not_used_days' => (int) env('MARKETING_CANDLE_CASH_LIFECYCLE_EARNED_NOT_USED_DAYS', 14),
            'reminder_cooldown_days' => (int) env('MARKETING_CANDLE_CASH_LIFECYCLE_REMINDER_COOLDOWN_DAYS', 14),
            'lapsed_purchaser_days' => (int) env('MARKETING_CANDLE_CASH_LIFECYCLE_LAPSED_PURCHASER_DAYS', 60),
            'default_channel' => env('MARKETING_CANDLE_CASH_LIFECYCLE_DEFAULT_CHANNEL', 'email'),
            'preview_limit' => (int) env('MARKETING_CANDLE_CASH_LIFECYCLE_PREVIEW_LIMIT', 200),
        ],
    ],

    'birthday_rewards' => [
        'enabled' => (bool) env('MARKETING_BIRTHDAY_REWARDS_ENABLED', true),
        'reward_type' => env('MARKETING_BIRTHDAY_REWARD_TYPE', 'candle_cash') === 'points'
            ? 'candle_cash'
            : env('MARKETING_BIRTHDAY_REWARD_TYPE', 'candle_cash'),
        'candle_cash_amount' => (int) env('MARKETING_BIRTHDAY_CANDLE_CASH_AMOUNT', env('MARKETING_BIRTHDAY_POINTS_AMOUNT', 50)),
        'discount_code_prefix' => env('MARKETING_BIRTHDAY_DISCOUNT_CODE_PREFIX', 'BDAY'),
        'free_shipping_code_prefix' => env('MARKETING_BIRTHDAY_FREE_SHIPPING_CODE_PREFIX', 'BDAYSHIP'),
        'claim_window_days_before' => (int) env('MARKETING_BIRTHDAY_CLAIM_WINDOW_DAYS_BEFORE', 0),
        'claim_window_days_after' => (int) env('MARKETING_BIRTHDAY_CLAIM_WINDOW_DAYS_AFTER', 14),
    ],

    'integration_health' => [
        'resolved_retention_days' => (int) env('MARKETING_INTEGRATION_HEALTH_RESOLVED_RETENTION_DAYS', 45),
    ],
];
