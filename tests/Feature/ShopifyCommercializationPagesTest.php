<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Models\TenantCommercialOverride;
use App\Models\TenantModuleAccessRequest;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;

beforeEach(function () {
    $this->withoutVite();
});

test('promo page renders config-driven headline and pricing content', function () {
    config()->set('product_surfaces.plans.cards.starter.price_display', 'From $777/mo');

    $this->get(route('platform.promo'))
        ->assertOk()
        ->assertSee('data-premium-motion="public"', false)
        ->assertSee('id="intro-logo"', false)
        ->assertSee('id="site-ambient"', false)
        ->assertSeeText('Customers, shipping, and wholesale in one place.')
        ->assertSeeText('From $777/mo')
        ->assertSeeText('Install on Shopify');
});

test('contact placeholder page renders configured channels', function () {
    $this->get(route('platform.contact'))
        ->assertOk()
        ->assertSee('data-premium-motion="public"', false)
        ->assertSeeText('Contact Forestry Backstage')
        ->assertSee('mailto:sales@theeverbranch.com?subject=Platform%20Demo%20Request', false)
        ->assertSeeText('Back to homepage');
});

test('embedded start-here page renders onboarding checklist surface', function () {
    configureEmbeddedRetailStore();

    $response = $this->get(route('shopify.app.start', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Start Here')
        ->assertSeeText('Setup Checklist')
        ->assertSeeText('Customer import status')
        ->assertSeeText('Unlock Next')
        ->assertSee('data-onboarding-surface="true"', false)
        ->assertSee('data-module-checklist="true"', false)
        ->assertViewHas('onboardingPayload', function ($payload): bool {
            return is_array($payload)
                && is_array($payload['onboarding'] ?? null)
                && array_key_exists('recommended_phase', (array) ($payload['onboarding'] ?? []));
        })
        ->assertViewHas('pageSubnav', function (array $subnav): bool {
            return collect($subnav)->contains(fn (array $item): bool => ($item['key'] ?? null) === 'start' && ! empty($item['active']));
        });
});

test('embedded plans page renders tenant-aware plan and addon state', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Direct Services Co',
        'slug' => 'direct-services-co',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'direct_starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    TenantAccessAddon::query()->create([
        'tenant_id' => $tenant->id,
        'addon_key' => 'sms',
        'enabled' => true,
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $response = $this->get(route('shopify.app.plans', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Current Plan')
        ->assertSeeText('Starter')
        ->assertSeeText('Add-ons')
        ->assertSeeText('SMS')
        ->assertSeeText('Unlock Next')
        ->assertSeeText('Upgrade Opportunities')
        ->assertViewHas('pageSubnav', function (array $subnav): bool {
            return collect($subnav)->contains(fn (array $item): bool => ($item['key'] ?? null) === 'plans' && ! empty($item['active']));
        });
});

test('embedded app store page renders safe visible modules and hides internal-only catalog entries', function () {
    $tenant = Tenant::query()->create([
        'name' => 'App Store Tenant',
        'slug' => 'app-store-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.store', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('App Store')
        ->assertSeeText('SMS')
        ->assertSeeText('Add module')
        ->assertDontSeeText('Square')
        ->assertDontSeeText('Future Niche Modules')
        ->assertViewHas('pageSubnav', function (array $subnav): bool {
            return collect($subnav)->contains(fn (array $item): bool => ($item['key'] ?? null) === 'store' && ! empty($item['active']));
        });
});

test('embedded app store activation writes tenant module entitlements', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Activation Tenant',
        'slug' => 'activation-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->post(route('shopify.app.store.activate', ['moduleKey' => 'sms']) . '?' . http_build_query(retailEmbeddedSignedQuery()), [
        'context_token' => retailEmbeddedContextToken(),
    ])
        ->assertRedirect();

    expect(TenantModuleEntitlement::query()
        ->where('tenant_id', $tenant->id)
        ->where('module_key', 'sms')
        ->value('enabled_status'))->toBe('enabled');
});

test('embedded app store request path records a pending access request lifecycle row', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Embedded Request Tenant',
        'slug' => 'embedded-request-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->post(route('shopify.app.store.request', ['moduleKey' => 'diagnostics_advanced']) . '?' . http_build_query(retailEmbeddedSignedQuery()), [
        'context_token' => retailEmbeddedContextToken(),
    ])
        ->assertRedirect();

    expect(TenantModuleAccessRequest::query()
        ->where('tenant_id', $tenant->id)
        ->where('module_key', 'diagnostics_advanced')
        ->value('status'))->toBe('pending');
});

