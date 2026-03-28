<?php

use App\Models\LandlordCatalogEntry;
use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Models\TenantCommercialOverride;
use App\Models\TenantModuleState;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

function commercialLandlordHost(): string
{
    $host = parse_url(route('landlord.commercial.index'), PHP_URL_HOST);

    return is_string($host) && $host !== '' ? strtolower($host) : 'forestrybackstage.com';
}

beforeEach(function (): void {
    $host = commercialLandlordHost();
    config()->set('tenancy.landlord.primary_host', $host);
    config()->set('tenancy.landlord.hosts', [$host]);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
});

test('landlord commercial page is host-locked and available to landlord operators', function (): void {
    $host = commercialLandlordHost();
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://{$host}/landlord/commercial")
        ->assertOk()
        ->assertSeeText('Commercial Control Center');
});

test('landlord commercial actions can assign plans and tenant overrides', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
    ]);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/plan", [
            'plan_key' => 'growth',
            'operating_mode' => 'shopify',
        ])
        ->assertRedirect();

    expect(TenantAccessProfile::query()->where('tenant_id', $tenant->id)->value('plan_key'))->toBe('growth');

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/override", [
            'template_key' => 'generic',
            'store_channel_allowance' => 2,
            'display_labels_json' => '{"rewards":"Rewards","birthdays":"Lifecycle"}',
        ])
        ->assertRedirect();

    $override = TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->first();
    expect($override)->not->toBeNull()
        ->and((string) $override?->template_key)->toBe('generic')
        ->and((int) ($override?->store_channel_allowance ?? 0))->toBe(2)
        ->and((string) data_get($override?->display_labels ?? [], 'rewards'))->toBe('Rewards');

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/modules/rewards", [
            'enabled_override' => 'enabled',
            'setup_status' => 'configured',
        ])
        ->assertRedirect();

    expect(TenantModuleState::query()
        ->where('tenant_id', $tenant->id)
        ->where('module_key', 'rewards')
        ->value('enabled_override'))->toBeTrue();

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/addons/sms", [
            'enabled' => true,
        ])
        ->assertRedirect();

    expect(TenantAccessAddon::query()
        ->where('tenant_id', $tenant->id)
        ->where('addon_key', 'sms')
        ->value('enabled'))->toBeTrue();
});

test('landlord commercial assignment supports all canonical template keys', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Template Tenant',
        'slug' => 'template-tenant',
    ]);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    foreach (['candle', 'law', 'landscaping', 'apparel', 'generic'] as $templateKey) {
        $this->actingAs($user)
            ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/override", [
                'template_key' => $templateKey,
            ])
            ->assertRedirect();

        expect(TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->value('template_key'))
            ->toBe($templateKey);
    }
});

test('landlord commercial assignment supports starter growth and pro tiers', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Plan Tier Tenant',
        'slug' => 'plan-tier-tenant',
    ]);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    foreach (['starter', 'growth', 'pro'] as $planKey) {
        $this->actingAs($user)
            ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/plan", [
                'plan_key' => $planKey,
                'operating_mode' => 'shopify',
            ])
            ->assertRedirect();

        expect(TenantAccessProfile::query()->where('tenant_id', $tenant->id)->value('plan_key'))
            ->toBe($planKey);
    }
});

test('landlord commercial validation blocks unknown assignment keys', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Validation Tenant',
        'slug' => 'validation-tenant',
    ]);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/plan", [
            'plan_key' => 'enterprise',
            'operating_mode' => 'shopify',
        ])
        ->assertSessionHasErrors('plan_key');

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/override", [
            'template_key' => 'unknown-template',
        ])
        ->assertSessionHasErrors('template_key');

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/modules/not_real", [
            'enabled_override' => 'enabled',
            'setup_status' => 'configured',
        ])
        ->assertSessionHasErrors('module_key');

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/addons/not_real", [
            'enabled' => true,
        ])
        ->assertSessionHasErrors('addon_key');
});

