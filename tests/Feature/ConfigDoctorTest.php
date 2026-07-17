<?php

function configureConfigDoctorProductionRequirements(): void
{
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('mail.default', 'smtp');
    config()->set('commercial.billing_readiness.allow_production_test_mode', false);
    config()->set('commercial.billing_readiness.agreement_checkout.enabled', false);
    config()->set('commercial.billing_readiness.direct_invoicing.enabled', false);
    config()->set('services.shopify.stores.retail.shop', 'x.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'id');
    config()->set('services.shopify.stores.retail.client_secret', 'secret');
}

function configureAgreementStripe(string $publishableKey, string $secretKey, string $webhookSecret = 'whsec_example'): void
{
    config()->set('commercial.billing_readiness.agreement_checkout.enabled', true);
    config()->set('commercial.billing_readiness.direct_invoicing.enabled', false);
    config()->set('commercial.billing_readiness.allow_production_test_mode', false);
    config()->set('services.stripe.account_id', 'acct_1234567890');
    config()->set('services.stripe.publishable_key', $publishableKey);
    config()->set('services.stripe.secret', $secretKey);
    config()->set('services.stripe.webhook_secret', $webhookSecret);
}

test('environment template has unique keys and safe Stripe placeholders', function (): void {
    $contents = (string) file_get_contents(base_path('.env.example'));
    preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $contents, $matches);
    $duplicates = array_filter(
        array_count_values($matches[1] ?? []),
        static fn (int $count): bool => $count > 1
    );

    expect($duplicates)->toBe([])
        ->and($contents)->not->toContain('fireforgetech')
        ->and($contents)->not->toContain('mk_1Ttv')
        ->and($contents)->toContain('STRIPE_ACCOUNT_ID=')
        ->and($contents)->toContain('STRIPE_KEY=')
        ->and($contents)->toContain('STRIPE_SECRET=')
        ->and($contents)->toContain('STRIPE_WEBHOOK_SECRET=')
        ->and($contents)->toContain('pk_test_... or pk_live_...')
        ->and($contents)->toContain('sk_test_... or sk_live_...');
});

test('config doctor passes when the required production keys are present', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('app.url', 'https://example.test');
    config()->set('mail.default', 'smtp');
    config()->set('commercial.billing_readiness.allow_production_test_mode', false);
    config()->set('commercial.billing_readiness.agreement_checkout.enabled', false);
    config()->set('commercial.billing_readiness.direct_invoicing.enabled', false);
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

test('config doctor skips Stripe credential validation while agreement checkout is disabled', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('commercial.billing_readiness.agreement_checkout.enabled', false);
    config()->set('commercial.billing_readiness.direct_invoicing.enabled', false);
    config()->set('services.stripe.account_id', 'not-an-account');
    config()->set('services.stripe.publishable_key', 'mk_invalid');
    config()->set('services.stripe.secret', 'mk_invalid');

    $this->artisan('config:doctor --env=local')->assertSuccessful();
});

test('config doctor accepts complete test-mode Stripe credentials locally', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    configureAgreementStripe('pk_test_example', 'sk_test_example');

    $this->artisan('config:doctor --env=local')->assertSuccessful();
});

test('config doctor validates Stripe credentials when direct invoicing alone is enabled', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('commercial.billing_readiness.agreement_checkout.enabled', false);
    config()->set('commercial.billing_readiness.direct_invoicing.enabled', true);
    config()->set('services.stripe.account_id', 'acct_1234567890');
    config()->set('services.stripe.publishable_key', 'pk_test_example');
    config()->set('services.stripe.secret', 'sk_test_example');
    config()->set('services.stripe.webhook_secret', 'whsec_example');

    $this->artisan('config:doctor --env=local')->assertSuccessful();

    config()->set('services.stripe.secret', 'mk_invalid');
    $this->artisan('config:doctor --env=local')->assertFailed();
});

test('config doctor rejects manually prefixed or malformed Stripe credentials', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    configureAgreementStripe('mk_publishable', 'mk_secret');
    config()->set('services.stripe.account_id', 'merchant_123');

    $this->artisan('config:doctor --env=local')->assertFailed();
});

test('config doctor rejects mixed Stripe test and live modes', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    configureAgreementStripe('pk_test_example', 'sk_live_example');

    $this->artisan('config:doctor --env=local')->assertFailed();
});

test('config doctor rejects a missing Stripe webhook secret when checkout is enabled', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    configureAgreementStripe('pk_test_example', 'sk_test_example', '');

    $this->artisan('config:doctor --env=local')->assertFailed();
});

test('config doctor accepts live Stripe credentials only with production readiness gates', function (): void {
    configureConfigDoctorProductionRequirements();
    configureAgreementStripe('pk_live_example', 'sk_live_example');
    config()->set('commercial.billing_readiness.agreement_checkout.tax_decision_confirmed', true);
    config()->set('commercial.billing_readiness.agreement_checkout.relay_payout_verified', true);

    $this->artisan('config:doctor --env=production')->assertSuccessful();
});

test('config doctor allows production-host Stripe sandbox only with explicit gate and concrete tenant allowlist', function (): void {
    configureConfigDoctorProductionRequirements();
    config()->set('commercial.billing_readiness.direct_invoicing.enabled', true);
    config()->set('commercial.billing_readiness.direct_invoicing.tenant_slugs', ['front-yard-foods']);
    config()->set('services.stripe.account_id', 'acct_1234567890');
    config()->set('services.stripe.publishable_key', 'pk_test_example');
    config()->set('services.stripe.secret', 'sk_test_example');
    config()->set('services.stripe.webhook_secret', 'whsec_example');

    $this->artisan('config:doctor --env=production')->assertFailed();

    config()->set('commercial.billing_readiness.allow_production_test_mode', true);
    $this->artisan('config:doctor --env=production')->assertSuccessful();

    config()->set('commercial.billing_readiness.direct_invoicing.tenant_slugs', ['*']);
    $this->artisan('config:doctor --env=production')->assertFailed();
});

test('config doctor rejects live Stripe credentials when production readiness gates are incomplete', function (): void {
    configureConfigDoctorProductionRequirements();
    configureAgreementStripe('pk_live_example', 'sk_live_example');
    config()->set('commercial.billing_readiness.agreement_checkout.tax_decision_confirmed', false);
    config()->set('commercial.billing_readiness.agreement_checkout.relay_payout_verified', false);

    $this->artisan('config:doctor --env=production')->assertFailed();
});
