<?php

return [
    'runtime' => false, // Set only by the dedicated Enterprise bootstrap/provider.
    'enabled' => (bool) env('ENTERPRISE_ENABLED', false),
    'writes_enabled' => (bool) env('ENTERPRISE_WRITES_ENABLED', false),
    'collaboration_enabled' => (bool) env('ENTERPRISE_COLLABORATION_ENABLED', false),
    'planning_enabled' => (bool) env('ENTERPRISE_PLANNING_ENABLED', false),
    'ask_enabled' => (bool) env('ENTERPRISE_ASK_ENABLED', false),
    'email_drafts_enabled' => (bool) env('ENTERPRISE_EMAIL_DRAFTS_ENABLED', false),
    'workflow_enabled' => (bool) env('ENTERPRISE_WORKFLOW_ENABLED', false),
    'briefing_enabled' => (bool) env('ENTERPRISE_BRIEFING_ENABLED', false),
    'sample_data' => (bool) env('ENTERPRISE_SAMPLE_DATA', false),
    'intelligence' => [
        'enabled' => (bool) env('ENTERPRISE_AI_ENABLED', false),
        'api_key' => env('ENTERPRISE_AI_API_KEY'),
        'model' => env('ENTERPRISE_AI_MODEL'),
        'input_rate_microusd_per_million' => (int) env('ENTERPRISE_AI_INPUT_RATE_MICROUSD_PER_MILLION', 0),
        'output_rate_microusd_per_million' => (int) env('ENTERPRISE_AI_OUTPUT_RATE_MICROUSD_PER_MILLION', 0),
        'rates_valid_until' => env('ENTERPRISE_AI_RATES_VALID_UNTIL'),
        'max_input' => 12000, 'max_output' => 1800,
        'lifetime_cap_microusd' => (int) env('ENTERPRISE_AI_LIFETIME_CAP_MICROUSD', 0),
        'tenant_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('ENTERPRISE_AI_TENANT_ALLOWLIST', ''))))),
    ],
    'mailbox' => [
        'enabled' => (bool) env('ENTERPRISE_MAILBOX_ENABLED', false),
        'send_enabled' => (bool) env('ENTERPRISE_MAILBOX_SEND_ENABLED', false),
        'allowed_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('ENTERPRISE_MAILBOX_ALLOWED_IDS', ''))))),
    ],
    'gmail' => [
        'connection_enabled' => (bool) env('ENTERPRISE_GMAIL_CONNECTION_ENABLED', false),
        'send_enabled' => (bool) env('ENTERPRISE_GMAIL_SEND_ENABLED', false),
        'client_id' => env('ENTERPRISE_GMAIL_CLIENT_ID'),
        'client_secret' => env('ENTERPRISE_GMAIL_CLIENT_SECRET'),
        'redirect_uri' => env('ENTERPRISE_GMAIL_REDIRECT_URI'),
        'tenant_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('ENTERPRISE_GMAIL_TENANT_ALLOWLIST', ''))))),
    ],
    'tenant_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('ENTERPRISE_TENANT_ALLOWLIST', ''))))),
];
