<?php

use App\Models\CustomerAccessRequest;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantCommercialOverride;
use App\Models\User;
use App\Services\Billing\TenantBillingNextStepResolver;
use App\Services\Tenancy\TenantCommercialExperienceService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('app.url', 'https://app.grovebud.com');
    config()->set('tenancy.domains.canonical.base_domain', 'grovebud.com');
    config()->set('tenancy.landlord.primary_host', 'app.grovebud.com');
    config()->set('tenancy.auth.flagship_hosts', ['app.grovebud.com']);
    config()->set('services.stripe.api_base', 'https://stripe.test');
});

test('merchant journey payload includes normalized billing interest and next step', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'approved',
        'name' => 'Acme Ops',
        'email' => 'ops@acme.example.com',
        'tenant_id' => (int) $tenant->id,
        'metadata' => [
            'preferred_plan_key' => 'growth',
            'addons_interest' => ['sms', 'referrals'],
            'requested_via' => 'platform',
        ],
    ]);

    $payload = app(TenantCommercialExperienceService::class)->merchantJourneyPayload((int) $tenant->id);

    expect($payload)->toHaveKeys(['billing_interest', 'billing_next_step'])
        ->and((array) $payload['billing_interest'])->toMatchArray([
            'preferred_plan_key' => 'growth',
            'addons_interest' => ['sms', 'referrals'],
            'source' => 'customer_access_request',
        ])
        ->and((string) data_get($payload, 'billing_next_step.mode'))->toBe('landlord_follow_up');
});

test('hosted checkout mode when stripe is enabled and interest is valid', function (): void {
    config()->set('commercial.billing_readiness.checkout_active', true);
    config()->set('services.stripe.secret', 'sk_test_123');

    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
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

    $journey = app(TenantCommercialExperienceService::class)->merchantJourneyPayload((int) $tenant->id);
    $nextStep = (array) ($journey['billing_next_step'] ?? []);

    expect((string) ($nextStep['mode'] ?? ''))->toBe('hosted_checkout')
        ->and((array) ($nextStep['cta_route'] ?? []))->toMatchArray(['name' => 'billing.checkout', 'method' => 'post']);
});

test('billing portal mode when stripe customer reference exists', function (): void {
    config()->set('commercial.billing_readiness.checkout_active', true);
    config()->set('services.stripe.secret', 'sk_test_123');

    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    TenantCommercialOverride::query()->create([
        'tenant_id' => (int) $tenant->id,
        'billing_mapping' => [
            'stripe' => [
                'customer_reference' => 'cus_123',
                'subscription_reference' => 'sub_123',
            ],
        ],
    ]);

    $resolver = app(TenantBillingNextStepResolver::class);
    $nextStep = $resolver->resolveForTenantId((int) $tenant->id, [
        'preferred_plan_key' => 'growth',
        'addons_interest' => ['sms'],
        'source' => 'customer_access_request',
        'captured_at' => now()->toIso8601String(),
        'access_request_id' => 1,
    ]);

    expect((string) ($nextStep['mode'] ?? ''))->toBe('billing_portal')
        ->and((array) ($nextStep['cta_route'] ?? []))->toMatchArray(['name' => 'billing.portal', 'method' => 'post']);
});