test('landlord commercial page keeps billing lifecycle actions disabled', function (): void {
    $host = commercialLandlordHost();
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://{$host}/landlord/commercial")
        ->assertOk()
        ->assertSeeText('Configuration is ready for future billing mapping, but billing lifecycle is inactive in this phase.')
        ->assertSeeText('Only one guarded live subscription create/sync action is available (landlord-triggered, prerequisite-gated).')
        ->assertSeeText('Broad subscription update/cancel automation is still disabled.')
        ->assertSeeText('Checkout remains disabled and broad lifecycle writes remain intentionally inactive in this phase.')
        ->assertSeeText('customer reference sync, subscription-prep metadata sync, and narrow live subscription create/sync')
        ->assertSeeText('No tenants are available yet. Create or sync a tenant before running commercial assignment UAT.')
        ->assertSeeText('docs/operations/pre-billing-readiness-gate.md')
        ->assertSeeText('docs/operations/billing-activation-checklist.md')
        ->assertSeeText('stripe.customer_reference')
        ->assertSeeText('stripe.subscription_reference');

    expect((bool) config('commercial.billing_readiness.checkout_active'))->toBeFalse();
    expect((bool) config('commercial.billing_readiness.lifecycle_mutations_enabled'))->toBeFalse();
});

test('landlord catalog upsert writes configuration entries', function (): void {
    $host = commercialLandlordHost();
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/commercial/catalog/plan/upsert", [
            'entry_key' => 'pro',
            'name' => 'Pro',
            'position' => 30,
            'recurring_price_cents' => 45900,
            'setup_price_cents' => 10900,
            'payload_json' => '{"modules":["wishlist","diagnostics_advanced"]}',
        ])
        ->assertRedirect();

    $row = LandlordCatalogEntry::query()
        ->where('entry_type', LandlordCatalogEntry::TYPE_PLAN)
        ->where('entry_key', 'pro')
        ->first();

    expect($row)->not->toBeNull()
        ->and((int) ($row?->recurring_price_cents ?? 0))->toBe(45900)
        ->and((string) data_get($row?->payload ?? [], 'modules.0'))->toBe('wishlist');
});

test('landlord commercial page shows effective label source and missing-template fallback notice', function (): void {
    $host = commercialLandlordHost();
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $tenantWithOverride = Tenant::query()->create([
        'name' => 'Labels Override Tenant',
        'slug' => 'labels-override-tenant',
    ]);
    TenantCommercialOverride::query()->create([
        'tenant_id' => $tenantWithOverride->id,
        'template_key' => 'law',
        'display_labels' => [
            'rewards' => 'Forest Credits',
        ],
    ]);

    $tenantWithMissingTemplate = Tenant::query()->create([
        'name' => 'Missing Template Tenant',
        'slug' => 'missing-template-tenant',
    ]);
    TenantCommercialOverride::query()->create([
        'tenant_id' => $tenantWithMissingTemplate->id,
        'template_key' => 'missing_template_key',
    ]);

    $this->actingAs($user)
        ->get("http://{$host}/landlord/commercial")
        ->assertOk()
        ->assertSeeText('labels: tenant override')
        ->assertSeeText('Effective labels (read-only)')
        ->assertSeeText('Assigned template key is missing from the catalog. Commercialization surfaces will fall back to entitlement defaults.');
});

