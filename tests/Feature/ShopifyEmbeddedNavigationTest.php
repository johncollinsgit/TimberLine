<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;

beforeEach(function (): void {
    config()->set('entitlements.default_plan', 'growth');
});

function grantMessagingEntitlement(Tenant $tenant): void
{
    TenantModuleEntitlement::query()->updateOrCreate(
        [
            'tenant_id' => $tenant->id,
            'module_key' => 'messaging',
        ],
        [
            'availability_status' => 'available',
            'enabled_status' => 'enabled',
            'billing_status' => 'add_on_comped',
            'currency' => 'USD',
            'entitlement_source' => 'test',
            'price_source' => 'test',
        ]
    );
}

test('embedded app navigation metadata matches each top-level section route', function (string $routeName, string $expectedSection, ?string $expectedChild) {
    configureEmbeddedRetailStore();

    $response = $this->get(route($routeName, retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertViewHas('appNavigation', function (array $navigation) use ($expectedSection, $expectedChild): bool {
            return ($navigation['activeSection'] ?? null) === $expectedSection
                && ($navigation['activeChild'] ?? null) === $expectedChild;
        })
        ->assertViewHas('pageActions', fn (array $actions): bool => count($actions) === 0);
})->with([
    'home' => ['home', 'home', null],
    'rewards overview' => ['shopify.app.rewards', 'rewards', 'overview'],
    'rewards earn' => ['shopify.app.rewards.earn', 'rewards', 'earn'],
    'rewards redeem' => ['shopify.app.rewards.redeem', 'rewards', 'redeem'],
    'rewards referrals' => ['shopify.app.rewards.referrals', 'rewards', 'referrals'],
    'rewards birthdays' => ['shopify.app.rewards.birthdays', 'rewards', 'birthdays'],
    'rewards vip' => ['shopify.app.rewards.vip', 'rewards', 'vip'],
    'rewards notifications' => ['shopify.app.rewards.notifications', 'rewards', 'notifications'],
    'customers' => ['shopify.app.customers', 'customers', null],
    'messaging workspace' => ['shopify.app.messaging', 'messaging', 'workspace'],
    'messaging analytics' => ['shopify.app.messaging.analytics', 'messaging', 'analytics'],
    'messaging responses' => ['shopify.app.messaging.responses', 'messaging', 'responses'],
    'settings' => ['shopify.app.settings', 'settings', null],
]);

test('customers routes and aliases keep customers section active with correct subnav tab', function (string $routeName, string $activeTab, string $visibleText) {
    configureEmbeddedRetailStore();

    $response = $this->get(route($routeName, retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText($visibleText)
        ->assertViewHas('appNavigation', fn (array $navigation): bool => ($navigation['activeSection'] ?? null) === 'customers')
        ->assertViewHas('pageSubnav', function (array $subnav) use ($activeTab): bool {
            return collect($subnav)->contains(fn (array $item): bool => ($item['key'] ?? null) === $activeTab && ! empty($item['active']));
        });
})->with([
    'customers root manage' => ['shopify.app.customers', 'all', 'All customers'],
    'customers manage' => ['shopify.app.customers.manage', 'all', 'All customers'],
    'customers segments' => ['shopify.app.customers.segments', 'segments', 'Segments'],
    'customers activity' => ['shopify.app.customers.activity', 'activity', 'Activity'],
    'customers imports' => ['shopify.app.customers.imports', 'imports', 'Imports'],
    'customers alias root manage' => ['shopify.app.customers', 'all', 'All customers'],
    'customers alias manage' => ['shopify.app.customers.manage', 'all', 'All customers'],
    'customers alias segments' => ['shopify.app.customers.segments', 'segments', 'Segments'],
    'customers alias activity' => ['shopify.app.customers.activity', 'activity', 'Activity'],
    'customers alias imports' => ['shopify.app.customers.imports', 'imports', 'Imports'],
]);

test('legacy customers questions route redirects to imports', function () {
    configureEmbeddedRetailStore();

    $response = $this->get(route('shopify.app.customers.questions', retailEmbeddedSignedQuery()));

    $response->assertRedirect();
    expect($response->headers->get('Location', ''))->toContain('/shopify/app/customers/imports');
});

test('messaging routes expose messaging subnav with expected active tab', function (string $routeName, string $activeTab, string $visibleText) {
    $tenant = Tenant::query()->create([
        'name' => 'Messaging Subnav Tenant',
        'slug' => 'messaging-subnav-tenant',
    ]);
    grantMessagingEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $response = $this->get(route($routeName, retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText($visibleText)
        ->assertViewHas('appNavigation', fn (array $navigation): bool => ($navigation['activeSection'] ?? null) === 'messaging')
        ->assertViewHas('pageSubnav', function (array $subnav) use ($activeTab): bool {
            return collect($subnav)->contains(fn (array $item): bool => ($item['key'] ?? null) === $activeTab && ! empty($item['active']));
        });
})->with([
    'messaging workspace tab' => ['shopify.app.messaging', 'workspace', 'Messages Workspace'],
    'messaging analytics tab' => ['shopify.app.messaging.analytics', 'analytics', 'Message Analytics'],
    'messaging responses tab' => ['shopify.app.messaging.responses', 'responses', 'Responses'],
]);

test('legacy customers route redirects to all customers', function () {
    configureEmbeddedRetailStore();

    $response = $this->get(route('shopify.embedded.customers', retailEmbeddedSignedQuery()));

    $response->assertRedirect();
    expect($response->headers->get('Location', ''))->toContain('/shopify/app/customers/manage');
});

test('customers detail route and alias resolve with all customers tab active', function (string $routeName) {
    configureEmbeddedRetailStore();

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Avery',
        'last_name' => 'Stone',
        'email' => 'avery@example.com',
        'normalized_email' => 'avery@example.com',
    ]);

    $response = $this->get(route($routeName, array_merge(
        ['marketingProfile' => $profile->id],
        retailEmbeddedSignedQuery()
    )));

    $response->assertOk()
        ->assertSeeText('Customer Detail')
        ->assertSeeText('Profile #'.$profile->id)
        ->assertViewHas('appNavigation', fn (array $navigation): bool => ($navigation['activeSection'] ?? null) === 'customers')
        ->assertViewHas('pageSubnav', function (array $subnav): bool {
            return collect($subnav)->contains(fn (array $item): bool => ($item['key'] ?? null) === 'all' && ! empty($item['active']));
        });
})->with([
    'customers detail root route' => ['shopify.app.customers.detail'],
    'customers detail alias route' => ['shopify.app.customers.detail'],
]);

test('home no longer shows oversized legacy action labels', function () {
    configureEmbeddedRetailStore();

    $this->get(route('home', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertDontSeeText('Open Rewards Admin')
        ->assertDontSeeText('Open Birthdays in Backstage')
        ->assertDontSeeText('Open Marketing Overview')
        ->assertDontSeeText('Open Birthday Rewards');
});

test('embedded shell renders shopify app nav with top-level links', function () {
    configureEmbeddedRetailStore();

    $response = $this->get(route('home', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSee('<s-app-nav>', false)
        ->assertSee('rel="home"', false)
        ->assertSee('href="/shopify/app?shop=', false)
        ->assertSee('href="/shopify/app/customers/manage?shop=', false)
        ->assertSee('href="/shopify/app/messaging?shop=', false)
        ->assertSee('href="/shopify/app/rewards?shop=', false)
        ->assertSee('href="/shopify/app/settings?shop=', false);
});

test('embedded navigation metadata keeps expected top-level labels and order', function () {
    configureEmbeddedRetailStore();

    $response = $this->get(route('home', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertViewHas('appNavigation', function (array $navigation): bool {
            $items = array_values(array_filter(is_array($navigation['items'] ?? null) ? $navigation['items'] : [], 'is_array'));
            $labels = array_map(static fn (array $item): string => (string) ($item['label'] ?? ''), $items);

            return ($navigation['activeSection'] ?? null) === 'home'
                && count($items) >= 4
                && $labels[0] === 'Dashboard';
        });
});

test('embedded shell includes messaging nav link when messaging entitlement is enabled', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Messaging Nav Tenant',
        'slug' => 'messaging-nav-tenant',
    ]);
    grantMessagingEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $response = $this->get(route('home', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSee('href="/shopify/app/messaging?shop=', false);
});

test('embedded navigation order includes messaging when module access is enabled', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Messaging Nav Order Tenant',
        'slug' => 'messaging-nav-order-tenant',
    ]);
    grantMessagingEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $response = $this->get(route('home', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertViewHas('appNavigation', function (array $navigation): bool {
            $items = array_values(array_filter(is_array($navigation['items'] ?? null) ? $navigation['items'] : [], 'is_array'));
            $keys = array_map(static fn (array $item): string => (string) ($item['key'] ?? ''), $items);
            $labels = array_map(static fn (array $item): string => (string) ($item['label'] ?? ''), $items);

            return $keys === ['home', 'customers', 'messaging', 'rewards', 'settings']
                && $labels[2] === 'Messages';
        });
});

test('embedded navigation renders module-state indicators for placeholder and setup surfaces', function () {
    configureEmbeddedRetailStore();

    $this->get(route('shopify.app.rewards.referrals', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSee('data-module-state="coming_soon"', false);
});

test('legacy rewards routes redirect to canonical embedded app routes with context intact', function () {
    configureEmbeddedRetailStore();

    $response = $this->get(route('shopify.embedded.rewards.earn', retailEmbeddedExtendedSignedQuery()));

    $response->assertRedirect();

    $location = $response->headers->get('Location', '');
    expect($location)->toContain('/shopify/app/rewards/earn')
        ->toContain('shop=modernforestry.myshopify.com')
        ->toContain('host=admin-host-token')
        ->toContain('embedded=1')
        ->toContain('id_token=')
        ->toContain('session=embedded-session-token');
});

test('embedded shell includes lightweight internal prefetch hooks for embedded links', function () {
    configureEmbeddedRetailStore();

    $this->get(route('home', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSee('data-embedded-prefetch-link="1"', false)
        ->assertSee('__fbEmbeddedLinkPrefetchBound', false)
        ->assertSee('X-Forestry-Prefetch', false);
});

test('embedded shell mounts shopify command menu with registry-backed route discovery payload', function () {
    configureEmbeddedRetailStore();

    $this->get(route('home', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSee('data-shopify-global-command-menu', false)
        ->assertDontSee('data-app-command-palette', false)
        ->assertSee('"id":"page:settings"', false)
        ->assertSee('"section":"pages"', false);
});

test('embedded command menu includes messaging analytics document when messaging access is enabled', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Messaging Search Tenant',
        'slug' => 'messaging-search-tenant',
    ]);
    grantMessagingEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('home', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSee('"id":"page:messaging.analytics"', false)
        ->assertSee('message analytics', false);
});

test('embedded topbar search exposes accessible command menu controls and endpoint fallback wiring', function () {
    configureEmbeddedRetailStore();

    $this->get(route('home', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSee('id="app-topbar-command-search"', false)
        ->assertSee('aria-controls="shopify-global-command-menu-panel"', false)
        ->assertSee('aria-haspopup="dialog"', false)
        ->assertSee('aria-expanded="false"', false)
        ->assertDontSee('action="/shopify/app/api/search"', false);
});
