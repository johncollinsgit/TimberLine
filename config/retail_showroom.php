<?php

return [
    'enabled' => (bool) env('RETAIL_SHOWROOM_ENABLED', false),
    'owner_email' => strtolower(trim((string) env('RETAIL_SHOWROOM_OWNER_EMAIL', ''))),
    'sandbox_key' => env('RETAIL_SHOWROOM_EASYPOST_TEST_KEY'),
    // Enable only with a verified transactional mail transport, never array/log.
    'signup_confirmation_enabled' => (bool) env('RETAIL_SIGNUP_CONFIRMATION_ENABLED', false),
    'ai_enabled' => (bool) env('RETAIL_SHOWROOM_AI_ENABLED', false),
    'ai_model' => env('RETAIL_SHOWROOM_AI_MODEL', env('EVERBRANCH_AI_BRANCH_MODEL', 'gpt-4.1-mini')),
    'ai_requests_per_hour' => 30,
    // Explicit per-merchant synthetic origins. Never borrow a live tenant's connection.
    'sandbox_origins' => json_decode((string) env('RETAIL_SHOWROOM_TEST_ORIGINS', '{}'), true) ?: [],
];