test('billing readiness config includes canonical stripe-first mapping for tiers and add-ons', function (): void {
    expect(config('commercial.billing_readiness.provider_priority'))->toBe(['stripe', 'braintree'])
        ->and(config('commercial.stripe_mapping.status'))->toBe('configuration_only')
        ->and(config('commercial.stripe_mapping.tiers.starter.recurring_price_lookup_key'))->toBe('tier_starter_monthly')
        ->and(config('commercial.stripe_mapping.tiers.growth.recurring_price_lookup_key'))->toBe('tier_growth_monthly')
        ->and(config('commercial.stripe_mapping.tiers.pro.recurring_price_lookup_key'))->toBe('tier_pro_monthly')
        ->and(config('commercial.stripe_mapping.addons.referrals.recurring_price_lookup_key'))->toBe('addon_referrals_monthly')
        ->and(config('commercial.stripe_mapping.addons.sms.recurring_price_lookup_key'))->toBe('addon_sms_monthly')
        ->and(config('commercial.stripe_mapping.addons.additional_channels.recurring_price_lookup_key'))->toBe('addon_additional_channels_monthly')
        ->and(config('commercial.stripe_mapping.addons.bulk_email_marketing.recurring_price_lookup_key'))->toBe('addon_bulk_email_marketing_monthly')
        ->and(config('commercial.stripe_mapping.addons.future_niche_modules.recurring_price_lookup_key'))->toBe('addon_future_niche_modules_monthly')
        ->and(config('commercial.stripe_mapping.support_tiers.priority_support.recurring_price_lookup_key'))->toBe('support_priority_monthly');
});

test('tenant billing readiness highlights missing required mapping placeholders', function (): void {
    $host = commercialLandlordHost();
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $tenant = Tenant::query()->create([
        'name' => 'Missing Billing Mapping Tenant',
        'slug' => 'missing-billing-mapping-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    $this->actingAs($user)
        ->get("http://{$host}/landlord/commercial")
        ->assertOk()
        ->assertSeeText('Billing readiness:')
        ->assertSeeText('not ready for activation prep')
        ->assertSeeText('Missing billing requirements: Tenant billing mapping missing: stripe.customer_reference; Tenant billing mapping missing: stripe.subscription_reference.');
});

test('tenant billing readiness reports ready for activation prep when required placeholders exist', function (): void {
    $host = commercialLandlordHost();
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $tenant = Tenant::query()->create([
        'name' => 'Billing Ready Tenant',
        'slug' => 'billing-ready-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    TenantCommercialOverride::query()->create([
        'tenant_id' => $tenant->id,
        'billing_mapping' => [
            'stripe' => [
                'customer_reference' => 'cus_test_123',
                'subscription_reference' => 'sub_test_123',
            ],
        ],
    ]);

    $this->actingAs($user)
        ->get("http://{$host}/landlord/commercial")
        ->assertOk()
        ->assertSeeText('Billing readiness:')
        ->assertSeeText('ready for activation prep')
        ->assertDontSeeText('Missing billing requirements:');
});

test('guarded stripe customer sync is landlord-only', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Access Guard Tenant',
        'slug' => 'access-guard-tenant',
    ]);

    $user = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/customer-sync")
        ->assertForbidden();
});

test('guarded stripe subscription prep is landlord-only', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Subscription Prep Access Guard Tenant',
        'slug' => 'subscription-prep-access-guard-tenant',
    ]);

    $user = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/subscription-prep")
        ->assertForbidden();
});

test('guarded stripe live subscription sync is landlord-only', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Live Subscription Access Guard Tenant',
        'slug' => 'live-subscription-access-guard-tenant',
    ]);

    $user = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/subscription-live-sync")
        ->assertForbidden();
});

test('guarded stripe customer sync blocks when stripe configuration is incomplete', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Stripe Missing Secret Tenant',
        'slug' => 'stripe-missing-secret-tenant',
    ]);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    config()->set('services.stripe.secret', '');
    config()->set('services.stripe.api_base', 'https://api.stripe.com');
    config()->set('commercial.billing_readiness.guarded_actions.stripe_customer_sync.enabled', true);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/customer-sync")
        ->assertRedirect()
        ->assertSessionHas('status_error');

    $override = TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->first();
    expect($override)->not->toBeNull()
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_customer_sync.status'))
            ->toBe('blocked')
        ->and((string) data_get($override?->billing_mapping ?? [], 'stripe.customer_reference', ''))
            ->toBe('');
});

