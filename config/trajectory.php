<?php

return [
    'enabled' => (bool) env('TRAJECTORY_ENABLED', false),
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
