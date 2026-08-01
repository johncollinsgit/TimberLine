<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSite;
use App\Models\TenantSitePage;
use App\Models\TenantSitePageVersion;
use App\Models\TenantSiteVersion;
use App\Models\User;
use App\Services\ManagedWebsite\ManagedWebsiteDomainService;
use App\Services\Tenancy\PreAuthTenantContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config()->set('managed_website.editor_enabled', true);
    config()->set('managed_website.publishing_enabled', true);
    config()->set('managed_website.public_render_enabled', true);
    config()->set('managed_website.custom_domains_enabled', true);
    config()->set('managed_website.custom_domain_activation_enabled', true);

    // The legacy test bootstrap schema predates the additive Website pilot
    // migrations. Load this small chain explicitly so this regression remains
    // runnable until the shared SQLite snapshot is refreshed.
    foreach ([
        '2026_07_27_120000_create_managed_website_tables.php',
        '2026_07_27_200000_add_versioned_theme_and_media_to_tenant_sites.php',
        '2026_07_27_230000_create_tenant_site_domains_table.php',
    ] as $migration) {
        if (! Schema::hasTable('tenant_sites') || ($migration !== '2026_07_27_120000_create_managed_website_tables.php' && ! Schema::hasTable($migration === '2026_07_27_200000_add_versioned_theme_and_media_to_tenant_sites.php' ? 'tenant_site_versions' : 'tenant_site_domains'))) {
            (require dirname(__DIR__, 2).'/database/migrations/'.$migration)->up();
        }
    }
});

function customDomainPilot(string $name = 'Domain Pilot', string $slug = 'domain-pilot'): array
{
    $tenant = Tenant::query()->create(['name' => $name, 'slug' => $slug]);
    $actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $actor->tenants()->attach($tenant->id, ['role' => 'admin']);
    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'managed_website',
        'availability_status' => 'available',
        'enabled_status' => 'enabled',
        'billing_status' => 'add_on_paid',
        'entitlement_source' => 'test',
        'price_source' => 'catalog',
    ]);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id]);
    config()->set('managed_website.custom_domain_tenant_ids', [$tenant->id]);
    $site = TenantSite::query()->create(['tenant_id' => $tenant->id, 'status' => 'published', 'public_enabled' => true, 'subdomain' => $slug]);
    $siteVersion = TenantSiteVersion::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'version_number' => 1, 'status' => 'published', 'settings' => ['theme_name' => $name], 'navigation' => [], 'seo' => [], 'published_at' => now()]);
    $page = TenantSitePage::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'slug' => '/', 'page_type' => 'home', 'title' => $name]);
    $pageVersion = TenantSitePageVersion::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'tenant_site_page_id' => $page->id, 'version_number' => 1, 'status' => 'published', 'title' => $name, 'blocks' => [['type' => 'hero', 'heading' => $name]], 'seo' => [], 'published_at' => now()]);
    $site->forceFill(['published_site_version_id' => $siteVersion->id])->save();
    $page->forceFill(['published_version_id' => $pageVersion->id])->save();

    return [$tenant, $actor, $site->fresh()];
}

function publishedCustomDomainPage(Tenant $tenant, TenantSite $site, string $slug, string $title): TenantSitePage
{
    $page = TenantSitePage::query()->create([
        'tenant_id' => $tenant->id,
        'tenant_site_id' => $site->id,
        'slug' => $slug,
        'page_type' => 'standard',
        'title' => $title,
        'is_navigation_visible' => true,
    ]);
    $version = TenantSitePageVersion::query()->create([
        'tenant_id' => $tenant->id,
        'tenant_site_id' => $site->id,
        'tenant_site_page_id' => $page->id,
        'version_number' => 1,
        'status' => 'published',
        'title' => $title,
        'blocks' => [['type' => 'hero', 'heading' => $title]],
        'seo' => [],
        'published_at' => now(),
    ]);
    $page->forceFill(['published_version_id' => $version->id])->save();

    return $page;
}

test('a custom domain stays pending until verified and is bound to one site', function (): void {
    [, $actor, $site] = customDomainPilot();
    $service = app(ManagedWebsiteDomainService::class);
    $domain = $service->request($site, 'https://CollinsElectricSC.com/', $actor);

    expect($domain->hostname)->toBe('collinselectricsc.com')
        ->and($domain->status)->toBe('pending')
        ->and($domain->verification_token)->not->toBeEmpty()
        ->and($service->verificationRecordName($domain->hostname))->toBe('_everbranch-verify.collinselectricsc.com')
        ->and($service->verificationValue($domain))->toStartWith('everbranch-site=');
});

