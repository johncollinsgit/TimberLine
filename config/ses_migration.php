<?php

return [
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