test('guarded stripe customer sync blocks when stripe secret format is invalid', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Stripe Invalid Secret Format Tenant',
        'slug' => 'stripe-invalid-secret-format-tenant',
    ]);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    config()->set('services.stripe.secret', 'invalid_secret_value');
    config()->set('services.stripe.api_base', 'https://api.stripe.com');
    config()->set('commercial.billing_readiness.guarded_actions.stripe_customer_sync.enabled', true);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/customer-sync")
        ->assertRedirect()
        ->assertSessionHas('status_error');

    $override = TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->first();
    expect($override)->not->toBeNull()
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_customer_sync.status'))
            ->toBe('blocked')
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_customer_sync.message'))
            ->toContain('should start with `sk_`');
});

test('guarded stripe customer sync blocks when stripe api base is non-https remote url', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Stripe Invalid API Base Tenant',
        'slug' => 'stripe-invalid-api-base-tenant',
    ]);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    config()->set('services.stripe.secret', 'sk_test_guarded');
    config()->set('services.stripe.api_base', 'http://api.stripe.com');
    config()->set('commercial.billing_readiness.guarded_actions.stripe_customer_sync.enabled', true);

    Http::fake();

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/customer-sync")
        ->assertRedirect()
        ->assertSessionHas('status_error');

    Http::assertNothingSent();

    $override = TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->first();
    expect($override)->not->toBeNull()
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_customer_sync.status'))
            ->toBe('blocked')
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_customer_sync.message'))
            ->toContain('Use HTTPS for remote endpoints');
});

test('guarded stripe customer sync creates tenant stripe customer reference', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Stripe Create Tenant',
        'slug' => 'stripe-create-tenant',
    ]);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    config()->set('services.stripe.secret', 'sk_test_guarded');
    config()->set('services.stripe.api_base', 'https://api.stripe.com');
    config()->set('services.stripe.timeout', 20);
    config()->set('commercial.billing_readiness.guarded_actions.stripe_customer_sync.enabled', true);

    Http::fake([
        'https://api.stripe.com/v1/customers' => Http::response([
            'id' => 'cus_test_guarded_create',
            'object' => 'customer',
        ], 200),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/customer-sync")
        ->assertRedirect()
        ->assertSessionHas('status');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.stripe.com/v1/customers'
            && str_contains((string) $request->header('Idempotency-Key')[0] ?? '', 'tenant-');
    });

    $override = TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->first();
    expect($override)->not->toBeNull()
        ->and((string) data_get($override?->billing_mapping ?? [], 'stripe.customer_reference'))
            ->toBe('cus_test_guarded_create')
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_customer_sync.status'))
            ->toBe('succeeded')
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_customer_sync.mode'))
            ->toBe('create');
});

test('guarded stripe customer sync updates existing tenant stripe customer reference without passive activation', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Stripe Update Tenant',
        'slug' => 'stripe-update-tenant',
    ]);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    TenantCommercialOverride::query()->create([
        'tenant_id' => $tenant->id,
        'billing_mapping' => [
            'stripe' => [
                'customer_reference' => 'cus_existing_guarded',
            ],
        ],
    ]);

    config()->set('services.stripe.secret', 'sk_test_guarded');
    config()->set('services.stripe.api_base', 'https://api.stripe.com');
    config()->set('services.stripe.timeout', 20);
    config()->set('commercial.billing_readiness.guarded_actions.stripe_customer_sync.enabled', true);

    Http::fake([
        'https://api.stripe.com/v1/customers/cus_existing_guarded' => Http::response([
            'id' => 'cus_existing_guarded',
            'object' => 'customer',
        ], 200),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/customer-sync")
        ->assertRedirect()
        ->assertSessionHas('status');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.stripe.com/v1/customers/cus_existing_guarded');

    $override = TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->first();
    expect($override)->not->toBeNull()
        ->and((string) data_get($override?->billing_mapping ?? [], 'stripe.customer_reference'))
            ->toBe('cus_existing_guarded')
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_customer_sync.mode'))
            ->toBe('update');

    Http::fake();

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/plan", [
            'plan_key' => 'growth',
            'operating_mode' => 'shopify',
        ])
        ->assertRedirect();

    Http::assertNothingSent();
});

