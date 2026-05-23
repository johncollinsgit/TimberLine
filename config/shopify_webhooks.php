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
