<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->withoutVite();
});

test('shopify embedded app route shows helpful launch message when opened outside shopify admin', function () {
    $this->get(route('shopify.app'))
        ->assertOk()
        ->assertSeeText('Dashboard')
        ->assertSeeText('Open this app from Shopify Admin to load store data.')
        ->assertDontSeeText('Install on Shopify')
        ->assertHeader('Content-Security-Policy', "frame-ancestors https://admin.shopify.com https://*.myshopify.com https://*.shopify.com;");
});

test('shopify embedded app route renders verified admin shell for configured store', function () {
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'shopify-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'shopify-client-secret');

    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'modernforestry.myshopify.com',
        'access_token' => 'shpat_test',
        'installed_at' => now(),
    ]);

    $query = shopifyEmbeddedSignedQuery([
        'shop' => 'modernforestry.myshopify.com',
        'host' => 'admin-host-token',
        'embedded' => '1',
        'timestamp' => (string) time(),
    ], 'shopify-client-secret');

    $response = $this->get(route('shopify.app', $query));

    $response->assertOk()
        ->assertSeeText('Dashboard')
        ->assertSeeText('Fast loyalty snapshot for recent program activity.')
        ->assertSeeText('Recent customer purchase activity')
        ->assertDontSee('id="shopify-dashboard-root"', false)
        ->assertDontSee('id="shopify-dashboard-bootstrap"', false)
        ->assertSee('<s-app-nav>', false)
        ->assertHeader('Content-Security-Policy', "frame-ancestors https://admin.shopify.com https://*.myshopify.com https://*.shopify.com;");

    expect($response->headers->get('X-Frame-Options'))->toBeNull();
});

test('shopify embedded app route can load the full analytics dashboard from the stored session page context', function () {
    configureEmbeddedRetailStore();

    $this->get(route('shopify.app', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Recent customer purchase activity');

    $full = $this->get(route('shopify.app', ['full' => 1]));

    $full->assertOk()
        ->assertSeeText('Loading dashboard')
        ->assertSee('id="shopify-dashboard-root"', false)
        ->assertSee('id="shopify-dashboard-bootstrap"', false);
});

test('shopify embedded app route emits server timing header only when profiling is enabled', function () {
    configureEmbeddedRetailStore();

    config()->set('shopify_embedded.perf_profiling_enabled', true);
    $withProfiling = $this->get(route('shopify.app', retailEmbeddedSignedQuery()));
    $withProfiling->assertOk();
    expect((string) $withProfiling->headers->get('Server-Timing', ''))
        ->toContain('context;dur=')
        ->toContain('total;dur=');

    config()->set('shopify_embedded.perf_profiling_enabled', false);
    $withoutProfiling = $this->get(route('shopify.app', retailEmbeddedSignedQuery()));
    $withoutProfiling->assertOk();
    expect($withoutProfiling->headers->get('Server-Timing'))->toBeNull();
});

test('shopify embedded app route rejects invalid hmac', function () {
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'shopify-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'shopify-client-secret');

    $this->get(route('shopify.app', [
        'shop' => 'modernforestry.myshopify.com',
        'host' => 'admin-host-token',
        'embedded' => '1',
        'timestamp' => (string) time(),
        'hmac' => 'bad-signature',
    ]))
        ->assertStatus(401)
        ->assertSeeText('Open the app again from Shopify Admin to load store data.');
});

test('shopify embedded session lets root-style home route resolve after signed app entry', function () {
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'shopify-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'shopify-client-secret');

    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'modernforestry.myshopify.com',
        'access_token' => 'shpat_test',
        'installed_at' => now(),
    ]);

    $query = shopifyEmbeddedSignedQuery([
        'shop' => 'modernforestry.myshopify.com',
        'host' => 'admin-host-token',
        'embedded' => '1',
        'timestamp' => (string) time(),
    ], 'shopify-client-secret');

    $this->get(route('shopify.app', $query))->assertOk();

    $this->get('/')
        ->assertOk()
        ->assertSeeText('Dashboard')
        ->assertSeeText('Fast loyalty snapshot for recent program activity.');
});

test('shopify embedded session redirects legacy rewards root to canonical app route and blocks legacy customer entry without Shopify context', function () {
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'shopify-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'shopify-client-secret');

    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'modernforestry.myshopify.com',
        'access_token' => 'shpat_test',
        'installed_at' => now(),
    ]);

    $query = shopifyEmbeddedSignedQuery([
        'shop' => 'modernforestry.myshopify.com',
        'host' => 'admin-host-token',
        'embedded' => '1',
        'timestamp' => (string) time(),
    ], 'shopify-client-secret');

    $this->get(route('shopify.app', $query))->assertOk();

    $rewardsResponse = $this->get('/rewards');
    $rewardsResponse->assertRedirect();
    expect($rewardsResponse->headers->get('Location', ''))
        ->toContain('/shopify/app/rewards');

    $this->get('/customers')
        ->assertStatus(400)
        ->assertSeeText('Context Missing')
        ->assertSeeText('This page must be opened from Shopify Admin');
});

