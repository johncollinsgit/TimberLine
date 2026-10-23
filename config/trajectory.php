<?php

return [
    'enabled' => (bool) env('TRAJECTORY_ENABLED', false),
    'gerald_enabled' => (bool) env('TRAJECTORY_GERALD_ENABLED', true),
    'audio_rates' => ['version' => 'openai-gerald-audio-2026-10-22', 'valid_until' => '2026-11-20T00:00:00Z', 'transcription_micros_per_minute' => 4500, 'speech_text_input_per_million' => 600000, 'speech_cached_text_input_per_million' => 60000, 'speech_text_output_per_million' => 2400000, 'speech_audio_output_per_million' => 20000000],
    'sms_enabled' => (bool) env('TRAJECTORY_SMS_ENABLED', false),
    'checkout_enabled' => false,
    'plans' => ['personal', 'business', 'both'],
    'plaid' => [
        'environment' => env('PLAID_ENVIRONMENT', 'sandbox'),
        'client_id' => env('PLAID_CLIENT_ID'),
        'secret' => env('PLAID_SECRET'),
        'webhook_url' => env('PLAID_WEBHOOK_URL'),
        'redirect_uri' => env('PLAID_REDIRECT_URI'),
        // New sign-ins remain a named-space pilot until real bank/app returns pass.
        // Existing feed sync and already-issued session completion stay available.
        'link_space_ids' => array_values(array_filter(array_map(
            static fn (string $id): int|false => filter_var(trim($id), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
            explode(',', (string) env('PLAID_LINK_SPACE_IDS', '')),
        ))),
    ],
    'goldapi_key' => env('GOLDAPI_KEY'),
    'categories' => ['giving', 'travel', 'taxes', 'housing', 'utilities', 'groceries', 'transport', 'health', 'insurance', 'education', 'dining', 'delivery_fees', 'entertainment', 'shopping', 'alcohol', 'tobacco', 'gambling', 'subscriptions', 'materials', 'payroll', 'fees', 'interest', 'income', 'uncategorized'],
    'discretionary_defaults' => ['gambling', 'tobacco', 'alcohol', 'delivery_fees', 'entertainment', 'shopping'],
];
