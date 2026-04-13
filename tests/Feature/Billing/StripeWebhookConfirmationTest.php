<?php

use App\Models\CustomerAccessRequest;
use App\Models\StripeWebhookEvent;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantCommercialOverride;
use App\Services\Tenancy\TenantCommercialExperienceService;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('app.url', 'https://app.backstage.local');
    config()->set('tenancy.landlord.primary_host', 'app.backstage.local');
    config()->set('tenancy.auth.flagship_hosts', ['app.backstage.local']);
    config()->set('services.stripe.webhook_secret', 'whsec_test_123');

    // Enable hosted billing in test so Start Here can move to confirmed/portal mode.
    config()->set('commercial.billing_readiness.checkout_active', true);
    config()->set('services.stripe.secret', 'sk_test_123');
});

function stripeSignatureHeader(string $payload, string $secret, ?int $timestamp = null): string
{
    $timestamp = $timestamp ?? time();
    $signedPayload = $timestamp.'.'.$payload;
    $signature = hash_hmac('sha256', $signedPayload, $secret);

    return "t={$timestamp},v1={$signature}";
}

test('valid signed webhook updates billing mapping for correct tenant and does not mutate entitlements', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);

    TenantAccessProfile::query()->create([
        'tenant_id' => (int) $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
        'metadata' => [],
    ]);

    CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'approved',
        'name' => 'Acme Ops',
        'email' => 'ops@acme.example.com',
        'tenant_id' => (int) $tenant->id,
        'metadata' => [
            'preferred_plan_key' => 'growth',
            'addons_interest' => ['sms'],
        ],
    ]);

    $payload = json_encode([
        'id' => 'evt_123',
        'type' => 'checkout.session.completed',
        'created' => time(),
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'cs_test_123',
                'object' => 'checkout.session',
                'customer' => 'cus_123',
                'subscription' => 'sub_123',
                'metadata' => [
                    'tenant_id' => (string) $tenant->id,
                    'tenant_slug' => 'acme',
                    'preferred_plan_key' => 'growth',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $signature = stripeSignatureHeader($payload, (string) config('services.stripe.webhook_secret'));

    // Prime journey cache, then ensure webhook invalidates override-driven cache cleanly.
    $before = app(TenantCommercialExperienceService::class)->merchantJourneyPayload((int) $tenant->id);
    expect((string) data_get($before, 'billing_next_step.mode'))->toBe('hosted_checkout');

    $this->call('POST', 'http://app.backstage.local/webhooks/stripe/events', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $payload)
        ->assertOk();

    $override = TenantCommercialOverride::query()->where('tenant_id', (int) $tenant->id)->first();
    expect($override)->not->toBeNull()
        ->and((string) data_get($override?->billing_mapping ?? [], 'stripe.customer_reference'))->toBe('cus_123')
        ->and((string) data_get($override?->billing_mapping ?? [], 'stripe.subscription_reference'))->toBe('sub_123')
        ->and((string) data_get($override?->billing_mapping ?? [], 'stripe.checkout_session_id'))->toBe('cs_test_123')
        ->and((string) data_get($override?->billing_mapping ?? [], 'stripe.last_webhook_event_id'))->toBe('evt_123')
        ->and((string) data_get($override?->billing_mapping ?? [], 'stripe.last_webhook_event_type'))->toBe('checkout.session.completed');

    expect(StripeWebhookEvent::query()->where('event_id', 'evt_123')->exists())->toBeTrue();

    // No optimistic local entitlements/plan changes.
    expect((string) TenantAccessProfile::query()->where('tenant_id', (int) $tenant->id)->value('plan_key'))->toBe('starter');

    $after = app(TenantCommercialExperienceService::class)->merchantJourneyPayload((int) $tenant->id);
    expect((string) data_get($after, 'billing_next_step.mode'))->toBe('billing_portal');
});

test('duplicate webhook event id is idempotent', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);

    $payload = json_encode([
        'id' => 'evt_dup',
        'type' => 'checkout.session.completed',
        'created' => time(),
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'cs_test_dup',
                'object' => 'checkout.session',
                'customer' => 'cus_dup',
                'subscription' => 'sub_dup',
                'metadata' => [
                    'tenant_id' => (string) $tenant->id,
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $signature = stripeSignatureHeader($payload, (string) config('services.stripe.webhook_secret'));

    $this->call('POST', 'http://app.backstage.local/webhooks/stripe/events', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $payload)
        ->assertOk();

    $this->call('POST', 'http://app.backstage.local/webhooks/stripe/events', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $payload)
        ->assertOk();

    expect(StripeWebhookEvent::query()->where('event_id', 'evt_dup')->count())->toBe(1);
});

test('unsupported event type does nothing to tenant billing mapping', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);

    $payload = json_encode([
        'id' => 'evt_unsupported',
        'type' => 'invoice.payment_succeeded',
        'created' => time(),
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'in_123',
                'object' => 'invoice',
                'metadata' => [
                    'tenant_id' => (string) $tenant->id,
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $signature = stripeSignatureHeader($payload, (string) config('services.stripe.webhook_secret'));

    $this->call('POST', 'http://app.backstage.local/webhooks/stripe/events', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $payload)
        ->assertOk();

    expect(TenantCommercialOverride::query()->where('tenant_id', (int) $tenant->id)->exists())->toBeFalse();
});

test('invalid signature is rejected', function (): void {
    $payload = json_encode([
        'id' => 'evt_bad_sig',
        'type' => 'checkout.session.completed',
        'created' => time(),
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'cs_test_bad',
                'object' => 'checkout.session',
                'metadata' => [
                    'tenant_id' => '1',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', 'http://app.backstage.local/webhooks/stripe/events', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => 't=1,v1=deadbeef',
    ], $payload)
        ->assertStatus(400);

    expect(StripeWebhookEvent::query()->where('event_id', 'evt_bad_sig')->exists())->toBeFalse();
});
