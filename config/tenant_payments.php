<?php

return [
    // Connection setup only. Existing collection, tax and webhook gates still apply.
    'setup_enabled' => (bool) env('TENANT_PAYMENT_SETUP_ENABLED', false),
    'tenant_slugs' => array_values(array_filter(array_map('trim', explode(',', (string) env('TENANT_PAYMENT_SETUP_TENANTS', ''))))),
    'stripe_embedded_enabled' => (bool) env('TENANT_STRIPE_EMBEDDED_ENABLED', false),
    'paypal' => [
        'enabled' => (bool) env('TENANT_PAYPAL_ENABLED', false),
        'environment' => env('PAYPAL_ENVIRONMENT', 'sandbox'),
        'partner_approved' => (bool) env('PAYPAL_PARTNER_APPROVED', false),
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'partner_id' => env('PAYPAL_PARTNER_MERCHANT_ID'),
        'bn_code' => env('PAYPAL_BN_CODE'),
    ],
];
