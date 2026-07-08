<?php

$normalizeHost = static function (mixed $value): ?string {
    $host = strtolower(trim((string) $value));
    if ($host === '') {
        return null;
    }

    $host = preg_replace('#^https?://#', '', $host);
    $host = explode('/', (string) $host)[0] ?? '';
    $host = explode(':', (string) $host)[0] ?? '';
    $host = trim((string) $host, '.');

    return $host !== '' ? $host : null;
};

$canonicalScheme = strtolower(trim((string) env('TENANCY_CANONICAL_SCHEME', '')));
if (! in_array($canonicalScheme, ['http', 'https'], true)) {
    $appScheme = strtolower(trim((string) parse_url((string) env('APP_URL', ''), PHP_URL_SCHEME)));
    $canonicalScheme = in_array($appScheme, ['http', 'https'], true) ? $appScheme : 'https';
}

$canonicalLandlordHost = $normalizeHost(env('TENANCY_CANONICAL_LANDLORD_HOST', ''));
if ($canonicalLandlordHost === null) {
    $canonicalLandlordHost = $normalizeHost(parse_url((string) env('APP_URL', ''), PHP_URL_HOST));
}

$defaultGoogleRedirect = $canonicalLandlordHost !== null
    ? $canonicalScheme.'://'.$canonicalLandlordHost.'/auth/google/callback'
    : rtrim((string) env('APP_URL', 'http://localhost'), '/').'/auth/google/callback';
$defaultGoogleGbpRedirect = $canonicalLandlordHost !== null
    ? $canonicalScheme.'://'.$canonicalLandlordHost.'/marketing/candle-cash/google-business/callback'
    : rtrim((string) env('APP_URL', 'http://localhost'), '/').'/marketing/candle-cash/google-business/callback';
$defaultGoogleCalendarRedirect = $canonicalLandlordHost !== null
    ? $canonicalScheme.'://'.$canonicalLandlordHost.'/marketing/providers-integrations/workflow-automations/google-calendar/callback'
    : rtrim((string) env('APP_URL', 'http://localhost'), '/').'/marketing/providers-integrations/workflow-automations/google-calendar/callback';