test('embedded app store suppresses shopify-only safe modules for direct-mode tenants', function () {
    config()->set('module_catalog.modules.sms.channels', ['shopify']);

    $tenant = Tenant::query()->create([
        'name' => 'Direct Mode Store Tenant',
        'slug' => 'direct-mode-store-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'direct_starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.store', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertDontSeeText('SMS');
});

test('embedded app store activation requires authenticated embedded api context', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Missing Api Auth Tenant',
        'slug' => 'missing-api-auth-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->postJson(route('shopify.app.store.activate', ['moduleKey' => 'sms']), retailEmbeddedSignedQuery())
        ->assertStatus(401)
        ->assertJsonPath('status', 'missing_api_auth');
});

test('embedded app store activation rejects direct signed-query posts without a page context token', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Missing Context Token Tenant',
        'slug' => 'missing-context-token-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->post(route('shopify.app.store.activate', ['moduleKey' => 'sms']) . '?' . http_build_query(retailEmbeddedSignedQuery()))
        ->assertStatus(401)
        ->assertJsonPath('status', 'missing_context_token');
});

test('embedded app store blocks shopify only activation for direct mode tenants', function () {
    config()->set('module_catalog.modules.sms.channels', ['shopify']);

    $tenant = Tenant::query()->create([
        'name' => 'Direct Embedded Activation Tenant',
        'slug' => 'direct-embedded-activation-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'direct_starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $response = $this->post(route('shopify.app.store.activate', ['moduleKey' => 'sms']) . '?' . http_build_query(retailEmbeddedSignedQuery()), [
        'context_token' => retailEmbeddedContextToken(),
    ]);

    $response->assertRedirect();
    expect(TenantModuleEntitlement::query()
        ->where('tenant_id', $tenant->id)
        ->where('module_key', 'sms')
        ->exists())->toBeFalse();
});

test('assignment and label overrides propagate across start plans and integrations pages', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Propagation Tenant',
        'slug' => 'propagation-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'pro',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    TenantCommercialOverride::query()->create([
        'tenant_id' => $tenant->id,
        'template_key' => 'law',
        'display_labels' => [
            'rewards' => 'Forest Credits',
        ],
    ]);

    TenantModuleState::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'rewards',
        'enabled_override' => false,
        'setup_status' => 'configured',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.start', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Customer import status');

    $this->get(route('shopify.app.plans', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Current Plan')
        ->assertSeeText('Pro');

    $this->get(route('shopify.app.integrations', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Connect customer data sources so import and customer workflows stay reliable.')
        ->assertSeeText('Import')
        ->assertSee('data-integrations-surface="true"', false);
});

test('commercialization pages use predictable entitlements fallback when no template override exists', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Fallback Tenant',
        'slug' => 'fallback-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.start', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Customer import status')
        ->assertViewHas('onboardingPayload', function (array $payload): bool {
            return data_get($payload, 'commercial_context.label_source') === 'global_fallback'
                && data_get($payload, 'commercial_context.labels.rewards') === 'Rewards';
        });

    $this->get(route('shopify.app.plans', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Growth')
        ->assertViewHas('plansPayload', function (array $payload): bool {
            return data_get($payload, 'commercial_context.label_source') === 'global_fallback';
        });

    $this->get(route('shopify.app.integrations', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Integrations')
        ->assertSeeText('Import')
        ->assertViewHas('integrationsPayload', function (array $payload): bool {
            return data_get($payload, 'commercial_context.label_source') === 'global_fallback';
        });
});

test('commercialization pages ignore malformed label overrides and keep deterministic label source fallback', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Malformed Label Tenant',
        'slug' => 'malformed-label-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    TenantCommercialOverride::query()->create([
        'tenant_id' => $tenant->id,
        'template_key' => 'law',
        // Invalid shape for label overrides: should not be treated as tenant override.
        'display_labels' => ['Forest Credits'],
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.start', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Customer import status')
        ->assertViewHas('onboardingPayload', function (array $payload): bool {
            return data_get($payload, 'commercial_context.label_source') === 'template_default'
                && data_get($payload, 'commercial_context.labels.rewards') === 'Client Credits';
        });

    $this->get(route('shopify.app.plans', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertViewHas('plansPayload', function (array $payload): bool {
            return data_get($payload, 'commercial_context.label_source') === 'template_default';
        });

    $this->get(route('shopify.app.integrations', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Integrations')
        ->assertSeeText('Import')
        ->assertViewHas('integrationsPayload', function (array $payload): bool {
            return data_get($payload, 'commercial_context.label_source') === 'template_default';
        });

    TenantCommercialOverride::query()
        ->where('tenant_id', $tenant->id)
        ->firstOrFail()
        ->update([
            'template_key' => null,
            'display_labels' => ['Forest Credits'],
        ]);

    $this->get(route('shopify.app.start', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Customer import status')
        ->assertViewHas('onboardingPayload', function (array $payload): bool {
            return data_get($payload, 'commercial_context.label_source') === 'global_fallback'
                && data_get($payload, 'commercial_context.labels.rewards') === 'Rewards';
        });

    $this->get(route('shopify.app.plans', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Current Plan');

    $this->get(route('shopify.app.integrations', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Integrations')
        ->assertSeeText('Import')
        ->assertViewHas('integrationsPayload', function (array $payload): bool {
            return data_get($payload, 'commercial_context.label_source') === 'global_fallback';
        });
});

test('embedded integrations page renders placeholder-first cards and categories', function () {
    configureEmbeddedRetailStore();

    $response = $this->get(route('shopify.app.integrations', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Integrations')
        ->assertSee('data-integrations-surface="true"', false)
        ->assertSee('data-integration-drawer="true"', false)
        ->assertSee('data-integration-category="commerce"', false)
        ->assertSee('data-integration-key="shopify_orders"', false)
        ->assertSee('data-integration-key="csv_import"', false)
        ->assertSee('data-integration-setup-template="shopify_orders"', false)
        ->assertSee('data-integration-setup-steps="shopify_orders"', false)
        ->assertSee('data-integration-required-fields="shopify_orders"', false)
        ->assertSee('data-integration-fallback-options="shopify_orders"', false)
        ->assertSee('data-integration-card-status="csv_import"', false)
        ->assertSee('data-integration-card-source="csv_import"', false)
        ->assertSee('data-integration-card-setup-mode="csv_import"', false)
        ->assertSee('data-integration-drawer-status="csv_import"', false)
        ->assertSeeInOrder([
            'data-integration-key="csv_import"',
            'data-integration-state="setup_needed"',
        ], false)
        ->assertSeeInOrder([
            'data-integration-key="manual_entry"',
            'data-integration-state="connected"',
        ], false)
        ->assertSee('data-integration-drawer-status="manual_entry"', false)
        ->assertSeeText('Data path: CSV upload fallback')
        ->assertSeeText('Data path: Built-in manual workflow')
        ->assertSeeText('You can still use this system without this integration.')
        ->assertSeeText('Continue setup')
        ->assertViewHas('pageSubnav', function (array $subnav): bool {
            return collect($subnav)->contains(fn (array $item): bool => ($item['key'] ?? null) === 'integrations' && ! empty($item['active']));
        });
});

test('embedded integrations page derives locked and coming soon states from entitlement context', function () {
    $tenant = Tenant::query()->create([
        'name' => 'No Shopify Direct Tenant',
        'slug' => 'no-shopify-direct-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'direct_starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $response = $this->get(route('shopify.app.integrations', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Starter')
        ->assertSeeInOrder([
            'data-integration-key="sms_gateway"',
            'data-integration-state="locked"',
        ], false)
        ->assertSeeInOrder([
            'data-integration-key="quickbooks"',
            'data-integration-state="coming_soon"',
        ], false)
        ->assertSee('href="/shopify/app/plans?', false)
        ->assertSee('data-integration-cta-state="locked"', false)
        ->assertSeeText('Upgrade to unlock')
        ->assertSeeText('SMS access follows tenant entitlements and provider readiness configuration.')
        ->assertSee('data-integration-card-status="sms_gateway"', false)
        ->assertSee('data-integration-card-source="sms_gateway"', false)
        ->assertSeeText('Data path: Plan entitlement')
        ->assertSee('data-integration-cta-state="coming_soon"', false)
        ->assertSeeText('Coming soon')
        ->assertSeeText('QuickBooks is currently a roadmap-visible placeholder.')
        ->assertSee('data-integration-card-status="quickbooks"', false)
        ->assertSee('data-integration-drawer-status="quickbooks"', false)
        ->assertSeeText('Data path: Roadmap placeholder');
});

test('dashboard now exposes overview start-here and plans subnav links', function () {
    configureEmbeddedRetailStore();

    $response = $this->get(route('shopify.app.start', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Start Here')
        ->assertSeeText('Plans & Add-ons')
        ->assertViewHas('pageSubnav', function (array $subnav): bool {
            $keys = collect($subnav)->pluck('key')->values()->all();

            return $keys === ['overview', 'start', 'plans', 'store', 'integrations'];
        });
});

test('public catalog feed only exposes safe public modules', function () {
    $this->get(route('platform.catalog.feed'))
        ->assertOk()
        ->assertJsonStructure([
            'generated_at',
            'positioning' => ['headline', 'themes', 'supported_channel_types'],
            'plans',
            'modules' => [
                '*' => ['key', 'display_name', 'description', 'status', 'billing_mode', 'channels', 'included_in_plans', 'market_state', 'cta_routing'],
            ],
        ])
        ->assertJsonMissing(['key' => 'square'])
        ->assertJsonMissing(['key' => 'future_niche_modules'])
        ->assertJsonFragment(['key' => 'sms'])
        ->assertJsonFragment(['key' => 'bulk_email_marketing']);
});
