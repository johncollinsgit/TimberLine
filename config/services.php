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
            'read_products,read_orders,read_all_orders,read_reports,read_analytics,read_customers,write_customers,read_discounts,write_discounts,read_pixels,write_pixels,read_customer_events'
        ),
        // Stores used by default when commands run without an explicit store argument.
        'active_store_keys' => env('SHOPIFY_ACTIVE_STORE_KEYS', 'retail'),
        // Launch-critical store keys. Optional stores remain visible but do not fail launch gates.
        'required_store_keys' => env('SHOPIFY_REQUIRED_STORE_KEYS', 'retail'),

        'stores' => [

            'retail' => [
                'shop'          => env('SHOPIFY_RETAIL_SHOP'),
                'access_token'  => env('SHOPIFY_RETAIL_ACCESS_TOKEN'),
                'client_id'     => env('SHOPIFY_RETAIL_CLIENT_ID'),
                'client_secret' => env('SHOPIFY_RETAIL_CLIENT_SECRET'),
                'timezone'      => env('SHOPIFY_RETAIL_TIMEZONE', env('SHOPIFY_REPORTING_TIMEZONE', 'America/New_York')),
            ],

            'wholesale' => [
                'shop'          => env('SHOPIFY_WHOLESALE_SHOP'),
                'access_token'  => env('SHOPIFY_WHOLESALE_ACCESS_TOKEN'),
                'client_id'     => env('SHOPIFY_WHOLESALE_CLIENT_ID'),
                'client_secret' => env('SHOPIFY_WHOLESALE_CLIENT_SECRET'),
                'timezone'      => env('SHOPIFY_WHOLESALE_TIMEZONE', env('SHOPIFY_REPORTING_TIMEZONE', 'America/New_York')),
            ],

        ],

    ],

];