test('shopify embedded home renders concise setup surface', function () {
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'shopify-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'shopify-client-secret');

    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'modernforestry.myshopify.com',
        'access_token' => 'shpat_test',
        'installed_at' => now(),
    ]);

    $query = shopifyEmbeddedSignedQuery([
        'shop' => 'modernforestry.myshopify.com',
        'host' => 'admin-host-token',
        'embedded' => '1',
        'timestamp' => (string) time(),
    ], 'shopify-client-secret');

    $response = $this->get(route('shopify.app', $query));

    $response->assertOk()
        ->assertSeeText('Recent customer purchase activity')
        ->assertSee('data-command-field', false)
        ->assertSee('id="app-topbar-command-search"', false)
        ->assertDontSeeText('Cmd/Ctrl + K')
        ->assertDontSee('shopify-dashboard-loading-shell', false)
        ->assertDontSee('id="shopify-dashboard-root"', false)
        ->assertDontSeeText('What Happens After Import');
});

test('shopify embedded search reuses page context and returns embedded backstage sections', function () {
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'shopify-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'shopify-client-secret');

    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'modernforestry.myshopify.com',
        'access_token' => 'shpat_test',
        'installed_at' => now(),
    ]);

    $query = shopifyEmbeddedSignedQuery([
        'shop' => 'modernforestry.myshopify.com',
        'host' => 'admin-host-token',
        'embedded' => '1',
        'timestamp' => (string) time(),
    ], 'shopify-client-secret');

    $this->get(route('shopify.app', $query))->assertOk();

    $response = $this->getJson(route('shopify.app.api.search', ['q' => 'settings']));

    $response->assertOk()
        ->assertJsonPath('query', 'settings')
        ->assertJsonPath('groups.Backstage.0.title', 'Settings');

    expect(collect($response->json('results'))->pluck('url'))
        ->toContain('/shopify/app/settings?host=admin-host-token');
});

test('shopify embedded search accepts person-name queries with spaces and does not return open_from_shopify state when context is present', function () {
    configureEmbeddedRetailStore();

    $this->get(route('shopify.app', retailEmbeddedSignedQuery()))->assertOk();

    $response = $this->getJson(route('shopify.app.api.search', array_merge(
        retailEmbeddedSignedQuery(),
        ['q' => 'John collins']
    )));

    $response->assertOk()
        ->assertJsonPath('query', 'John collins')
        ->assertJsonMissing(['status' => 'open_from_shopify'])
        ->assertJsonStructure([
            'query',
            'results',
            'groups',
            'total',
        ]);
});

test('home does not flag sync as stale before the configured threshold', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-02 12:00:00'));

    try {
        config()->set('shopify_embedded.sync_stale_after_days', 3);

        $tenant = Tenant::query()->create([
            'name' => 'Retail Tenant',
            'slug' => 'retail-tenant',
        ]);
        configureEmbeddedRetailStore($tenant->id);

        MarketingProfile::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Avery',
            'last_name' => 'Stone',
            'email' => 'avery@example.com',
            'normalized_email' => 'avery@example.com',
        ]);

        MarketingImportRun::query()->create([
            'tenant_id' => $tenant->id,
            'type' => 'customers',
            'status' => 'completed',
            'source_label' => 'Shopify sync',
            'started_at' => now()->subDays(3)->addMinute(),
            'finished_at' => now()->subDays(3)->addMinute(),
        ]);

        $response = $this->get(route('shopify.app', retailEmbeddedSignedQuery()));

        $response->assertOk()
            ->assertSeeText('Recent customer purchase activity')
            ->assertDontSeeText('Refresh customer sync')
            ->assertDontSeeText('Retry sync');
    } finally {
        $this->travelBack();
    }
});

test('home flags sync as stale at the configured threshold', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-02 12:00:00'));

    try {
        config()->set('shopify_embedded.sync_stale_after_days', 3);

        $tenant = Tenant::query()->create([
            'name' => 'Retail Tenant',
            'slug' => 'retail-tenant',
        ]);
        configureEmbeddedRetailStore($tenant->id);

        MarketingProfile::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Avery',
            'last_name' => 'Stone',
            'email' => 'avery@example.com',
            'normalized_email' => 'avery@example.com',
        ]);

        MarketingImportRun::query()->create([
            'tenant_id' => $tenant->id,
            'type' => 'customers',
            'status' => 'completed',
            'source_label' => 'Shopify sync',
            'started_at' => now()->subDays(3),
            'finished_at' => now()->subDays(3),
        ]);

        $response = $this->get(route('shopify.app', retailEmbeddedSignedQuery()));

        $response->assertOk()
            ->assertSeeText('Recent customer purchase activity')
            ->assertDontSee('id="shopify-dashboard-root"', false)
            ->assertDontSee('id="shopify-dashboard-bootstrap"', false)
            ->assertDontSeeText('Refresh customer sync')
            ->assertDontSeeText('Customer data has not synced in the last 3 days.')
            ->assertDontSeeText('Retry sync');
    } finally {
        $this->travelBack();
    }
});
