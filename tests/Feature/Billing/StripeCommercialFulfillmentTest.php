<?php

use App\Models\CustomerAccessRequest;
use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantBillingSubscription;
use App\Models\TenantCommercialOverride;
use App\Models\User;
use App\Services\Tenancy\TenantCommercialExperienceService;

beforeEach(function (): void {
    $this->withoutVite();
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.theeverbranch.com';

    config()->set('app.url', 'https://'.$landlordHost);
    config()->set('tenancy.landlord.primary_host', $landlordHost);
    config()->set('tenancy.landlord.hosts', [$landlordHost]);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
    config()->set('services.stripe.webhook_secret', 'whsec_test_123');

    config()->set('services.stripe.secret', 'sk_test_123');
    config()->set('commercial.billing_readiness.checkout_active', true);
    config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', true);
});

if (! function_exists('stripeSignatureHeader')) {
    function stripeSignatureHeader(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $signedPayload = $timestamp.'.'.$payload;
        $signature = hash_hmac('sha256', $signedPayload, $secret);

        return "t={$timestamp},v1={$signature}";
    }
}

test('checkout completion webhook can fulfill local plan and addons idempotently', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.theeverbranch.com';

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
        'id' => 'evt_fulfill_1',
        'type' => 'checkout.session.completed',
        'created' => time(),
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'cs_test_fulfill_1',
                'object' => 'checkout.session',
                'payment_status' => 'paid',
                'customer' => 'cus_fulfill_1',
                'subscription' => 'sub_fulfill_1',
                'metadata' => [
                    'tenant_id' => (string) $tenant->id,
                    'tenant_slug' => 'acme',
                    'checkout_plan_key' => 'growth',
                    'addons_interest' => 'sms',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $signature = stripeSignatureHeader($payload, (string) config('services.stripe.webhook_secret'));

    $this->call('POST', "http://{$landlordHost}/webhooks/stripe/events", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $payload)->assertOk();

    expect((string) TenantAccessProfile::query()->where('tenant_id', (int) $tenant->id)->value('plan_key'))->toBe('growth');

    expect(TenantAccessAddon::query()
        ->where('tenant_id', (int) $tenant->id)
        ->where('addon_key', 'sms')
        ->where('enabled', true)
        ->exists())->toBeTrue();

    expect(TenantBillingFulfillment::query()->where('tenant_id', (int) $tenant->id)->count())->toBe(1);

    expect(TenantBillingSubscription::withoutGlobalScopes()
        ->where('tenant_id', (int) $tenant->id)
        ->where('provider_subscription_reference', 'sub_fulfill_1')
        ->pluck('purchase_key')
        ->sort()
        ->values()
        ->all())->toBe(['addon.sms', 'plan.growth']);

    $override = TenantCommercialOverride::query()->where('tenant_id', (int) $tenant->id)->first();
    expect($override)->not->toBeNull()
        ->and((string) data_get($override?->billing_mapping ?? [], 'stripe.fulfillment.status'))->toBeIn(['fulfilled', 'noop']);

    $journey = app(TenantCommercialExperienceService::class)->merchantJourneyPayload((int) $tenant->id);
    expect((string) data_get($journey, 'commercial_summary.lifecycle_state'))->toBe('fulfilled');

    // Duplicate event idempotent: no extra fulfillment row, no double apply.
    $this->call('POST', "http://{$landlordHost}/webhooks/stripe/events", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $payload)->assertOk();

    expect(TenantBillingFulfillment::query()->where('tenant_id', (int) $tenant->id)->count())->toBe(1);
    expect(TenantBillingSubscription::withoutGlobalScopes()
        ->where('provider_subscription_reference', 'sub_fulfill_1')
        ->count())->toBe(2);
});

test('landlord reconcile endpoint is gated and replays fulfillment safely', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.theeverbranch.com';

    $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);

    TenantAccessProfile::query()->create([
        'tenant_id' => (int) $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    TenantCommercialOverride::query()->create([
        'tenant_id' => (int) $tenant->id,
        'billing_mapping' => [
            'stripe' => [
                'customer_reference' => 'cus_replay_1',
                'subscription_reference' => 'sub_replay_1',
                'billing_confirmed_at' => now()->toIso8601String(),
                'confirmed_plan_key' => 'growth',
                'confirmed_addon_keys' => ['sms'],
                'checkout_completed_at' => now()->toIso8601String(),
            ],
        ],
    ]);

    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($manager)
        ->post("http://{$landlordHost}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/fulfillment-reconcile")
        ->assertForbidden();

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post("http://{$landlordHost}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/fulfillment-reconcile")
        ->assertRedirect();

    expect((string) TenantAccessProfile::query()->where('tenant_id', (int) $tenant->id)->value('plan_key'))->toBe('growth');
});