test('guarded stripe subscription prep blocks when stripe customer reference is missing', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Subscription Prep Missing Customer Tenant',
        'slug' => 'subscription-prep-missing-customer-tenant',
    ]);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    config()->set('commercial.billing_readiness.guarded_actions.stripe_subscription_prep.enabled', true);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/subscription-prep")
        ->assertRedirect()
        ->assertSessionHas('status_error');

    $override = TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->first();
    expect($override)->not->toBeNull()
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_subscription_prep.status'))
            ->toBe('blocked')
        ->and((string) data_get($override?->billing_mapping ?? [], 'stripe.subscription_prep_hash', ''))
            ->toBe('');
});

test('guarded stripe subscription prep syncs canonical mapping metadata idempotently', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Subscription Prep Sync Tenant',
        'slug' => 'subscription-prep-sync-tenant',
    ]);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    TenantAccessAddon::query()->create([
        'tenant_id' => $tenant->id,
        'addon_key' => 'sms',
        'enabled' => true,
        'source' => 'test',
    ]);

    TenantCommercialOverride::query()->create([
        'tenant_id' => $tenant->id,
        'billing_mapping' => [
            'stripe' => [
                'customer_reference' => 'cus_prep_sync_123',
            ],
        ],
    ]);

    config()->set('commercial.billing_readiness.guarded_actions.stripe_subscription_prep.enabled', true);
    Http::fake();

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/subscription-prep")
        ->assertRedirect()
        ->assertSessionHas('status');

    Http::assertNothingSent();

    $override = TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->first();
    $firstHash = trim((string) data_get($override?->billing_mapping ?? [], 'stripe.subscription_prep_hash', ''));
    expect($override)->not->toBeNull()
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_subscription_prep.status'))
            ->toBe('succeeded')
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_subscription_prep.mode'))
            ->toBe('sync')
        ->and((string) data_get($override?->billing_mapping ?? [], 'stripe.subscription_prep_candidate.plan.key'))
            ->toBe('growth')
        ->and((string) data_get($override?->billing_mapping ?? [], 'stripe.subscription_prep_candidate.addons.sms.recurring_price_lookup_key'))
            ->toBe('addon_sms_monthly')
        ->and((string) data_get($override?->billing_mapping ?? [], 'stripe.subscription_prep_candidate.customer_reference'))
            ->toBe('cus_prep_sync_123')
        ->and((string) data_get($override?->billing_mapping ?? [], 'stripe.subscription_reference', ''))
            ->toBe('');

    expect($firstHash)->not->toBe('');

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/subscription-prep")
        ->assertRedirect()
        ->assertSessionHas('status');

    $override = TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->first();
    expect((string) data_get($override?->billing_mapping ?? [], 'stripe.subscription_prep_hash'))
        ->toBe($firstHash)
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_subscription_prep.mode'))
        ->toBe('noop');

    $this->actingAs($user)
        ->get("http://{$host}/landlord/commercial")
        ->assertOk()
        ->assertSeeText('Guarded Stripe Subscription Prep (Landlord-Only)')
        ->assertSeeText('Guarded Stripe Live Subscription Create/Sync (Landlord-Only)')
        ->assertSeeText($firstHash);
});

test('guarded stripe live subscription sync blocks when prep prerequisites are missing', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Live Sync Blocked Tenant',
        'slug' => 'live-sync-blocked-tenant',
    ]);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    config()->set('services.stripe.secret', 'sk_test_guarded');
    config()->set('services.stripe.api_base', 'https://api.stripe.com');
    config()->set('commercial.billing_readiness.guarded_actions.stripe_live_subscription_sync.enabled', true);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/subscription-live-sync")
        ->assertRedirect()
        ->assertSessionHas('status_error');

    $override = TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->first();
    expect($override)->not->toBeNull()
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_live_subscription_sync.status'))
            ->toBe('blocked')
        ->and((string) data_get($override?->billing_mapping ?? [], 'stripe.subscription_reference', ''))
            ->toBe('');
});

