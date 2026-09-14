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
    ],
    'goldapi_key' => env('GOLDAPI_KEY'),
    'categories' => ['housing', 'utilities', 'groceries', 'transport', 'health', 'insurance', 'education', 'dining', 'delivery_fees', 'entertainment', 'shopping', 'alcohol', 'tobacco', 'gambling', 'subscriptions', 'materials', 'payroll', 'fees', 'interest', 'income', 'uncategorized'],
    'discretionary_defaults' => ['gambling', 'tobacco', 'alcohol', 'delivery_fees', 'entertainment', 'shopping'],
];