test('only an active custom domain resolves a public tenant host', function (): void {
    [$tenant, $actor, $site] = customDomainPilot();
    $domain = app(ManagedWebsiteDomainService::class)->request($site, 'collinselectricsc.com', $actor);
    $domain->forceFill(['status' => 'verified', 'verified_at' => now()])->save();
    app(ManagedWebsiteDomainService::class)->activate($domain, $actor);

    $context = app(PreAuthTenantContextResolver::class)->resolveForRequest(Request::create('https://collinselectricsc.com/'));

    expect($context->tenant?->id)->toBe($tenant->id)
        ->and($context->strategy)->toBe('managed_website_custom_domain');

    $this->get('https://collinselectricsc.com/login')->assertNotFound();
});

test('an active custom domain renders its published child pages without becoming a platform catch-all', function (): void {
    [$tenant, $actor, $site] = customDomainPilot();
    publishedCustomDomainPage($tenant, $site, 'residential', 'Residential electrical services');
    publishedCustomDomainPage($tenant, $site, 'api', 'Never a platform API page');

    $domain = app(ManagedWebsiteDomainService::class)->request($site, 'collinselectricsc.com', $actor);
    $domain->forceFill(['status' => 'verified', 'verified_at' => now()])->save();
    app(ManagedWebsiteDomainService::class)->activate($domain, $actor);

    $this->get('https://collinselectricsc.com/residential')
        ->assertOk()
        ->assertSeeText('Residential electrical services');

    $this->get('https://collinselectricsc.com/not-a-page')->assertNotFound();
    $this->post('https://collinselectricsc.com/not-a-page')->assertNotFound();
    $this->withHeaders(['Accept' => 'application/json'])
        ->get('https://collinselectricsc.com/residential')
        ->assertNotFound();
    $this->get('https://collinselectricsc.com/api')->assertNotFound();
    $this->get('https://collinselectricsc.com/login')->assertNotFound();
    $this->get('https://theeverbranch.com/residential')->assertNotFound();
});

test('active custom domains resolve only their own immutable published pages', function (): void {
    [$firstTenant, $firstActor, $firstSite] = customDomainPilot('First Electrical', 'first-electrical');
    [$secondTenant, $secondActor, $secondSite] = customDomainPilot('Second Electrical', 'second-electrical');
    config()->set('managed_website.custom_domain_tenant_ids', [$firstTenant->id, $secondTenant->id]);

    publishedCustomDomainPage($firstTenant, $firstSite, 'residential', 'First tenant residential service');
    publishedCustomDomainPage($secondTenant, $secondSite, 'residential', 'Second tenant residential service');

    $domains = app(ManagedWebsiteDomainService::class);
    foreach ([
        [$firstSite, 'first-electric.example', $firstActor],
        [$secondSite, 'second-electric.example', $secondActor],
    ] as [$site, $hostname, $actor]) {
        $domain = $domains->request($site, $hostname, $actor);
        $domain->forceFill(['status' => 'verified', 'verified_at' => now()])->save();
        $domains->activate($domain, $actor);
    }

    $this->get('https://first-electric.example/residential')
        ->assertOk()
        ->assertSeeText('First tenant residential service')
        ->assertDontSeeText('Second tenant residential service');
    $this->get('https://second-electric.example/residential')
        ->assertOk()
        ->assertSeeText('Second tenant residential service')
        ->assertDontSeeText('First tenant residential service');
    $this->get('https://unknown-electric.example/residential')->assertNotFound();
});

test('the everbranch public home cannot render a published modern forestry website', function (): void {
    customDomainPilot('Modern Forestry', 'modern-forestry');

    $this->get('https://theeverbranch.com/')
        ->assertOk()
        ->assertDontSee('data-testid="managed-site"', false);
});

test('platform domains cannot be claimed by a customer website', function (): void {
    [, $actor, $site] = customDomainPilot();

    expect(fn () => app(ManagedWebsiteDomainService::class)->request($site, 'theeverbranch.com', $actor))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

test('an attempted custom domain can be removed without deleting audit-safe state', function (): void {
    [, $actor, $site] = customDomainPilot();
    $service = app(ManagedWebsiteDomainService::class);
    $domain = $service->request($site, 'wrong-address.example', $actor);

    $service->cancel($domain, $actor);

    expect($domain->fresh())
        ->status->toBe('disabled')
        ->is_primary->toBeFalse();

    $replacement = $service->request($site, 'collinselectricsc.com', $actor);

    expect($replacement->hostname)->toBe('collinselectricsc.com')
        ->and($replacement->status)->toBe('pending');
});

test('a tenant cannot cancel another tenant’s attempted domain', function (): void {
    [$tenant, $actor, $site] = customDomainPilot();
    $domain = app(ManagedWebsiteDomainService::class)->request($site, 'collinselectricsc.com', $actor);
    [$otherTenant, $otherActor] = customDomainPilot('Other Pilot', 'other-pilot');

    config()->set('managed_website.custom_domain_tenant_ids', [$tenant->id, $otherTenant->id]);

    $this->actingAs($otherActor)->withSession(['tenant_id' => $otherTenant->id])
        ->post(route('managed-website.domains.cancel', ['domain' => $domain]))
        ->assertNotFound();

    expect($domain->fresh()->status)->toBe('pending');
});
