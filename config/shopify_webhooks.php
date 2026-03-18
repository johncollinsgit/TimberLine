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

    'format' => 'json',
];
