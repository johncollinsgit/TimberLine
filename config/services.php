<?php

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
        'redirect' => env('GOOGLE_REDIRECT_URI'),
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

        'scopes' => env(
            'SHOPIFY_SCOPES',
            'read_orders,read_products,read_customers'
        ),

        'stores' => [

            'retail' => [
                'shop'          => env('SHOPIFY_RETAIL_SHOP'),
                'access_token'  => env('SHOPIFY_RETAIL_ACCESS_TOKEN'),
                'client_id'     => env('SHOPIFY_RETAIL_CLIENT_ID'),
                'client_secret' => env('SHOPIFY_RETAIL_CLIENT_SECRET'),
            ],

            'wholesale' => [
                'shop'          => env('SHOPIFY_WHOLESALE_SHOP'),
                'access_token'  => env('SHOPIFY_WHOLESALE_ACCESS_TOKEN'),
                'client_id'     => env('SHOPIFY_WHOLESALE_CLIENT_ID'),
                'client_secret' => env('SHOPIFY_WHOLESALE_CLIENT_SECRET'),
            ],

        ],

    ],

];
