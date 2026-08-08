<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSetupStatus;
use App\Models\User;

beforeEach(function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST) ?: 'localhost';

    config()->set('tenancy.landlord.primary_host', $landlordHost);
    config()->set('tenancy.landlord.hosts', [$landlordHost]);
    config()->set('tenancy.landlord.operator_roles', ['platform_admin', 'admin']);
    config()->set('tenancy.landlord.operator_emails', []);
    config()->set('tenancy.auth.flagship_tenant_slug', 'modern-forestry');
    config()->set('tenancy.domains.tenant_base_domains', ['theeverbranch.com']);
});

function pr27ShellTenant(string $slug, string $name, string $accountMode = 'production'): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => $name,
        'slug' => $slug,
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => (int) $tenant->id,
        'plan_key' => 'base',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => ['account_mode' => $accountMode],
    ]);

    TenantSetupStatus::query()->create([
        'tenant_id' => (int) $tenant->id,
        'business_profile_status' => 'ready',
        'import_path' => 'manual',
        'shopify_connection_status' => 'not_applicable',
        'mobile_interest' => 'undecided',
        'landlord_review_status' => 'reviewed',
    ]);

    return $tenant;
}

test('tenant app shell keeps Home first and renders the cleaned sidebar shell', function (): void {
    $tenant = pr27ShellTenant('shell-client', 'Shell Client');
    $user = User::factory()->tenantAdmin()->create();
    $user->tenants()->attach((int) $tenant->id, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->get('http://shell-client.theeverbranch.com/dashboard')
        ->assertOk()
        ->assertSee('data-app-shell-topbar', false)
        ->assertSee('Search or ask what you want to do...', false)
        ->assertSee('data-assistant-entry', false)
        ->assertSee('href="/account-help"', false)
        ->assertSee('data-shell-context="tenant"', false)
        ->assertSee('mf-sidebar-brand-wordmark', false)
        ->assertSeeText('Everbranch')
        ->assertSeeText('Shell Client')
        ->assertSeeText('Everbranch Admin')
        ->assertSeeText('Marketing')
        ->assertSeeText('Work')
        ->assertSeeText($user->email)
        ->assertDontSee('data-sidebar-sortable', false)
        ->assertDontSee('mf-console-switches', false)
        ->assertDontSee('mf-sidebar-context', false)
        ->assertDontSee('data-sidebar-key="modules"', false)
        ->assertDontSeeText('Current Console')
        ->assertDontSeeText('Shortcuts')
        ->assertDontSeeText('Workspaces')
        ->assertDontSeeText('Forestry Backstage');

    $html = $response->getContent();
    $homePosition = strpos($html, 'data-sidebar-key="home"');
    $workPosition = strpos($html, 'data-sidebar-key="field-service"');

    expect($homePosition)->not->toBeFalse()
        ->and($workPosition)->not->toBeFalse()
        ->and($homePosition)->toBeLessThan($workPosition);
});

test('entitled Website is the first workspace link after Home', function (): void {
    $tenant = pr27ShellTenant('website-client', 'Website Client');
    $user = User::factory()->tenantAdmin()->create();
    $user->tenants()->attach((int) $tenant->id, ['role' => 'admin']);
    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'managed_website',
        'availability_status' => 'available',
        'enabled_status' => 'enabled',
        'billing_status' => 'add_on_paid',
        'entitlement_source' => 'test',
        'price_source' => 'catalog',
    ]);

    $html = $this->actingAs($user)
        ->get('http://website-client.theeverbranch.com/dashboard')
        ->assertOk()
        ->assertSee('data-sidebar-key="managed-website"', false)
        ->getContent();

    preg_match_all('/data-sidebar-key="([^"]+)"/', $html, $matches);
    $keys = $matches[1];
    $homeIndex = array_search('home', $keys, true);
    $websiteIndex = array_search('managed-website', $keys, true);

    expect($homeIndex)->not->toBeFalse()
        ->and($websiteIndex)->not->toBeFalse()
        ->and($websiteIndex)->toBe($homeIndex + 1);
});

