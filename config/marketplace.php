<?php

return [
    'enabled' => (bool) env('MARKETPLACE_ENABLED', false),
    'checkout_enabled' => (bool) env('MARKETPLACE_CHECKOUT_ENABLED', false),
    'checkout_provider' => env('MARKETPLACE_CHECKOUT_PROVIDER', 'everbranch'),
    'stripe_webhook_secret' => env('MARKETPLACE_STRIPE_WEBHOOK_SECRET'),
    'shop_pay_enabled' => (bool) env('MARKETPLACE_SHOP_PAY_ENABLED', false),
    'stripe_live_verified' => (bool) env('MARKETPLACE_STRIPE_LIVE_VERIFIED', false),
    // Only independently verified hosts belong here. No wildcard or tenant fallback.
    'host' => env('MARKETPLACE_VERIFIED_HOST', ''),
    'operator_user_ids' => array_filter(array_map('intval', explode(',', env('MARKETPLACE_OPERATOR_USER_IDS', '')))),
    'api_version' => '2026-10',
    'currency' => 'USD',
    'country' => 'US',
    'demo' => (bool) env('MARKETPLACE_DEMO', false),
];
