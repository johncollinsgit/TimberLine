<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Tenant;
use App\Models\TenantDiscoveryPage;
use App\Models\TenantDiscoveryProfile;
use App\Services\Discovery\DomainCanonicalResolver;
use App\Services\Discovery\TenantDiscoveryProfileService;
use App\Services\Tenancy\ModernForestryAlphaBootstrapService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Command\Command as ConsoleCommand;

test('tenant discovery profile resolution includes modern forestry defaults and south carolina relevance', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    app(ModernForestryAlphaBootstrapService::class)->ensureForTenant($tenant->id, 'retail', force: true);

    $payload = app(TenantDiscoveryProfileService::class)->resolveForTenant($tenant->id);

    expect(data_get($payload, 'brand_identity.primary_brand_name'))->toBe('Modern Forestry')
        ->and(data_get($payload, 'domain_relationships.primary_retail_domain'))->toBe('theforestrystudio.com')
        ->and(data_get($payload, 'domain_relationships.primary_wholesale_domain'))->toBe('modernforestrywholesale.com')
        ->and(data_get($payload, 'domain_relationships.shopify_admin_domain'))->toBe('modernforestry.myshopify.com')
        ->and(data_get($payload, 'geography.primary_state'))->toBe('South Carolina')
        ->and(collect((array) data_get($payload, 'discovery_pages'))->pluck('page_key')->contains('south-carolina-wholesale'))->toBeTrue()
        ->and(data_get($payload, 'merchant_signals.wholesale_available'))->toBeTrue();
});

test('domain canonical resolver separates retail and wholesale intent', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);
    app(ModernForestryAlphaBootstrapService::class)->ensureForTenant($tenant->id, 'retail', force: true);

    /** @var DomainCanonicalResolver $resolver */
    $resolver = app(DomainCanonicalResolver::class);

    $retail = $resolver->resolveForPage($tenant->id, 'retail_collection');
    $wholesale = $resolver->resolveForPage($tenant->id, 'wholesale_landing');

    expect(data_get($retail, 'target_role'))->toBe('retail_storefront')
        ->and(data_get($retail, 'target_domain'))->toBe('theforestrystudio.com')
        ->and(data_get($wholesale, 'target_role'))->toBe('wholesale_storefront')
        ->and(data_get($wholesale, 'target_domain'))->toBe('modernforestrywholesale.com');
});

test('public well known discovery endpoint returns expected graph shape', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);
    app(ModernForestryAlphaBootstrapService::class)->ensureForTenant($tenant->id, 'retail', force: true);

    $response = $this->getJson(route('discovery.well-known.brand'));

    $response->assertOk()
        ->assertJsonStructure([
            'organization',
            'websites',
            'domains',
            'audience_paths',
            'service_regions',
            'merchant_policies',
            'trust_signals',
            'faq_refs',
            'product_catalog_entrypoints',
            'wholesale_entrypoints',
            'retail_entrypoints',
            'structured_data_contracts',
        ])
        ->assertJsonPath('organization.name', 'Modern Forestry')
        ->assertJsonPath('service_regions.primary_state', 'South Carolina')
        ->assertJsonPath('audience_paths.retail_customer.recommended_domain_role', 'retail_storefront')
        ->assertJsonPath('audience_paths.wholesale_buyer.recommended_domain_role', 'wholesale_storefront');
});

test('brand discovery graph keeps retail and wholesale entrypoints separate', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);
    app(ModernForestryAlphaBootstrapService::class)->ensureForTenant($tenant->id, 'retail', force: true);

    $response = $this->getJson(route('discovery.public.brand', ['tenant' => $tenant->slug]));
    $response->assertOk();

    $wholesaleEntrypoints = (array) $response->json('wholesale_entrypoints', []);
    $retailEntrypoints = (array) $response->json('retail_entrypoints', []);

    expect(collect($wholesaleEntrypoints)->contains(fn (array $row): bool => (string) ($row['recommended_domain_role'] ?? '') === 'wholesale_storefront'))->toBeTrue()
        ->and(collect($retailEntrypoints)->contains(fn (array $row): bool => (string) ($row['recommended_domain_role'] ?? '') === 'retail_storefront'))->toBeTrue();
});