test('guarded stripe live subscription sync exercises create path with explicit failure state when sync cannot complete', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Live Sync Create Tenant',
        'slug' => 'live-sync-create-tenant',
    ]);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    TenantAccessAddon::query()->create([
        'tenant_id' => $tenant->id,
        'addon_key' => 'sms',
        'enabled' => true,
        'source' => 'test',
    ]);

    TenantCommercialOverride::query()->create([
        'tenant_id' => $tenant->id,
        'billing_mapping' => [
            'stripe' => [
                'customer_reference' => 'cus_live_create_123',
            ],
        ],
    ]);

    config()->set('services.stripe.secret', 'sk_test_guarded');
    config()->set('services.stripe.api_base', 'https://api.stripe.com');
    config()->set('services.stripe.timeout', 20);
    config()->set('commercial.billing_readiness.guarded_actions.stripe_subscription_prep.enabled', true);
    config()->set('commercial.billing_readiness.guarded_actions.stripe_live_subscription_sync.enabled', true);

    Http::fake();

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/subscription-prep")
        ->assertRedirect()
        ->assertSessionHas('status');

    Http::assertNothingSent();

    Http::fake(function (HttpRequest $request) {
        if ($request->method() === 'POST') {
            return Http::response([
                'id' => 'sub_live_create_123',
                'object' => 'subscription',
                'status' => 'active',
                'customer' => 'cus_live_create_123',
            ], 200);
        }

        return Http::response([
            'object' => 'list',
            'data' => [
                ['id' => 'price_growth_monthly_1', 'lookup_key' => 'tier_growth_monthly'],
                ['id' => 'price_sms_monthly_1', 'lookup_key' => 'addon_sms_monthly'],
            ],
        ], 200);
    });

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/subscription-live-sync")
        ->assertRedirect()
        ->assertSessionHas('status_error');

    $override = TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->first();
    expect($override)->not->toBeNull()
        ->and((string) data_get($override?->billing_mapping ?? [], 'stripe.subscription_reference', ''))
            ->toBe('')
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_live_subscription_sync.status'))
            ->toBe('failed')
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_live_subscription_sync.mode'))
            ->toBe('create');
});

test('guarded stripe live subscription sync fails safely when recurring lookup keys are missing', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Live Sync Missing Lookup Keys Tenant',
        'slug' => 'live-sync-missing-lookup-keys-tenant',
    ]);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    TenantAccessAddon::query()->create([
        'tenant_id' => $tenant->id,
        'addon_key' => 'sms',
        'enabled' => true,
        'source' => 'test',
    ]);

    TenantCommercialOverride::query()->create([
        'tenant_id' => $tenant->id,
        'billing_mapping' => [
            'stripe' => [
                'customer_reference' => 'cus_live_missing_lookup_123',
            ],
        ],
    ]);

    config()->set('services.stripe.secret', 'sk_test_guarded');
    config()->set('services.stripe.api_base', 'https://api.stripe.com');
    config()->set('services.stripe.timeout', 20);
    config()->set('commercial.billing_readiness.guarded_actions.stripe_subscription_prep.enabled', true);
    config()->set('commercial.billing_readiness.guarded_actions.stripe_live_subscription_sync.enabled', true);

    Http::fake();

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/subscription-prep")
        ->assertRedirect()
        ->assertSessionHas('status');

    Http::assertNothingSent();

    Http::fake(function (HttpRequest $request) {
        if (str_contains($request->url(), '/v1/prices')) {
            return Http::response([
                'object' => 'list',
                'data' => [
                    ['id' => 'price_growth_monthly_1', 'lookup_key' => 'tier_growth_monthly'],
                ],
            ], 200);
        }

        if (str_contains($request->url(), '/v1/subscriptions')) {
            return Http::response([
                'id' => 'sub_should_not_be_created',
                'object' => 'subscription',
                'status' => 'active',
                'customer' => 'cus_live_missing_lookup_123',
            ], 200);
        }

        return Http::response([], 404);
    });

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/subscription-live-sync")
        ->assertRedirect()
        ->assertSessionHas('status_error');

    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/v1/prices'));
    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/v1/subscriptions'));

    $override = TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->first();
    expect($override)->not->toBeNull()
        ->and((string) data_get($override?->billing_mapping ?? [], 'stripe.subscription_reference', ''))
            ->toBe('')
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_live_subscription_sync.status'))
            ->toBe('failed')
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_live_subscription_sync.message'))
            ->toContain('Missing Stripe price lookup keys');
});

