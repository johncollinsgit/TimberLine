<?php

use App\Models\ClientProject;
use App\Models\ClientProjectTicket;
use App\Models\ClientProjectTicketTask;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Services\Dashboard\UnifiedDashboardService;
use App\Services\Navigation\UnifiedAppNavigationService;
use App\Services\Tenancy\TenantModuleCatalogService;

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = Tenant::create(['name' => 'Website Client', 'slug' => 'website-client']);
    TenantAccessProfile::create(['tenant_id' => $this->tenant->id, 'plan_key' => 'starter', 'operating_mode' => 'direct', 'metadata' => ['workspace_focus' => 'website_sales']]);
    TenantModuleState::create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'enabled_override' => true]);
    $this->client = User::factory()->create(['role' => 'manager', 'is_active' => true]);
    $this->operator = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    foreach ([$this->client, $this->operator] as $user) {
        $user->tenants()->attach($this->tenant, ['role' => 'admin', 'membership_active' => true]);
    }
    $project = ClientProject::create(['tenant_id' => $this->tenant->id, 'title' => 'Launch', 'metadata' => ['checklist_enabled' => true]]);
    $ticket = ClientProjectTicket::create(['tenant_id' => $this->tenant->id, 'client_project_id' => $project->id, 'title' => 'Launch steps', 'problem_summary' => 'Launch', 'customer_visible' => true]);
    ClientProjectTicketTask::create(['tenant_id' => $this->tenant->id, 'client_project_ticket_id' => $ticket->id, 'title' => 'Review photos', 'owner_type' => 'client', 'status' => 'open']);
});

test('operator and client see identical focused tools without consulting unrelated financial spaces', function (): void {
    $this->mock(App\Services\Trajectory\FinanceAccess::class)->shouldNotReceive('spaces');
    $menus = [];
    foreach ([$this->client, $this->operator] as $user) {
        $this->actingAs($user)->get(route('dashboard', ['tenant' => $this->tenant->slug]))
            ->assertOk()->assertSeeText('Website & sales workspace')->assertSeeText('Website status')
            ->assertSeeText('Launch checklist')->assertDontSee('data-sidebar-key="trajectory"', false)
            ->assertDontSee('data-sidebar-key="marketing"', false)->assertDontSee('data-sidebar-key="administration"', false)
            ->assertDontSee('/shipping/orders')->assertDontSee('/analytics');
        $nav = app(UnifiedAppNavigationService::class)->build(request(), $user);
        $menus[] = collect($nav['items'])->pluck('key')->all();
        expect($nav['marketing_sub_groups'])->toBe([])->and($nav['admin_sub_items'])->toBe([]);
    }
    expect($menus[0])->toBe($menus[1])->toContain('website-products', 'website-customers', 'website-orders', 'website-leads', 'sales-channels');
    $catalog = app(TenantModuleCatalogService::class)->tenantStorePayload($this->tenant->id, 'marketing');
    expect(collect($catalog['modules'])->pluck('module_key')->all())->toBe(['managed_website']);
});

test('website dashboard uses only current tenant website records and visible checklist tasks', function (): void {
    $other = Tenant::create(['name' => 'Other website', 'slug' => 'other-website']);
    $otherSite = App\Models\TenantSite::create(['tenant_id' => $other->id, 'status' => 'draft', 'subdomain' => 'other-website']);
    App\Models\WebsiteProduct::create(['tenant_site_id' => $otherSite->id, 'tenant_id' => $other->id, 'handle' => 'other-product', 'title' => 'Other product', 'product_type' => 'quote', 'status' => 'active']);
    $this->actingAs($this->client)->get(route('dashboard', ['tenant' => $this->tenant->slug]))->assertOk();
    $dashboard = app(UnifiedDashboardService::class)->forRequest(request(), $this->client);
    expect($dashboard['hero']['value'])->toBe('Draft')
        ->and(collect($dashboard['summary_cards'])->pluck('value', 'label')->all())->toBe(['Launch checklist' => '0 / 1', 'Products' => '0', 'Customers' => '0', 'Orders' => '0'])
        ->and($dashboard['channel_pulse'])->toBeNull()->and($dashboard['owner_reporting'])->toBeNull();
});

test('focus does not grant website access or affect another workspace', function (): void {
    TenantModuleState::where('tenant_id', $this->tenant->id)->where('module_key', 'managed_website')->update(['enabled_override' => false]);
    $this->actingAs($this->client)->get(route('dashboard', ['tenant' => $this->tenant->slug]))->assertOk()
        ->assertDontSee('data-sidebar-key="website-products"', false)->assertSeeText('Unavailable');
    $this->get(route('managed-website.products.index', ['tenant' => $this->tenant->slug]))->assertForbidden();
    $other = Tenant::create(['name' => 'Regular workspace', 'slug' => 'regular-workspace']);
    TenantAccessProfile::create(['tenant_id' => $other->id, 'plan_key' => 'starter', 'operating_mode' => 'direct']);
    $this->operator->tenants()->attach($other, ['role' => 'admin', 'membership_active' => true]);
    $this->actingAs($this->operator)->get(route('dashboard', ['tenant' => $other->slug]))->assertOk()
        ->assertSee('data-sidebar-key="marketing"', false)->assertSee('data-sidebar-key="branches"', false);
});

test('focused search excludes legacy operations and inactive members cannot open its dashboard', function (): void {
    App\Models\Order::create(['tenant_id' => $this->tenant->id, 'order_number' => 'legacy-only', 'status' => 'open']);
    $this->actingAs($this->operator)->get(route('dashboard', ['tenant' => $this->tenant->slug]))->assertOk();
    $context = ['tenant_id' => $this->tenant->id, 'user' => $this->operator, 'request' => request()];
    $search = app(App\Services\Search\GlobalSearchCoordinator::class);
    expect($search->search('legacy-only', $context)['total'])->toBe(0);
    expect(collect($search->search('Products', $context)['results'])->pluck('url')->all())->toContain(route('managed-website.products.index', ['tenant' => $this->tenant->slug]));
    $this->client->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    $this->actingAs($this->client)->get(route('dashboard', ['tenant' => $this->tenant->slug]))->assertForbidden();
});
