<?php

return [
    'connection_enabled' => (bool) env('WHOLESALE_GMAIL_CONNECTION_ENABLED', false),
    'test_enabled' => (bool) env('WHOLESALE_GMAIL_TEST_ENABLED', false),
    'send_enabled' => (bool) env('WHOLESALE_GMAIL_SEND_ENABLED', false),
    'client_id' => env('WHOLESALE_GMAIL_CLIENT_ID'),
    'client_secret' => env('WHOLESALE_GMAIL_CLIENT_SECRET'),
    'redirect_uri' => env('WHOLESALE_GMAIL_REDIRECT_URI'),
    // Explicit tenant/store/account/sender bindings; no shared provider fallback.
    'tenants' => json_decode((string) env('WHOLESALE_GMAIL_TENANTS', '{}'), true) ?: [],
];
