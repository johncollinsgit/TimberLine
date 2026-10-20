<?php

return [
    'simulator_fixture_enabled' => (bool) env('SES_SIMULATOR_FIXTURE_ENABLED', false),
    // Critical application mail has a separate opt-in outbox and credential scope.
    'platform_enabled' => (bool) env('SES_PLATFORM_SENDING_ENABLED', false),
    'platform_queue_connection' => env('SES_PLATFORM_QUEUE_CONNECTION', 'database'),
    'platform_queue' => env('SES_PLATFORM_QUEUE', 'ses-platform'),
    'platform_from' => env('SES_PLATFORM_FROM', 'no-reply@theeverbranch.com'),
    'platform_configuration_set' => env('SES_PLATFORM_CONFIGURATION_SET', ''),
    'platform_key' => env('SES_PLATFORM_AWS_ACCESS_KEY_ID'),
    'platform_secret' => env('SES_PLATFORM_AWS_SECRET_ACCESS_KEY'),
    'platform_token' => env('SES_PLATFORM_AWS_SESSION_TOKEN'),
    'platform_recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('SES_PLATFORM_RECIPIENTS', ''))))),
    'platform_allow_all_recipients' => (bool) env('SES_PLATFORM_ALLOW_ALL_RECIPIENTS', false),
    'platform_daily_attempts' => (int) env('SES_PLATFORM_DAILY_ATTEMPTS', 100),
    'platform_max_bytes' => (int) env('SES_PLATFORM_MAX_BYTES', 1048576),
    'platform_min_attempt_cost_micros' => (int) env('SES_PLATFORM_MIN_ATTEMPT_COST_MICROS', 1000),
    // Selection is explicit. A failed SES attempt never selects another provider.
    'tenant_enabled' => (bool) env('SES_TENANT_SENDING_ENABLED', false),
    'tenant_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('SES_TENANT_IDS', ''))))),
    'sns_topic_arns' => array_values(array_filter(array_map('trim', explode(',', (string) env('SES_SNS_TOPIC_ARNS', ''))))),
    // Attempt ceilings include uncertain sends; they are not a billing estimate.
    'account_daily_attempts' => (int) env('SES_ACCOUNT_DAILY_ATTEMPTS', 1000),
    'tenant_daily_attempts' => (int) env('SES_TENANT_DAILY_ATTEMPTS', 100),
    'max_per_second' => (int) env('SES_MAX_PER_SECOND', 1),
    'daily_spend_cap_micros' => (int) env('SES_DAILY_SPEND_CAP_MICROS', 1000000),
    // Must be reviewed against the account's actual plan, tenant fees and payload.
    'approved_attempt_cost_micros' => (int) env('SES_APPROVED_ATTEMPT_COST_MICROS', 0),
];