$defaultAsanaWorkflowRedirect = $canonicalLandlordHost !== null
    ? $canonicalScheme.'://'.$canonicalLandlordHost.'/marketing/providers-integrations/workflow-automations/asana/callback'
    : rtrim((string) env('APP_URL', 'http://localhost'), '/').'/marketing/providers-integrations/workflow-automations/asana/callback';

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', $defaultGoogleRedirect),
        'enabled' => env('GOOGLE_LOGIN_ENABLED', false),
        'auto_provision' => env('GOOGLE_LOGIN_AUTO_PROVISION', true),
        'allowed_domains' => array_values(array_filter(array_map(
            static fn (string $domain): string => strtolower(trim($domain)),
            explode(',', (string) env('GOOGLE_LOGIN_ALLOWED_DOMAINS', ''))
        ))),
    ],

    'google_calendar' => [
        'api_key' => env('GOOGLE_CALENDAR_API_KEY'),
        'asana_skylight_calendar_id' => env('ASANA_SKYLIGHT_CALENDAR_ID', 'e4790b1a07ff610489e40c5fb28d50f4f8b74dc2d4b24db2a9b13bef0df39541@group.calendar.google.com'),
        'oauth_client_id' => env('GOOGLE_CALENDAR_CLIENT_ID', env('GOOGLE_CLIENT_ID')),
        'oauth_client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET', env('GOOGLE_CLIENT_SECRET')),
        'oauth_refresh_token' => env('GOOGLE_CALENDAR_REFRESH_TOKEN'),
        'oauth_access_token' => env('GOOGLE_CALENDAR_ACCESS_TOKEN'),
        'redirect_uri' => env('GOOGLE_CALENDAR_REDIRECT_URI', $defaultGoogleCalendarRedirect),
        'oauth_state_cache_store' => env('GOOGLE_CALENDAR_OAUTH_STATE_CACHE_STORE', env('CACHE_STORE', 'file')),
        'oauth_scopes' => env('GOOGLE_CALENDAR_OAUTH_SCOPES', 'https://www.googleapis.com/auth/calendar'),
    ],

    'asana' => [
        'personal_access_token' => env('ASANA_PERSONAL_ACCESS_TOKEN'),
        'api_base' => env('ASANA_API_BASE', 'https://app.asana.com/api/1.0'),
        'oauth_client_id' => env('ASANA_OAUTH_CLIENT_ID'),
        'oauth_client_secret' => env('ASANA_OAUTH_CLIENT_SECRET'),
        'oauth_refresh_token' => env('ASANA_OAUTH_REFRESH_TOKEN'),
        'oauth_access_token' => env('ASANA_OAUTH_ACCESS_TOKEN'),
        'redirect_uri' => env('ASANA_OAUTH_REDIRECT_URI', $defaultAsanaWorkflowRedirect),
        'oauth_state_cache_store' => env('ASANA_OAUTH_STATE_CACHE_STORE', env('CACHE_STORE', 'file')),
        'oauth_scopes' => env('ASANA_OAUTH_SCOPES', 'projects:read,tasks:read,workspaces:read'),
    ],

    'google_gbp' => [
        'client_id' => env('GOOGLE_GBP_CLIENT_ID'),
        'client_secret' => env('GOOGLE_GBP_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_GBP_REDIRECT_URI', $defaultGoogleGbpRedirect),
        'enabled' => env('GOOGLE_GBP_ENABLED', false),
        'scopes' => env('GOOGLE_GBP_SCOPES', 'https://www.googleapis.com/auth/business.manage'),
        'oauth_state_cache_store' => env('GOOGLE_GBP_OAUTH_STATE_CACHE_STORE', env('CACHE_STORE', 'file')),
    ],

    'square' => [
        'access_token' => env('SQUARE_ACCESS_TOKEN'),
        'base_url' => env('SQUARE_BASE_URL', 'https://connect.squareup.com'),
        'environment' => env('SQUARE_ENVIRONMENT', 'production'),
        'location_id' => env('SQUARE_LOCATION_ID'),
    ],

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
        'from_number' => env('TWILIO_FROM_NUMBER'),
        'status_callback_url' => env('TWILIO_STATUS_CALLBACK_URL'),
    ],

    'sendgrid' => [
        'api_key' => env('SENDGRID_API_KEY'),
    ],

    'modern_forestry_apns' => [
        'enabled' => env('MODERN_FORESTRY_APNS_ENABLED', false),
        'team_id' => env('MODERN_FORESTRY_APNS_TEAM_ID'),
        'key_id' => env('MODERN_FORESTRY_APNS_KEY_ID'),
        'bundle_id' => env('MODERN_FORESTRY_IOS_BUNDLE_ID', 'com.theforestrystudio.modernforestry'),
        'environment' => env('MODERN_FORESTRY_APNS_ENVIRONMENT', 'production'),
        'auth_key' => env('MODERN_FORESTRY_APNS_AUTH_KEY'),
        'auth_key_base64' => env('MODERN_FORESTRY_APNS_AUTH_KEY_BASE64'),
        'auth_key_path' => env('MODERN_FORESTRY_APNS_AUTH_KEY_PATH'),
        'timeout' => (int) env('MODERN_FORESTRY_APNS_TIMEOUT', 10),
    ],

    'modern_forestry' => [
        'support_alert_phone' => env('MODERN_FORESTRY_SUPPORT_ALERT_PHONE', '+18646165468'),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'publishable_key' => env('STRIPE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'api_base' => env('STRIPE_API_BASE', 'https://api.stripe.com'),
        'timeout' => (int) env('STRIPE_API_TIMEOUT', 20),
    ],

    'braintree' => [
        'merchant_id' => env('BRAINTREE_MERCHANT_ID'),
        'public_key' => env('BRAINTREE_PUBLIC_KEY'),
        'private_key' => env('BRAINTREE_PRIVATE_KEY'),
        'environment' => env('BRAINTREE_ENVIRONMENT', 'sandbox'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shopify Configuration
    |--------------------------------------------------------------------------
    |
    | Used by Production-OS ingestion layer.
    | Store keys must match artisan command arguments:
    |
    | php artisan shopify:import-orders retail
    | php artisan shopify:import-orders wholesale
    |
    */

    'shopify' => [

        'api_version' => env('SHOPIFY_API_VERSION', '2026-01'),
        'allow_env_token_fallback' => (bool) env('SHOPIFY_ALLOW_ENV_TOKEN_FALLBACK', false),
        // Reporting window timezone used by embedded admin surfaces (Dashboard Lite, etc).
        // Defaults to the primary business timezone so "Today" matches merchant expectations.
        'reporting_timezone' => env('SHOPIFY_REPORTING_TIMEZONE', 'America/New_York'),

        'scopes' => env(
            'SHOPIFY_SCOPES',
            'read_products,write_products,read_orders,read_all_orders,read_reports,read_analytics,read_customers,write_customers,read_discounts,write_discounts,read_pixels,write_pixels,read_customer_events,read_own_subscription_contracts,write_own_subscription_contracts,read_customer_payment_methods,unauthenticated_read_checkouts,unauthenticated_read_collection_listings,unauthenticated_read_product_listings,unauthenticated_read_selling_plans,unauthenticated_write_checkouts'
        ),
        // Stores used by default when commands run without an explicit store argument.
        'active_store_keys' => env('SHOPIFY_ACTIVE_STORE_KEYS', 'retail'),
        // Launch-critical store keys. Optional stores remain visible but do not fail launch gates.
        'required_store_keys' => env('SHOPIFY_REQUIRED_STORE_KEYS', 'retail'),

        'stores' => [

            'retail' => [
                'shop'                   => env('SHOPIFY_RETAIL_SHOP'),
                'access_token'           => env('SHOPIFY_RETAIL_ACCESS_TOKEN'),
                'storefront_access_token' => env('SHOPIFY_RETAIL_STOREFRONT_ACCESS_TOKEN'),
                'client_id'              => env('SHOPIFY_RETAIL_CLIENT_ID'),
                'client_secret'          => env('SHOPIFY_RETAIL_CLIENT_SECRET'),
                'timezone'               => env('SHOPIFY_RETAIL_TIMEZONE', env('SHOPIFY_REPORTING_TIMEZONE', 'America/New_York')),
            ],

            'wholesale' => [
                'shop'          => env('SHOPIFY_WHOLESALE_SHOP'),
                'access_token'  => env('SHOPIFY_WHOLESALE_ACCESS_TOKEN'),
                'client_id'     => env('SHOPIFY_WHOLESALE_CLIENT_ID'),
                'client_secret' => env('SHOPIFY_WHOLESALE_CLIENT_SECRET'),
                'embedded_client_id' => env('SHOPIFY_WHOLESALE_EMBEDDED_CLIENT_ID'),
                'embedded_client_secret' => env('SHOPIFY_WHOLESALE_EMBEDDED_CLIENT_SECRET'),
                'timezone'      => env('SHOPIFY_WHOLESALE_TIMEZONE', env('SHOPIFY_REPORTING_TIMEZONE', 'America/New_York')),
            ],

        ],

        'customer_account' => [
            'client_id' => env('SHOPIFY_CUSTOMER_ACCOUNT_CLIENT_ID'),
            'client_secret' => env('SHOPIFY_CUSTOMER_ACCOUNT_CLIENT_SECRET'),
            'authorization_endpoint' => env('SHOPIFY_CUSTOMER_ACCOUNT_AUTHORIZATION_ENDPOINT'),
            'token_endpoint' => env('SHOPIFY_CUSTOMER_ACCOUNT_TOKEN_ENDPOINT'),
            'graphql_endpoint' => env('SHOPIFY_CUSTOMER_ACCOUNT_GRAPHQL_ENDPOINT'),
            'redirect_uri' => env('SHOPIFY_CUSTOMER_ACCOUNT_REDIRECT_URI', 'https://app.theeverbranch.com/api/mobile/v1/modern-forestry/auth/callback'),
            'callback_scheme' => env('SHOPIFY_CUSTOMER_ACCOUNT_CALLBACK_SCHEME', 'shop.20812479.modernforestry'),
            'scopes' => env('SHOPIFY_CUSTOMER_ACCOUNT_SCOPES', 'openid email customer-account-api:full'),
        ],

    ],

    'pexels' => [
        'key' => env('PEXELS_API_KEY'),
    ],

    'unsplash' => [
        'access_key' => env('UNSPLASH_ACCESS_KEY'),
    ],

    'pixabay' => [
        'key' => env('PIXABAY_API_KEY'),
    ],

    'stock_photos' => [
        'provider_order' => env('STOCK_PHOTO_PROVIDER_ORDER', 'pexels,unsplash,pixabay'),
    ],

    'modern_forestry_app_review' => [
        'email' => env('MF_APP_REVIEW_DEMO_EMAIL', 'app-review@theforestrystudio.com'),
        'password_hash' => env('MF_APP_REVIEW_DEMO_PASSWORD_HASH', '$2y$12$yB2JKPtEPuPeWIWnNLKhAe6ZZw.n133j8v6uGqo9tbnKI.M/inFjS'),
        'password' => env('MF_APP_REVIEW_DEMO_PASSWORD'),
    ],

];