test('hosted checkout endpoint creates session and redirects with tenant-safe urls and metadata', function (): void {
    config()->set('commercial.billing_readiness.checkout_active', true);
    config()->set('services.stripe.secret', 'sk_test_123');

    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $user = User::factory()->create([
        'role' => 'manager',
        'requested_via' => 'customer_production',
        'is_active' => true,
        'email' => 'ops@acme.example.com',
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'manager']);

    TenantAccessProfile::query()->create([
        'tenant_id' => (int) $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'approved',
        'name' => 'Acme Ops',
        'email' => $user->email,
        'tenant_id' => (int) $tenant->id,
        'metadata' => [
            'preferred_plan_key' => 'growth',
            'addons_interest' => ['sms'],
        ],
    ]);

    $checkoutUrl = 'https://checkout.stripe.test/session/cs_123';

    $tenantId = (int) $tenant->id;

    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($checkoutUrl, $tenantId) {
        if (str_contains($request->url(), '/v1/prices')) {
            return Http::response([
                'data' => [
                    ['id' => 'price_growth', 'lookup_key' => 'tier_growth_monthly'],
                    ['id' => 'price_sms', 'lookup_key' => 'addon_sms_monthly'],
                ],
            ], 200);
        }

        if (str_contains($request->url(), '/v1/checkout/sessions')) {
            $data = $request->data();

            expect((string) ($data['mode'] ?? ''))->toBe('subscription')
                ->and((string) ($data['line_items[0][price]'] ?? ''))->toBe('price_growth')
                ->and((string) ($data['line_items[1][price]'] ?? ''))->toBe('price_sms')
                ->and((string) ($data['metadata[tenant_id]'] ?? ''))->toBe((string) $tenantId)
                ->and((string) ($data['metadata[preferred_plan_key]'] ?? ''))->toBe('growth')
                ->and((string) ($data['success_url'] ?? ''))->toContain('https://acme.grovebud.com/start?billing=success&session_id={CHECKOUT_SESSION_ID}')
                ->and((string) ($data['cancel_url'] ?? ''))->toBe('https://acme.grovebud.com/start?billing=cancel')
                ->and(json_encode($data))->not->toContain('price_evil');

            return Http::response([
                'id' => 'cs_123',
                'url' => $checkoutUrl,
            ], 200);
        }

        return Http::response(['error' => ['message' => 'unexpected']], 500);
    });

    $this->actingAs($user)
        ->post('http://acme.grovebud.com/billing/checkout', [
            'price_id' => 'price_evil',
            'line_items' => [['price' => 'price_evil']],
        ])
        ->assertRedirect($checkoutUrl);

    expect(TenantAccessProfile::query()->where('tenant_id', (int) $tenant->id)->value('plan_key'))->toBe('starter');
});

test('billing portal endpoint creates session and redirects', function (): void {
    config()->set('commercial.billing_readiness.checkout_active', true);
    config()->set('services.stripe.secret', 'sk_test_123');

    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    TenantCommercialOverride::query()->create([
        'tenant_id' => (int) $tenant->id,
        'billing_mapping' => [
            'stripe' => [
                'customer_reference' => 'cus_123',
                'subscription_reference' => 'sub_123',
            ],
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'manager',
        'requested_via' => 'customer_production',
        'is_active' => true,
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'manager']);

    $portalUrl = 'https://billing.stripe.test/session/ps_123';

    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($portalUrl) {
        if (str_contains($request->url(), '/v1/billing_portal/sessions')) {
            $data = $request->data();
            expect((string) ($data['customer'] ?? ''))->toBe('cus_123')
                ->and((string) ($data['return_url'] ?? ''))->toBe('https://acme.grovebud.com/start?billing=return');

            return Http::response([
                'id' => 'ps_123',
                'url' => $portalUrl,
            ], 200);
        }

        return Http::response(['error' => ['message' => 'unexpected']], 500);
    });

    $this->actingAs($user)
        ->post('http://acme.grovebud.com/billing/portal')
        ->assertRedirect($portalUrl);
});

test('unknown plan keys are rejected and do not enable checkout', function (): void {
    config()->set('commercial.billing_readiness.checkout_active', true);
    config()->set('services.stripe.secret', 'sk_test_123');

    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'approved',
        'name' => 'Acme Ops',
        'email' => 'ops@acme.example.com',
        'tenant_id' => (int) $tenant->id,
        'metadata' => [
            'preferred_plan_key' => 'evil_plan',
            'addons_interest' => ['evil_addon'],
        ],
    ]);

    $journey = app(TenantCommercialExperienceService::class)->merchantJourneyPayload((int) $tenant->id);
    expect(data_get($journey, 'billing_interest.preferred_plan_key'))->toBeNull()
        ->and((array) data_get($journey, 'billing_interest.addons_interest'))->toBe([])
        ->and((string) data_get($journey, 'billing_next_step.mode'))->toBe('unavailable');
});
