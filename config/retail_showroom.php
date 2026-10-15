<?php

return [
    'enabled' => (bool) env('RETAIL_SHOWROOM_ENABLED', false),
    'owner_email' => strtolower(trim((string) env('RETAIL_SHOWROOM_OWNER_EMAIL', ''))),
    'sandbox_key' => env('RETAIL_SHOWROOM_EASYPOST_TEST_KEY'),
    // Explicit per-merchant synthetic origins. Never borrow a live tenant's connection.
    'sandbox_origins' => json_decode((string) env('RETAIL_SHOWROOM_TEST_ORIGINS', '{}'), true) ?: [],
];
