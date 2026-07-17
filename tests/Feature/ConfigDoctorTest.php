<?php

afterEach(function (): void {
    config()->set('features.tenant_messaging_auto_bootstrap', false);
    config()->set('features.tenant_messaging_platform', false);
    config()->set('features.tenant_messaging_provisioning', false);
    config()->set('marketing.messaging.platform.automatic_tenant_ids', []);
});

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

test('config doctor fails closed when automatic messaging lacks provider gates', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('app.url', 'https://example.test');
    config()->set('mail.default', 'smtp');
    config()->set('services.shopify.stores.retail.shop', 'x.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'id');
    config()->set('services.shopify.stores.retail.client_secret', 'secret');
    config()->set('features.tenant_messaging_auto_bootstrap', true);
    config()->set('features.tenant_messaging_platform', true);
    config()->set('features.tenant_messaging_provisioning', true);
    config()->set('marketing.messaging.platform.automatic_tenant_ids', [4]);
    config()->set('services.sendgrid.api_key', null);
    config()->set('services.sendgrid.managed_domain_authentication_id', null);
    config()->set('services.twilio.account_sid', 'not-a-real-sid');
    config()->set('services.twilio.auth_token', null);

    $this->artisan('config:doctor --env=production')
        ->expectsOutputToContain('SENDGRID_API_KEY')
        ->expectsOutputToContain('TWILIO_ACCOUNT_SID')
        ->assertFailed();
});