test('guarded stripe live subscription sync re-syncs existing reference and does not passively activate on plan assignment', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Live Sync Existing Tenant',
        'slug' => 'live-sync-existing-tenant',
    ]);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    TenantCommercialOverride::query()->create([
        'tenant_id' => $tenant->id,
        'billing_mapping' => [
            'stripe' => [
                'customer_reference' => 'cus_live_sync_123',
            ],
        ],
    ]);

    config()->set('services.stripe.secret', 'sk_test_guarded');
    config()->set('services.stripe.api_base', 'https://api.stripe.com');
    config()->set('services.stripe.timeout', 20);
    config()->set('commercial.billing_readiness.guarded_actions.stripe_subscription_prep.enabled', true);
    config()->set('commercial.billing_readiness.guarded_actions.stripe_live_subscription_sync.enabled', true);

    Http::fake();

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/subscription-prep")
        ->assertRedirect()
        ->assertSessionHas('status');

    Http::assertNothingSent();

    $override = TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->firstOrFail();
    $billingMapping = is_array($override->billing_mapping) ? $override->billing_mapping : [];
    data_set($billingMapping, 'stripe.subscription_reference', 'sub_existing_live_sync_123');
    $override->billing_mapping = $billingMapping;
    $override->save();

    Http::fake();

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/subscription-prep")
        ->assertRedirect()
        ->assertSessionHas('status');

    Http::assertNothingSent();

    Http::fake([
        'https://api.stripe.com/v1/subscriptions/sub_existing_live_sync_123' => Http::response([
            'id' => 'sub_existing_live_sync_123',
            'object' => 'subscription',
            'status' => 'active',
            'customer' => 'cus_live_sync_123',
        ], 200),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/billing/stripe/subscription-live-sync")
        ->assertRedirect()
        ->assertSessionHas('status');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.stripe.com/v1/subscriptions/sub_existing_live_sync_123');

    $override = TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->first();
    expect($override)->not->toBeNull()
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_live_subscription_sync.mode'))
            ->toBe('sync')
        ->and((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_live_subscription_sync.status'))
            ->toBe('succeeded')
        ->and((string) data_get($override?->billing_mapping ?? [], 'stripe.subscription_reference'))
            ->toBe('sub_existing_live_sync_123');

    Http::fake();

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/plan", [
            'plan_key' => 'pro',
            'operating_mode' => 'shopify',
        ])
        ->assertRedirect();

    Http::assertNothingSent();
});

test('landlord plan assignment does not passively trigger guarded stripe subscription prep', function (): void {
    $host = commercialLandlordHost();
    $tenant = Tenant::query()->create([
        'name' => 'Subscription Prep Passive Guard Tenant',
        'slug' => 'subscription-prep-passive-guard-tenant',
    ]);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    TenantCommercialOverride::query()->create([
        'tenant_id' => $tenant->id,
        'billing_mapping' => [
            'stripe' => [
                'customer_reference' => 'cus_no_passive_trigger',
            ],
        ],
    ]);

    config()->set('commercial.billing_readiness.guarded_actions.stripe_subscription_prep.enabled', true);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/commercial/plan", [
            'plan_key' => 'starter',
            'operating_mode' => 'shopify',
        ])
        ->assertRedirect();

    $override = TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->first();
    expect((string) data_get($override?->metadata ?? [], 'billing_guarded_actions.stripe_subscription_prep.status', ''))
        ->toBe('');
});