test('structured data contracts avoid local business when location is incomplete', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);
    app(ModernForestryAlphaBootstrapService::class)->ensureForTenant($tenant->id, 'retail', force: true);

    $response = $this->getJson(route('discovery.public.structured', ['tenant' => $tenant->slug]));

    $response->assertOk()
        ->assertJsonPath('contracts.local_business', null)
        ->assertJsonPath('contracts.safety.local_business_emitted', false);
});

test('structured data emits faq page only when real faq content exists', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);
    app(ModernForestryAlphaBootstrapService::class)->ensureForTenant($tenant->id, 'retail', force: true);

    TenantDiscoveryPage::query()->updateOrCreate(
        ['tenant_id' => $tenant->id, 'page_key' => 'wholesale-faq'],
        [
            'title' => 'Wholesale FAQ',
            'page_type' => 'faq_page',
            'recommended_domain_role' => 'wholesale_storefront',
            'faq_items' => [
                ['question' => 'Do you ship outside South Carolina?', 'answer' => 'Shipping availability is policy-dependent and can require contact before ordering.'],
            ],
            'is_public' => true,
            'is_indexable' => true,
            'position' => 60,
        ]
    );

    $response = $this->getJson(route('discovery.public.structured', [
        'tenant' => $tenant->slug,
        'page_key' => 'wholesale-faq',
    ]));

    $response->assertOk()
        ->assertJsonPath('contracts.safety.faq_page_emitted', true)
        ->assertJsonPath('contracts.faq_page.@type', 'FAQPage');
});

test('trust policy links fallback to discovery page urls when trust facts are unset', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);
    app(ModernForestryAlphaBootstrapService::class)->ensureForTenant($tenant->id, 'retail', force: true);

    TenantDiscoveryProfile::query()->where('tenant_id', $tenant->id)->update([
        'trust_facts' => [
            'return_policy_url' => null,
            'shipping_policy_url' => null,
            'faq_url' => null,
        ],
    ]);

    TenantDiscoveryPage::query()->updateOrCreate(
        ['tenant_id' => $tenant->id, 'page_key' => 'shipping-policy'],
        [
            'title' => 'Shipping Policy',
            'page_type' => 'policy_page',
            'recommended_domain_role' => 'retail_storefront',
            'cta_url' => 'https://theforestrystudio.com/policies/shipping-policy',
            'is_public' => true,
            'is_indexable' => true,
            'position' => 70,
        ]
    );
    TenantDiscoveryPage::query()->updateOrCreate(
        ['tenant_id' => $tenant->id, 'page_key' => 'returns-policy'],
        [
            'title' => 'Returns Policy',
            'page_type' => 'policy_page',
            'recommended_domain_role' => 'retail_storefront',
            'cta_url' => 'https://theforestrystudio.com/policies/refund-policy',
            'is_public' => true,
            'is_indexable' => true,
            'position' => 80,
        ]
    );
    TenantDiscoveryPage::query()->updateOrCreate(
        ['tenant_id' => $tenant->id, 'page_key' => 'wholesale-faq'],
        [
            'title' => 'Wholesale FAQ',
            'page_type' => 'faq_page',
            'recommended_domain_role' => 'wholesale_storefront',
            'cta_url' => 'https://modernforestrywholesale.com/pages/faq',
            'is_public' => true,
            'is_indexable' => true,
            'position' => 60,
        ]
    );

    $payload = app(TenantDiscoveryProfileService::class)->resolveForTenant($tenant->id);

    expect(data_get($payload, 'trust.shipping_policy_url'))->toBe('https://theforestrystudio.com/policies/shipping-policy')
        ->and(data_get($payload, 'trust.return_policy_url'))->toBe('https://theforestrystudio.com/policies/refund-policy')
        ->and(data_get($payload, 'trust.faq_url'))->toBe('https://modernforestrywholesale.com/pages/faq');
});

