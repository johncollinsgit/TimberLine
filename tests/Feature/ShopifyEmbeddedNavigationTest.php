<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\MarketingProfile;

beforeEach(function (): void {
    config()->set('entitlements.default_plan', 'growth');
});

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
    'rewards overview' => ['shopify.embedded.rewards', 'rewards', 'overview'],
    'rewards earn' => ['shopify.embedded.rewards.earn', 'rewards', 'earn'],
    'rewards redeem' => ['shopify.embedded.rewards.redeem', 'rewards', 'redeem'],
    'rewards referrals' => ['shopify.embedded.rewards.referrals', 'rewards', 'referrals'],
    'rewards birthdays' => ['shopify.embedded.rewards.birthdays', 'rewards', 'birthdays'],
    'rewards vip' => ['shopify.embedded.rewards.vip', 'rewards', 'vip'],
    'rewards notifications' => ['shopify.embedded.rewards.notifications', 'rewards', 'notifications'],
    'customers' => ['shopify.app.customers', 'customers', null],
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
        ->assertSee('<s-link href="/shopify/app?shop=', false)
        ->assertSee('<s-link href="/shopify/app/rewards?shop=', false)
        ->assertSee('<s-link href="/shopify/app/customers/manage?shop=', false)
        ->assertSee('<s-link href="/shopify/app/settings?shop=', false);
});

test('embedded navigation renders module-state indicators for placeholder and setup surfaces', function () {
    configureEmbeddedRetailStore();

    $this->get(route('shopify.embedded.rewards.referrals', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSee('data-module-state="coming_soon"', false);
});
