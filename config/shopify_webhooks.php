<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Required Shopify Webhook Subscriptions
    |--------------------------------------------------------------------------
    |
    | Topic => route name for this app's webhook callback endpoint.
    | These are verified and repaired by ShopifyWebhookSubscriptionService.
    |
    */
    'required_topics' => [
        'orders/create' => 'shopify.webhooks.orders.create',
        'orders/updated' => 'shopify.webhooks.orders.updated',
        'orders/cancelled' => 'shopify.webhooks.orders.cancelled',
        'refunds/create' => 'shopify.webhooks.refunds.create',
        'customers/create' => 'shopify.webhooks.customers.create',
        'customers/update' => 'shopify.webhooks.customers.update',
        'customers/merge' => 'shopify.webhooks.customers.merge',
        'subscription_contracts/create' => 'shopify.webhooks.subscription-contracts.create',
        'subscription_contracts/update' => 'shopify.webhooks.subscription-contracts.update',
        'subscription_billing_attempts/success' => 'shopify.webhooks.subscription-billing-attempts.success',
        'subscription_billing_attempts/failure' => 'shopify.webhooks.subscription-billing-attempts.failure',
        'customer_payment_methods/create' => 'shopify.webhooks.customer-payment-methods.create',
        'customer_payment_methods/update' => 'shopify.webhooks.customer-payment-methods.update',
        'customer_payment_methods/revoke' => 'shopify.webhooks.customer-payment-methods.revoke',
    ],

    /*
    |--------------------------------------------------------------------------
    | Shopify Privacy Compliance Webhooks
    |--------------------------------------------------------------------------
    |
    | These mandatory compliance topics are app-specific Shopify CLI/TOML
    | subscriptions, not shop-specific Admin API subscriptions repaired by the
    | operational webhook drift command above.
    |
    */
    'privacy_topics' => [
        'customers/data_request' => 'shopify.webhooks.customers.data-request',
        'customers/redact' => 'shopify.webhooks.customers.redact',
        'shop/redact' => 'shopify.webhooks.shop.redact',
    ],

    'format' => 'json',
];