test('landlord shell keeps Home first and uses Everbranch Admin navigation', function (): void {
    $user = User::factory()->platformAdmin()->create();

    $response = $this->actingAs($user)
        ->get(route('landlord.dashboard'))
        ->assertOk()
        ->assertSee('data-app-shell-topbar', false)
        ->assertSee('data-shell-context="landlord"', false)
        ->assertSeeText('Everbranch Admin')
        ->assertSeeText('Workspaces')
        ->assertSeeText('Access Requests')
        ->assertSeeText('Setup Reviews')
        ->assertSeeText('Branches')
        ->assertSee('data-sidebar-key="branches"', false)
        ->assertSee('href="'.route('landlord.branches.index').'"', false)
        ->assertSeeText('Features')
        ->assertSeeText('Custom Requests')
        ->assertSeeText('Plan / Billing Readiness')
        ->assertSee('data-sidebar-key="invoices"', false)
        ->assertSee('href="'.route('landlord.invoices.index').'"', false)
        ->assertSeeText('Shopify Readiness')
        ->assertSeeText('System Readiness')
        ->assertSee('Search or ask what you want to do...', false)
        ->assertSee('data-assistant-entry', false)
        ->assertSee('href="/assistant"', false)
        ->assertSee('mf-sidebar-brand-wordmark', false)
        ->assertDontSee('data-sidebar-sortable', false)
        ->assertDontSee('mf-sidebar-context', false)
        ->assertDontSeeText('Current Console')
        ->assertDontSeeText('Shortcuts')
        ->assertDontSeeText('Forestry Backstage');

    $html = $response->getContent();
    $expectedOrder = [
        'home',
        'access-requests',
        'agreements',
        'branches',
        'custom-requests',
        'developer',
        'features',
        'invoices',
        'plan-billing-readiness',
        'settings',
        'setup-reviews',
        'shopify-readiness',
        'system-readiness',
        'tickets',
        'workspaces',
    ];
    $positions = collect($expectedOrder)->mapWithKeys(
        fn (string $key): array => [$key => strpos($html, 'data-sidebar-key="'.$key.'"')]
    );

    foreach ($expectedOrder as $key) {
        expect($positions->get($key))->not->toBeFalse();
    }

    $orderedPositions = $positions->values()->all();
    $sortedPositions = $orderedPositions;
    sort($sortedPositions);

    expect($orderedPositions)->toBe($sortedPositions);
});

test('demo and sandbox banners remain visible inside the premium shell', function (string $slug, string $name, string $mode, string $banner): void {
    $tenant = pr27ShellTenant($slug, $name, $mode);
    $user = $mode === 'demo'
        ? User::factory()->demoUser()->create()
        : User::factory()->sandboxUser()->create();
    $user->tenants()->attach((int) $tenant->id, ['role' => 'admin']);

    $this->actingAs($user)
        ->get("http://{$slug}.theeverbranch.com/dashboard")
        ->assertOk()
        ->assertSee($banner)
        ->assertSee("data-access-lane-banner=\"{$mode}\"", false)
        ->assertSee('data-app-shell-topbar', false);
})->with([
    ['everbranch-demo', 'Everbranch Demo', 'demo', 'Viewing Demo Tenant'],
    ['sandbox-test-client', 'Sandbox Test Client', 'sandbox', 'Viewing Sandbox Test Tenant'],
]);

test('Modern Forestry remains tenant context rather than generic legacy branding', function (): void {
    $tenant = pr27ShellTenant('modern-forestry', 'Modern Forestry');
    $user = User::factory()->tenantAdmin()->create();
    $user->tenants()->attach((int) $tenant->id, ['role' => 'admin']);

    $this->actingAs($user)
        ->get('http://modern-forestry.theeverbranch.com/dashboard')
        ->assertOk()
        ->assertSeeText('Modern Forestry')
        ->assertSee('data-shell-context="tenant"', false)
        ->assertSee('mf-sidebar-brand-wordmark', false)
        ->assertSeeText('Everbranch')
        ->assertSeeText('Everbranch Admin')
        ->assertSeeText('Marketing')
        ->assertDontSee('mf-console-switches', false)
        ->assertDontSee('mf-sidebar-context', false)
        ->assertDontSeeText('Current Console')
        ->assertDontSeeText('Forestry Backstage')
        ->assertDontSeeText('Workspaces');
});
