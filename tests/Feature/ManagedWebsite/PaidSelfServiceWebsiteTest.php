<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Models\WebsiteProduct;
use App\Services\Billing\SelfServicePurchaseEligibility;
use App\Services\ManagedWebsite\ManagedWebsiteDomainService;
use App\Services\ManagedWebsite\ManagedWebsiteSelfServicePolicy;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsitePilotService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config()->set('managed_website.editor_enabled', true);
    config()->set('managed_website.publishing_enabled', true);
    config()->set('managed_website.public_render_enabled', true);
    config()->set('managed_website.custom_domains_enabled', true);
    config()->set('managed_website.custom_domain_activation_enabled', true);
    config()->set('managed_website.editor_tenant_ids', []);
    config()->set('managed_website.custom_domain_tenant_ids', []);
    $purchases = Mockery::mock(SelfServicePurchaseEligibility::class);
    $purchases->shouldReceive('paidForTenant')->andReturnUsing(fn ($tenant): bool => in_array($tenant instanceof Tenant ? $tenant->id : $tenant, (array) config('testing.paid_website_tenants', []), true));
    $purchases->shouldReceive('hasVerifiedPurchase')->andReturnUsing(fn ($tenant): bool => in_array($tenant instanceof Tenant ? $tenant->id : $tenant, (array) config('testing.verified_website_tenants', []), true));
    app()->instance(SelfServicePurchaseEligibility::class, $purchases);
});

function paidWebsiteFixture(): array
{
    $tenant = Tenant::query()->create(['name' => 'Fictional Photo Studio', 'slug' => 'paid-photo-studio']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'metadata' => ['self_service_registration' => true]]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included', 'entitlement_source' => 'self_service_purchase']);
    $actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now()]);
    $actor->tenants()->attach($tenant, ['role' => 'admin']);

    return [$tenant, $actor];
}

test('only confirmed paid website access replaces manual rollout and global freezes still win', function (): void {
    [$tenant] = paidWebsiteFixture();
    $websites = app(ManagedWebsiteService::class);
    $domains = app(ManagedWebsiteDomainService::class);
    expect($websites->editorEnabledFor($tenant))->toBeFalse()->and($domains->enabledFor($tenant))->toBeFalse();
    config()->set('testing.paid_website_tenants', [$tenant->id]);
    expect($websites->editorEnabledFor($tenant))->toBeTrue()->and($domains->activationEnabledFor($tenant))->toBeTrue();
    config()->set('managed_website.editor_enabled', false);
    config()->set('managed_website.custom_domain_activation_enabled', false);
    expect($websites->editorEnabledFor($tenant))->toBeFalse()->and($domains->activationEnabledFor($tenant))->toBeFalse();
});

test('explicit website module disablement overrides a confirmed purchase', function (): void {
    [$tenant] = paidWebsiteFixture();
    config()->set('testing.paid_website_tenants', [$tenant->id]);
    TenantModuleState::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'managed_website', 'enabled_override' => false]);
    expect(app(ManagedWebsiteSelfServicePolicy::class)->eligible($tenant))->toBeFalse()
        ->and(app(ManagedWebsiteService::class)->editorEnabledFor($tenant))->toBeFalse();
});

test('paid website owners can publish a general business site without a quote-only catalog', function (): void {
    [$tenant, $actor] = paidWebsiteFixture();
    config()->set('testing.paid_website_tenants', [$tenant->id]);
    $websites = app(ManagedWebsiteService::class);
    $site = $websites->createSite($tenant, $actor);
    $websites->applyTheme($site, 'collins-electric', $actor);
    $site->refresh();
    $pilot = app(WebsitePilotService::class);
    $setup = $pilot->saveSetup($tenant, $site, ['contact_name' => 'Fictional Photo Studio', 'contact_email' => 'hello@photo.example', 'theme_key' => 'collins-electric'], $actor);
    expect(WebsiteProduct::query()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and($pilot->readyToPublish($site, $setup))->toBeTrue();
    config()->set('testing.verified_website_tenants', [$tenant->id]);
    $this->actingAs($actor)->withSession(['tenant_id' => $tenant->id])
        ->post(route('managed-website.publish'))->assertRedirect()->assertSessionHasNoErrors();
    expect($site->fresh()->status)->toBe('published')->and($site->fresh()->public_enabled)->toBeTrue();
    $this->get('https://paid-photo-studio.theeverbranch.com/')->assertOk()->assertSee('data-testid="managed-site"', false);
    $domain = app(ManagedWebsiteDomainService::class)->request($site, 'www.photo.example', $actor);
    $domain->forceFill(['status' => 'active', 'is_primary' => true])->save();
    expect($site->setup->domain_choice)->toBe('everbranch_subdomain')
        ->and($websites->publicHostAllowed($site, $domain->hostname))->toBeTrue();
    $this->get('https://www.photo.example/')->assertOk()->assertSee('data-testid="managed-site"', false);
});

test('expired paid eligibility pauses editing while retaining the last paid public snapshot', function (): void {
    [$tenant, $actor] = paidWebsiteFixture();
    config()->set('testing.paid_website_tenants', [$tenant->id]);
    config()->set('testing.verified_website_tenants', [$tenant->id]);
    $websites = app(ManagedWebsiteService::class);
    $site = $websites->createSite($tenant, $actor);
    $websites->publish($site, $actor);
    expect($websites->publicPage($tenant, '/'))->not->toBeNull();
    config()->set('testing.paid_website_tenants', []);
    expect($websites->editorEnabledFor($tenant))->toBeFalse()
        ->and($websites->publicPage($tenant, '/'))->not->toBeNull();
    $published = $site->fresh()->published_site_version_id;
    expect(fn () => $websites->publish($site, $actor))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($site->fresh()->published_site_version_id)->toBe($published);
    config()->set('testing.verified_website_tenants', []);
    expect($websites->publicPage($tenant, '/'))->toBeNull();
    TenantAccessProfile::query()->where('tenant_id', $tenant->id)->update(['metadata' => []]);
    expect($websites->publicPage($tenant, '/'))->not->toBeNull();
});
