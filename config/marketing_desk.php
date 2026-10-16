<?php

return [
    'enabled' => (bool) env('MARKETING_DESK_ENABLED', false),
    'tenant_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('MARKETING_DESK_TENANT_IDS', ''))))),
    'delivery_enabled' => (bool) env('MARKETING_DESK_DELIVERY_ENABLED', false),
    'ai_replies_enabled' => (bool) env('MARKETING_DESK_AI_REPLIES_ENABLED', false),
    'monthly_ai_cap_cents' => 5000,
    'owner_model' => env('MARKETING_DESK_OWNER_MODEL', 'gpt-6.1-sol'),
    'reply_model' => env('MARKETING_DESK_REPLY_MODEL', 'gpt-6-luna'),
    // Rates must be deliberately verified before provider dispatch. USD micros per million tokens.
    'input_micros_per_million' => (int) env('MARKETING_DESK_INPUT_MICROS_PER_MILLION', 0),
    'output_micros_per_million' => (int) env('MARKETING_DESK_OUTPUT_MICROS_PER_MILLION', 0),
    'media_hosts' => array_values(array_filter(explode(',', (string) env('MARKETING_DESK_MEDIA_HOSTS', '')))),
    'timezone' => env('MARKETING_DESK_TIMEZONE', 'UTC'),
    'meta' => [
        'execution_enabled' => (bool) env('MARKETING_META_EXECUTION_ENABLED', false),
        'client_id' => env('MARKETING_META_CLIENT_ID', ''),
        'client_secret' => env('MARKETING_META_CLIENT_SECRET', ''),
        'verify_token' => env('MARKETING_META_VERIFY_TOKEN', ''),
        'version' => env('MARKETING_META_VERSION', 'v24.0'),
        'redirect_uri' => env('MARKETING_META_REDIRECT_URI', ''),
    ],
];
