<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Models\CustomModuleRequest;
use App\Models\StripeWebhookEvent;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantSetupStatus;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();

    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.com');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
    config()->set('services.stripe.secret', null);
    config()->set('services.stripe.webhook_secret', null);
    config()->set('services.stripe.api_base', 'https://stripe.test');
    config()->set('commercial.billing_readiness.checkout_active', false);
    config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', false);
    config()->set('commercial.billing_readiness.guarded_actions.stripe_live_subscription_sync.enabled', false);
});

function billingSafetyTenant(string $slug = 'billing-safety-tenant'): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug,
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => (int) $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    TenantSetupStatus::query()->create([
        'tenant_id' => (int) $tenant->id,
        'business_profile_status' => 'ready',
        'import_path' => 'shopify',
        'shopify_connection_status' => 'connected',
        'mobile_interest' => 'undecided',
        'landlord_review_status' => 'reviewed',
    ]);

    return $tenant;
}

function billingSafetyUser(Tenant $tenant, string $role = 'marketing_manager'): User
{
    $user = User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $user->tenants()->attach($tenant->id, ['role' => 'manager']);

    return $user;
}

test('billing lifecycle and guarded stripe defaults remain disabled or narrow', function (): void {
    expect(config('commercial.billing_readiness.checkout_active'))->toBeFalse()
        ->and(config('commercial.billing_readiness.lifecycle_mutations_enabled'))->toBeFalse()
        ->and(config('commercial.billing_readiness.guarded_actions.stripe_customer_sync.enabled'))->toBeTrue()
        ->and(config('commercial.billing_readiness.guarded_actions.stripe_customer_sync.landlord_only'))->toBeTrue()
        ->and(config('commercial.billing_readiness.guarded_actions.stripe_subscription_prep.enabled'))->toBeTrue()
        ->and(config('commercial.billing_readiness.guarded_actions.stripe_subscription_prep.landlord_only'))->toBeTrue()
        ->and(config('commercial.billing_readiness.guarded_actions.stripe_live_subscription_sync.enabled'))->toBeFalse()
        ->and(config('commercial.billing_readiness.guarded_actions.stripe_live_subscription_sync.landlord_only'))->toBeTrue();
});

test('tenant hosted stripe checkout route exists but is inert by default and does not call stripe', function (): void {
    Http::fake();

    $tenant = billingSafetyTenant('acme-billing');
    $user = billingSafetyUser($tenant);

    $this->actingAs($user)
        ->from('http://acme-billing.theeverbranch.com/start')
        ->post('http://acme-billing.theeverbranch.com/billing/checkout')
        ->assertRedirect('http://acme-billing.theeverbranch.com/start');

    Http::assertNothingSent();

    expect(Route::has('billing.checkout'))->toBeTrue()
        ->and(TenantModuleEntitlement::query()->where('tenant_id', (int) $tenant->id)->exists())->toBeFalse();
});

test('tenant setup module store and custom request surfaces do not expose checkout controls by default', function (): void {
    $tenant = billingSafetyTenant('billing-copy');
    $user = billingSafetyUser($tenant);

    $this->actingAs($user)
        ->get('http://billing-copy.theeverbranch.com/start')
        ->assertOk()
        ->assertSeeText('Billing checkout is not active')
        ->assertDontSeeText('Pay now')
        ->assertDontSeeText('Subscribe now');

    $this->actingAs($user)
        ->get('http://billing-copy.theeverbranch.com/marketing/modules')
        ->assertOk()
        ->assertSeeText('checkout is not active here')
        ->assertDontSeeText('Pay now')
        ->assertDontSeeText('Subscribe now');

    $this->actingAs($user)
        ->get('http://billing-copy.theeverbranch.com/custom-module-requests/create')
        ->assertOk()
        ->assertSeeText('activate billing')
        ->assertDontSeeText('Pay now')
        ->assertDontSeeText('Subscribe now');
});

test('stripe webhook route requires webhook secret and does not expose tenant checkout or mutate entitlements', function (): void {
    $tenant = billingSafetyTenant('webhook-billing');

    $payload = json_encode([
        'id' => 'evt_pr10_missing_secret',
        'type' => 'checkout.session.completed',
        'created' => time(),
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'cs_pr10_missing_secret',
                'object' => 'checkout.session',
                'metadata' => [
                    'tenant_id' => (string) $tenant->id,
                    'checkout_plan_key' => 'growth',
                    'addons_interest' => 'sms',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', 'http://app.theeverbranch.com/webhooks/stripe/events', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => 't=1,v1=deadbeef',
    ], $payload)->assertStatus(400);

    expect(Route::has('billing.webhooks.stripe-events'))->toBeTrue()
        ->and(StripeWebhookEvent::query()->where('event_id', 'evt_pr10_missing_secret')->exists())->toBeFalse()
        ->and(TenantModuleEntitlement::query()->where('tenant_id', (int) $tenant->id)->exists())->toBeFalse();
});

test('landlord stripe actions are landlord host and operator gated', function (): void {
    $tenant = billingSafetyTenant('landlord-billing');
    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($manager)
        ->post("http://app.theeverbranch.com/landlord/tenants/{$tenant->id}/commercial/billing/stripe/customer-sync")
        ->assertForbidden();

    $this->actingAs($admin)
        ->post("http://{$tenant->slug}.theeverbranch.com/landlord/tenants/{$tenant->id}/commercial/billing/stripe/customer-sync")
        ->assertNotFound();
});

test('custom module request creation remains billing neutral', function (): void {
    $tenant = billingSafetyTenant('request-billing');
    $user = billingSafetyUser($tenant);

    $this->actingAs($user)
        ->post('http://request-billing.theeverbranch.com/custom-module-requests', [
            'title' => 'Need a field workflow',
            'problem_summary' => 'We need to track work before it becomes a reusable module.',
            'reusable_module_interest' => '1',
            'mobile_relevance' => 'future_mobile_companion',
        ])
        ->assertRedirect();

    expect(CustomModuleRequest::query()->where('tenant_id', (int) $tenant->id)->count())->toBe(1)
        ->and(TenantModuleEntitlement::query()->where('tenant_id', (int) $tenant->id)->exists())->toBeFalse()
        ->and(config('commercial.billing_readiness.checkout_active'))->toBeFalse();
});

test('shopify embedded app store keeps checkout inactive for app store readiness lane', function (): void {
    $tenant = billingSafetyTenant('shopify-billing-lane');
    configureEmbeddedRetailStore((int) $tenant->id);

    $this->get(route('shopify.app.store', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Checkout not active here')
        ->assertSeeText('Pricing: Add-on pricing label only; checkout is not active here')
        ->assertDontSeeText('Pay now')
        ->assertDontSeeText('Subscribe now');
});
