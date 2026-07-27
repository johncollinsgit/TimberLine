<?php

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('managed_website.commerce_enabled', true);
    config()->set('managed_website.editor_enabled', true);
    config()->set('managed_website.publishing_enabled', true);
    config()->set('managed_website.public_render_enabled', true);
});

function managedWebsiteTenant(string $slug = 'managed-site-pilot'): Tenant
{
    return Tenant::query()->create(['name' => 'Managed Website Pilot', 'slug' => $slug]);
}

function managedWebsiteActor(Tenant $tenant): User
{
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

    return $actor;
}

test('managed website publishing is additive and creates immutable snapshots', function (): void {
    $tenant = managedWebsiteTenant();
    $actor = managedWebsiteActor($tenant);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id]);
    $ordersBefore = Order::query()->count();
    Http::preventStrayRequests();

    $service = app(ManagedWebsiteService::class);
    $site = $service->createSite($tenant, $actor);
    $home = $site->pages()->where('slug', '/')->firstOrFail();
    $service->saveDraft($site, $home, [
        'title' => 'A safer pilot site',
        'blocks' => [['type' => 'hero', 'heading' => 'A safer pilot site', 'body' => 'Customer-owned content only.']],
        'seo' => ['title' => 'Pilot', 'description' => 'A safe test.'],
    ], $actor);
    $service->publish($site, $actor);

    $home->refresh();
    expect($site->fresh()->status)->toBe('published')
        ->and($home->published_version_id)->not->toBeNull()
        ->and($home->versions()->where('status', 'published')->count())->toBe(1)
        ->and(Order::query()->count())->toBe($ordersBefore);
});

test('managed website public host renders only an explicitly published snapshot', function (): void {
    $tenant = managedWebsiteTenant('safe-pilot');
    $actor = managedWebsiteActor($tenant);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id]);
    $service = app(ManagedWebsiteService::class);
    $site = $service->createSite($tenant, $actor);
    $service->publish($site, $actor);

    $this->get('https://safe-pilot.theeverbranch.com/')
        ->assertOk()
        ->assertSeeText('Managed Website Pilot');
});

test('pending billing never opens the editor even when a tenant is allowlisted', function (): void {
    $tenant = managedWebsiteTenant('billing-pending');
    managedWebsiteActor($tenant);
    TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->update(['billing_status' => 'pending_billing']);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id]);

    expect(app(ManagedWebsiteService::class)->editorEnabledFor($tenant))->toBeFalse();
});