test('discovery sitemap exports canonical public urls and discovery entrypoints', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);
    app(ModernForestryAlphaBootstrapService::class)->ensureForTenant($tenant->id, 'retail', force: true);

    $response = $this->get('/sitemaps/discovery.xml');
    $response->assertOk();
    $body = (string) $response->getContent();

    expect($body)->toContain('<urlset')
        ->toContain('https://theforestrystudio.com/')
        ->toContain('https://theforestrystudio.com/.well-known/brand-discovery.json')
        ->toContain('/api/public/discovery/brand/modern-forestry');
});

test('domain audit command reports drift for stale custom-domain signals and metadata gaps', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    TenantDiscoveryProfile::query()->create([
        'tenant_id' => $tenant->id,
        'primary_brand_name' => 'Modern Forestry',
        'domain_map' => [
            'primary_retail_domain' => 'theforestrystudio.com',
            'primary_wholesale_domain' => 'modernforestrywholesale.com',
            'shopify_admin_domain' => 'modernforestry.myshopify.com',
            'relationships' => [
                ['domain' => 'theforestrystudio.com', 'role' => 'retail_storefront', 'visibility' => 'public_facing'],
                ['domain' => 'modernforestrywholesale.com', 'role' => 'wholesale_storefront', 'visibility' => 'public_facing'],
                ['domain' => 'modernforestry.myshopify.com', 'role' => 'admin_only', 'visibility' => 'admin_only'],
            ],
        ],
        'canonical_rules' => [],
        'geography' => ['primary_state' => 'South Carolina'],
        'audience_map' => [],
        'trust_facts' => [],
        'merchant_signals' => [],
        'placeholders' => [],
        'is_active' => true,
    ]);

    Http::fake(function (HttpRequest $request) {
        $url = $request->url();

        if ($url === 'https://theforestrystudio.com/') {
            return Http::response('<html><head><title>Stale</title><meta name="description" content="stale"><link rel="canonical" href="https://theforestrystudio.com/"></head><body>Prestige growave theme 136487764227</body></html>', 200);
        }

        if ($url === 'https://theforestrystudio.com/robots.txt') {
            return Http::response("User-agent: *\nDisallow:\n", 200);
        }

        if ($url === 'https://theforestrystudio.com/.well-known/brand-discovery.json') {
            return Http::response('{"ok":true}', 200);
        }

        if ($url === 'https://modernforestrywholesale.com/') {
            return Http::response('<html><head><title></title></head><body>No schema here</body></html>', 200);
        }

        if ($url === 'https://modernforestrywholesale.com/robots.txt') {
            return Http::response("User-agent: *\nDisallow:\n", 200);
        }

        if ($url === 'https://modernforestrywholesale.com/.well-known/brand-discovery.json') {
            return Http::response('{}', 404);
        }

        if ($url === 'https://modernforestry.myshopify.com/') {
            return Http::response('<html><head><title>Admin</title></head><body></body></html>', 200);
        }

        if ($url === 'https://modernforestry.myshopify.com/robots.txt') {
            return Http::response("User-agent: *\nDisallow: /\n", 200);
        }

        if ($url === 'https://modernforestry.myshopify.com/.well-known/brand-discovery.json') {
            return Http::response('{}', 404);
        }

        return Http::response('', 404);
    });

    $this->artisan('modern-forestry:audit:domains', [
        '--tenant-id' => $tenant->id,
        '--timeout' => 2,
    ])
        ->expectsOutputToContain('status=drift')
        ->expectsOutputToContain('issue[severe]=stale_custom_domain_signal')
        ->expectsOutputToContain('issue[warning]=wholesale_discovery_metadata_thin')
        ->assertExitCode(ConsoleCommand::FAILURE);
});