test('add-on checkout preserves the active plan and other separately billed add-ons', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.theeverbranch.com';
    $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme-addon']);

    TenantAccessProfile::query()->create([
        'tenant_id' => (int) $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'stripe_fulfillment',
    ]);
    TenantAccessAddon::query()->create([
        'tenant_id' => (int) $tenant->id,
        'addon_key' => 'sms',
        'enabled' => true,
        'source' => 'stripe_fulfillment',
    ]);

    $event = static function (string $id, string $type, string $status) use ($tenant): string {
        $object = [
            'id' => $type === 'checkout.session.completed' ? 'cs_addon_1' : 'sub_addon_1',
            'object' => $type === 'checkout.session.completed' ? 'checkout.session' : 'subscription',
            'customer' => 'cus_addon_1',
            'status' => $status,
            'metadata' => [
                'tenant_id' => (string) $tenant->id,
                'purpose' => 'addon_checkout',
                'checkout_plan_key' => 'growth',
                'checkout_addons_interest' => 'order_calendar',
            ],
        ];
        if ($type === 'checkout.session.completed') {
            $object['payment_status'] = 'paid';
            $object['subscription'] = 'sub_addon_1';
        }

        return json_encode([
            'id' => $id,
            'type' => $type,
            'created' => time(),
            'livemode' => false,
            'data' => ['object' => $object],
        ], JSON_THROW_ON_ERROR);
    };

    $completed = $event('evt_addon_complete_1', 'checkout.session.completed', 'complete');
    $this->call('POST', "http://{$landlordHost}/webhooks/stripe/events", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => stripeSignatureHeader($completed, (string) config('services.stripe.webhook_secret')),
    ], $completed)->assertOk();

    expect((string) TenantAccessProfile::query()->where('tenant_id', (int) $tenant->id)->value('plan_key'))->toBe('growth')
        ->and(TenantAccessAddon::query()->where('tenant_id', (int) $tenant->id)->where('enabled', true)->pluck('addon_key')->sort()->values()->all())
        ->toBe(['order_calendar', 'sms']);

    $deleted = $event('evt_addon_deleted_1', 'customer.subscription.deleted', 'canceled');
    $this->call('POST', "http://{$landlordHost}/webhooks/stripe/events", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => stripeSignatureHeader($deleted, (string) config('services.stripe.webhook_secret')),
    ], $deleted)->assertOk();

    expect((string) TenantAccessProfile::query()->where('tenant_id', (int) $tenant->id)->value('plan_key'))->toBe('growth')
        ->and(TenantAccessAddon::query()->where('tenant_id', (int) $tenant->id)->where('enabled', true)->pluck('addon_key')->sort()->values()->all())
        ->toBe(['sms']);
});

test('subscription deleted webhook downgrades stripe-fulfilled access and keeps lifecycle truthful', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);

    TenantAccessProfile::query()->create([
        'tenant_id' => (int) $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'stripe_fulfillment',
    ]);

    TenantAccessAddon::query()->create([
        'tenant_id' => (int) $tenant->id,
        'addon_key' => 'sms',
        'enabled' => true,
        'source' => 'stripe_fulfillment',
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

    TenantCommercialOverride::query()->create([
        'tenant_id' => (int) $tenant->id,
        'billing_mapping' => [
            'stripe' => [
                'customer_reference' => 'cus_cancel_1',
                'subscription_reference' => 'sub_cancel_1',
                'subscription_status' => 'active',
                'billing_confirmed_at' => now()->toIso8601String(),
                'confirmed_plan_key' => 'growth',
                'confirmed_addon_keys' => ['sms'],
            ],
        ],
    ]);

    $payload = json_encode([
        'id' => 'evt_sub_deleted_1',
        'type' => 'customer.subscription.deleted',
        'created' => time(),
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'sub_cancel_1',
                'object' => 'subscription',
                'customer' => 'cus_cancel_1',
                'status' => 'canceled',
                'metadata' => [
                    'tenant_id' => (string) $tenant->id,
                    'checkout_plan_key' => 'growth',
                    'checkout_addons_interest' => 'sms',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $signature = stripeSignatureHeader($payload, (string) config('services.stripe.webhook_secret'));

    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.theeverbranch.com';

    $this->call('POST', "http://{$landlordHost}/webhooks/stripe/events", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $payload)->assertOk();

    // Downgraded to the lowest-position canonical plan, and prior stripe add-ons removed.
    expect((string) TenantAccessProfile::query()->where('tenant_id', (int) $tenant->id)->value('plan_key'))->toBe('base');
    expect((bool) TenantAccessAddon::query()
        ->where('tenant_id', (int) $tenant->id)
        ->where('addon_key', 'sms')
        ->value('enabled'))->toBeFalse();

    expect(TenantBillingSubscription::withoutGlobalScopes()
        ->where('provider_subscription_reference', 'sub_cancel_1')
        ->pluck('status')
        ->unique()
        ->values()
        ->all())->toBe(['canceled']);

    $journey = app(TenantCommercialExperienceService::class)->merchantJourneyPayload((int) $tenant->id);
    expect((string) data_get($journey, 'commercial_summary.lifecycle_state'))->toBe('action_required')
        ->and((string) data_get($journey, 'billing_next_step.mode'))->toBe('hosted_checkout');
});
