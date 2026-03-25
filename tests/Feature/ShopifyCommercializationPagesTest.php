<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;

beforeEach(function () {
    $this->withoutVite();
});

test('promo page renders config-driven headline and pricing content', function () {
    config()->set('product_surfaces.promo.headline', 'Testable Platform Headline');
    config()->set('product_surfaces.plans.cards.shopify_proof_of_concept.price_display', 'From $777/mo');

    $this->get(route('platform.promo'))
        ->assertOk()
        ->assertSeeText('Testable Platform Headline')
        ->assertSeeText('From $777/mo')
        ->assertSeeText('Install on Shopify');
});

test('contact placeholder page renders configured channels', function () {
    $this->get(route('platform.contact'))
        ->assertOk()
        ->assertSeeText('Contact Fire Forge Tech')
        ->assertSee('mailto:sales@fireforgetech.com?subject=Platform%20Demo%20Request', false)
        ->assertSeeText('Back to Product Overview');
});

test('embedded start-here page renders onboarding checklist surface', function () {
    configureEmbeddedRetailStore();

    $response = $this->get(route('shopify.app.start', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Start Here')
        ->assertSeeText('Setup Checklist')
        ->assertSee('data-onboarding-surface="true"', false)
        ->assertSee('data-module-checklist="true"', false)
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
        'addon_key' => 'integrations_pack',
        'enabled' => true,
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $response = $this->get(route('shopify.app.plans', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Current Access Profile')
        ->assertSeeText('Direct Starter')
        ->assertSeeText('Add-ons')
        ->assertSeeText('Integrations Pack')
        ->assertSeeText('Locked Modules')
        ->assertViewHas('pageSubnav', function (array $subnav): bool {
            return collect($subnav)->contains(fn (array $item): bool => ($item['key'] ?? null) === 'plans' && ! empty($item['active']));
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
        ->assertSeeText('Source: CSV upload fallback')
        ->assertSeeText('Source: Built-in manual workflow')
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
        ->assertSeeText('Direct Starter')
        ->assertSeeInOrder([
            'data-integration-key="shopify_orders"',
            'data-integration-state="locked"',
        ], false)
        ->assertSeeInOrder([
            'data-integration-key="quickbooks"',
            'data-integration-state="coming_soon"',
        ], false)
        ->assertSee('href="/shopify/app/plans"', false)
        ->assertSee('data-integration-cta-state="locked"', false)
        ->assertSeeText('Upgrade to unlock')
        ->assertSeeText('Shopify connector guidance is available under the current proof-of-concept access profile.')
        ->assertSee('data-integration-card-status="shopify_orders"', false)
        ->assertSee('data-integration-card-source="shopify_orders"', false)
        ->assertSeeText('Source: Plan entitlement')
        ->assertSee('data-integration-cta-state="coming_soon"', false)
        ->assertSeeText('Coming soon')
        ->assertSeeText('QuickBooks is currently a roadmap-visible placeholder.')
        ->assertSee('data-integration-card-status="quickbooks"', false)
        ->assertSee('data-integration-drawer-status="quickbooks"', false)
        ->assertSeeText('Source: Roadmap placeholder');
});

test('dashboard now exposes overview start-here and plans subnav links', function () {
    configureEmbeddedRetailStore();

    $response = $this->get(route('home', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Rewards performance snapshot')
        ->assertSeeText('Start Here')
        ->assertSeeText('Plans & Add-ons')
        ->assertViewHas('pageSubnav', function (array $subnav): bool {
            $keys = collect($subnav)->pluck('key')->values()->all();

            return $keys === ['overview', 'start', 'plans', 'integrations'];
        });
});
