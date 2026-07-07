<?php

test('config doctor passes when the required production keys are present', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('app.url', 'https://example.test');
    config()->set('mail.default', 'smtp');
    config()->set('services.shopify.stores.retail.shop', 'x.myshopify.com');
    config()->set('services.shopify.stores.retail.access_token', 'token');
    config()->set('services.shopify.stores.retail.client_id', 'id');
    config()->set('services.shopify.stores.retail.client_secret', 'secret');

    $this->artisan('config:doctor --env=production')->assertSuccessful();
});

test('config doctor fails loudly when the required retail store keys are missing', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('app.url', 'https://example.test');
    config()->set('mail.default', 'smtp');
    config()->set('services.shopify.stores.retail.shop', null);
    config()->set('services.shopify.stores.retail.access_token', null);
    config()->set('services.shopify.stores.retail.client_id', null);
    config()->set('services.shopify.stores.retail.client_secret', null);

    $this->artisan('config:doctor --env=production')->assertFailed();
});
