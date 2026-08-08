<?php

use App\Models\FieldServiceJob;
use App\Models\FormSubmission;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteOrder;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use App\Services\ManagedWebsite\WebsitePilotService;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('managed_website.editor_enabled', true);
    config()->set('managed_website.publishing_enabled', true);
    config()->set('managed_website.public_render_enabled', true);
    config()->set('managed_website.commerce_enabled', false);
});

function websitePilotTenant(string $slug = 'electrician-pilot'): Tenant
{
    $tenant = Tenant::query()->create(['name' => 'Electrician Pilot', 'slug' => $slug]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'trial', 'entitlement_source' => 'test']);

    return $tenant;
}

function websitePilotUser(Tenant $tenant, string $membershipRole = 'admin'): User
{
    $user = User::factory()->create(['role' => $membershipRole === 'manager' ? 'manager' : 'admin', 'is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => $membershipRole, 'membership_active' => true]);

    return $user;
}

function completedWebsitePilot(Tenant $tenant, User $actor): array
{
    config()->set('managed_website.editor_tenant_ids', [$tenant->id]);
    $websites = app(ManagedWebsiteService::class);
    $site = $websites->createSite($tenant, $actor);
    $websites->applyTheme($site, 'collins-electric', $actor);
    app(WebsitePilotService::class)->saveSetup($tenant, $site, ['contact_name' => 'Pilot Electric', 'contact_email' => 'hello@pilot.test', 'contact_phone' => '555-0100', 'hours' => 'Mon–Fri, 8am–5pm', 'service_area' => 'Albany and nearby towns'], $actor);
    $product = app(WebsiteCommerceService::class)->saveProduct($site, ['title' => 'Panel upgrade', 'product_type' => 'quote', 'status' => 'active', 'price' => '0', 'description' => 'A clear quote for a panel upgrade.', 'track_inventory' => false, 'is_available' => true]);
    app(WebsitePilotService::class)->markMobilePreviewed($tenant, $actor);

    return [$site->fresh('setup'), $product];
}

test('quote-first setup stays within its active tenant and has a truthful checklist', function (): void {
    $tenant = websitePilotTenant();
    $other = websitePilotTenant('electrician-other');
    $actor = websitePilotUser($tenant);
    [$site] = completedWebsitePilot($tenant, $actor);
    $checklist = app(WebsitePilotService::class)->checklist($site, $site->setup);

    expect($site->setup->tenant_id)->toBe($tenant->id)
        ->and($site->setup->business_mode)->toBe('trades')
        ->and($site->setup->visitor_actions)->toBe(['request_quote', 'call_business'])
        ->and(collect($checklist)->firstWhere('key', 'publish')['complete'])->toBeFalse()
        ->and($other->managedSiteSetup)->toBeNull();
});

test('website admin leads with the live preview and keeps domain details secondary', function (): void {
    $tenant = websitePilotTenant('website-admin-layout');
    $actor = websitePilotUser($tenant);
    completedWebsitePilot($tenant, $actor);

    $response = $this->actingAs($actor)
        ->get('https://website-admin-layout.theeverbranch.com/website')
        ->assertOk()
        ->assertSee('eb-admin-page-header', false)
        ->assertSee('eb-site-theme-card', false)
        ->assertSee('eb-site-browser-frame', false)
        ->assertSee('eb-admin-panel eb-admin-disclosure', false)
        ->assertSeeText('Edit website')
        ->assertSeeText('Pages')
        ->assertSeeText('Domains')
        ->assertDontSeeText('How this stays safe');

    $html = $response->getContent();

    expect(strpos($html, 'eb-site-theme-card'))
        ->toBeLessThan(strpos($html, 'id="website-domains"'));
});

test('only tenant owner or admin can publish while a manager can still save a draft', function (): void {
    $tenant = websitePilotTenant('publish-pilot');
    $admin = websitePilotUser($tenant, 'admin');
    [$site] = completedWebsitePilot($tenant, $admin);
    $manager = websitePilotUser($tenant, 'manager');
    $page = $site->pages()->where('slug', '/')->firstOrFail();

    $this->actingAs($manager)->putJson('https://publish-pilot.theeverbranch.com/website/editor/'.$page->id, ['title' => 'Manager draft', 'blocks' => [['type' => 'hero', 'heading' => 'Manager draft', 'body' => 'Safe draft only.']]])->assertOk();
    $this->actingAs($manager)->post('https://publish-pilot.theeverbranch.com/website/publish')->assertForbidden();
    $this->actingAs($admin)->post('https://publish-pilot.theeverbranch.com/website/publish')->assertRedirect();
    expect($site->fresh()->public_enabled)->toBeTrue();
    $published = $page->fresh()->publishedVersion;
    $this->actingAs($manager)->post('https://publish-pilot.theeverbranch.com/website/pages/'.$page->id.'/versions/'.$published->id.'/rollback')->assertForbidden();
});

test('public quote validates all six fields, scopes the lead, and never writes legacy systems', function (): void {
    $tenant = websitePilotTenant('quote-pilot');
    $actor = websitePilotUser($tenant);
    [$site, $product] = completedWebsitePilot($tenant, $actor);
    app(ManagedWebsiteService::class)->publish($site, $actor);
    $before = [Order::query()->count(), WebsiteOrder::query()->count(), WebsiteCustomer::query()->count(), FieldServiceJob::query()->count(), MarketingProfile::query()->count()];
    $url = 'https://quote-pilot.theeverbranch.com/products/'.$product->handle.'/quote';

    $this->post($url, ['name' => 'Pat Customer', 'email' => 'pat@gmail.com', 'phone' => '555-0123', 'service_address' => '1 Main St', 'service_needed' => 'Panel upgrade', 'message' => 'Please call after noon.'])->assertRedirect()->assertSessionHasNoErrors();
    $submission = FormSubmission::query()->where('source', 'managed_website_quote')->firstOrFail();
    expect($submission->tenant_id)->toBe($tenant->id)
        ->and(data_get($submission->normalized_payload, 'tenant_site_id'))->toBe($site->id)
        ->and(data_get($submission->normalized_payload, 'website_product_id'))->toBe($product->id)
        ->and(data_get($submission->payload, 'service_address'))->toBe('1 Main St')
        ->and([Order::query()->count(), WebsiteOrder::query()->count(), WebsiteCustomer::query()->count(), FieldServiceJob::query()->count(), MarketingProfile::query()->count()])->toBe($before);
    $this->from($url)->post($url, ['name' => 'Pat', 'email' => 'pat@gmail.com', 'service_address' => '1 Main St', 'service_needed' => 'Panel upgrade', 'message' => 'Missing phone'])->assertSessionHasErrors(['phone']);
    $this->from($url)->post($url, ['name' => 'Spam', 'email' => 'spam@gmail.com', 'phone' => '555-0123', 'service_address' => '1 Main St', 'service_needed' => 'Panel upgrade', 'message' => 'Spam', 'website' => 'filled'])->assertSessionHasErrors(['website']);
});

test('a host may only render the subdomain stored on the published pilot site', function (): void {
    $tenant = websitePilotTenant('wrong-host-pilot');
    $actor = websitePilotUser($tenant);
    [$site] = completedWebsitePilot($tenant, $actor);
    $site->update(['subdomain' => 'verified-pilot']);
    app(ManagedWebsiteService::class)->publish($site->fresh('setup'), $actor);

    $this->get('https://wrong-host-pilot.theeverbranch.com/')->assertNotFound();
    $this->get('https://verified-pilot.theeverbranch.com/')->assertNotFound();
});
