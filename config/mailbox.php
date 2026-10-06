<?php

return [
    // Keep direct delivery off until a mail host, DNS, TLS, and monitoring are ready.
    'direct_enabled' => (bool) env('EVERBRANCH_MAIL_DIRECT_ENABLED', false),
    'mail_host' => env('EVERBRANCH_MAIL_HOST', 'mail.theeverbranch.com'),
    'inbound_token' => env('EVERBRANCH_MAIL_INBOUND_TOKEN'),
    'submission_host' => env('EVERBRANCH_MAIL_SUBMISSION_HOST', '127.0.0.1'),
    'submission_port' => (int) env('EVERBRANCH_MAIL_SUBMISSION_PORT', 587),
    'management_url' => env('EVERBRANCH_MAIL_MANAGEMENT_URL'),
    'management_api_key' => env('EVERBRANCH_MAIL_MANAGEMENT_API_KEY'),
];
