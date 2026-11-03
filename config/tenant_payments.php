<?php

return [
    // Connection setup only. Existing collection, tax and webhook gates still apply.
    'setup_enabled' => (bool) env('TENANT_PAYMENT_SETUP_ENABLED', false),
    'tenant_slugs' => array_values(array_filter(array_map('trim', explode(',', (string) env('TENANT_PAYMENT_SETUP_TENANTS', ''))))),
    'stripe_embedded_enabled' => (bool) env('TENANT_STRIPE_EMBEDDED_ENABLED', false),
    'stripe_oauth_client_id' => env('STRIPE_CONNECT_CLIENT_ID'),
    'stripe_direct_webhooks_verified' => (bool) env('TENANT_STRIPE_DIRECT_WEBHOOKS_VERIFIED', false),
    // Existing accounts retain their frozen routing. Only new accounts use this policy.
    'legacy_express_tenants' => ['wren-family-soccer'],
    'braintree' => [
        'setup_enabled' => (bool) env('TENANT_BRAINTREE_SETUP_ENABLED', false),
        'tenant_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('TENANT_BRAINTREE_SETUP_TENANT_IDS', ''))), fn (int $id) => $id > 0)),
    ],
    'paypal' => [
        'enabled' => (bool) env('TENANT_PAYPAL_ENABLED', false),
        'environment' => env('PAYPAL_ENVIRONMENT', 'sandbox'),
        'partner_approved' => (bool) env('PAYPAL_PARTNER_APPROVED', false),
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'partner_id' => env('PAYPAL_PARTNER_MERCHANT_ID'),
        'bn_code' => env('PAYPAL_BN_CODE'),
        // Customer collection is separately gated from merchant connection setup.
        'soccer_checkout_enabled' => (bool) env('WREN_SOCCER_PAYPAL_CHECKOUT_ENABLED', false),
        'soccer_webhook_id' => env('WREN_SOCCER_PAYPAL_WEBHOOK_ID'),
        'soccer_webhook_verified' => (bool) env('WREN_SOCCER_PAYPAL_WEBHOOK_VERIFIED', false),
    ],
];
