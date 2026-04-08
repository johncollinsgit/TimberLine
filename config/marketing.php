<?php

$parseTwilioSenders = static function (): array {
    $raw = trim((string) env('MARKETING_TWILIO_SENDERS', ''));
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
};

$parseCsvStrings = static function (?string $value): array {
    return array_values(array_filter(array_map(
        static fn (string $entry): string => trim($entry),
        explode(',', (string) $value)
    )));
};

$parseCsvInts = static function (?string $value): array {
    return array_values(array_filter(array_map(
        static fn (string $entry): int => max(0, (int) trim($entry)),
        explode(',', (string) $value)
    ), static fn (int $entry): bool => $entry > 0));
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

    'message_analytics' => [
        'attribution_window_days' => (int) env('MARKETING_MESSAGE_ATTRIBUTION_WINDOW_DAYS', 7),
        'coupon_inference_enabled' => (bool) env('MARKETING_MESSAGE_COUPON_INFERENCE_ENABLED', true),
        'coupon_inference_require_url_match' => (bool) env('MARKETING_MESSAGE_COUPON_INFERENCE_REQUIRE_URL_MATCH', false),
        'url_signal_inference_enabled' => (bool) env('MARKETING_MESSAGE_URL_SIGNAL_INFERENCE_ENABLED', true),
        'sms_run_gap_minutes' => max(1, (int) env('MARKETING_MESSAGE_SMS_RUN_GAP_MINUTES', 5)),
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
        'smart_encoding' => (bool) env('MARKETING_TWILIO_SMART_ENCODING', true),
        'verify_signature' => (bool) env('MARKETING_TWILIO_VERIFY_SIGNATURE', false),
    ],

    'messaging' => [
        'queue' => (string) env('MARKETING_MESSAGING_QUEUE', (string) env('DB_QUEUE', (string) env('REDIS_QUEUE', 'default'))),
        'dispatch_batch_size' => max(25, (int) env('MARKETING_MESSAGING_DISPATCH_BATCH_SIZE', 250)),
        'dispatch_interval_seconds' => max(1, (int) env('MARKETING_MESSAGING_DISPATCH_INTERVAL_SECONDS', 2)),
        'default_max_attempts' => max(1, (int) env('MARKETING_MESSAGING_DEFAULT_MAX_ATTEMPTS', 4)),
        'cost_guardrails' => [
            'bulk_max_total_estimated_cost' => (float) env('MARKETING_MESSAGING_BULK_MAX_ESTIMATED_COST', 250),
            'sms_outbound_per_segment' => (float) env('MARKETING_MESSAGING_SMS_OUTBOUND_PER_SEGMENT', 0.0083),
            'sms_carrier_fee_per_segment' => (float) env('MARKETING_MESSAGING_SMS_CARRIER_FEE_PER_SEGMENT', 0.00395),
            'mms_outbound_per_message' => (float) env('MARKETING_MESSAGING_MMS_OUTBOUND_PER_MESSAGE', 0.022),
            'mms_carrier_fee_per_message' => (float) env('MARKETING_MESSAGING_MMS_CARRIER_FEE_PER_MESSAGE', 0.009),
            'mms_max_body_length' => max(1, (int) env('MARKETING_MESSAGING_MMS_MAX_BODY_LENGTH', 1600)),
            'prefer_mms_when_cheaper' => (bool) env('MARKETING_MESSAGING_PREFER_MMS_WHEN_CHEAPER', true),
        ],
        'sms' => [
            'max_dispatch_per_second' => max(1, (int) env('MARKETING_MESSAGING_SMS_MAX_DISPATCH_PER_SECOND', 18)),
            'retry_backoff_seconds' => $parseCsvInts((string) env('MARKETING_MESSAGING_SMS_RETRY_BACKOFF_SECONDS', '20,90,300')),
            'retryable_error_codes' => $parseCsvStrings((string) env(
                'MARKETING_MESSAGING_SMS_RETRYABLE_ERROR_CODES',
                '20429,21611,30001,30002,30003,30005,30006,30007,30008,429,500,502,503,504,exception,timeout'
            )),
        ],
        'email' => [
            'max_dispatch_per_second' => max(1, (int) env('MARKETING_MESSAGING_EMAIL_MAX_DISPATCH_PER_SECOND', 40)),
            'retry_backoff_seconds' => $parseCsvInts((string) env('MARKETING_MESSAGING_EMAIL_RETRY_BACKOFF_SECONDS', '30,120,420')),
        ],
        'link_shortening' => [
            'provider' => strtolower(trim((string) env('MARKETING_SMS_LINK_SHORTENER_PROVIDER', 'twilio'))),
            'twilio_native_enabled' => (bool) env('MARKETING_TWILIO_LINK_SHORTENING_ENABLED', false),
        ],
        'responses' => [
            'allow_start_resubscribe' => (bool) env('MARKETING_MESSAGING_RESPONSES_ALLOW_START_RESUBSCRIBE', false),
            'email_inbound_domain' => env('MARKETING_MESSAGING_RESPONSES_EMAIL_INBOUND_DOMAIN'),
            'sendgrid_inbound_token' => env('MARKETING_MESSAGING_RESPONSES_SENDGRID_INBOUND_TOKEN'),
        ],
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
        // Legacy beta rollout knob retained for compatibility only; storefront access is now GA and this value is ignored.
        'temporary_storefront_live_email_allowlist' => array_values(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            explode(',', (string) env('MARKETING_CANDLE_CASH_TEMP_LIVE_EMAIL_ALLOWLIST', ''))
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
