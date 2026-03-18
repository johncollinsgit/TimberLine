<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\MarketingProfile;

test('embedded app navigation metadata matches each top-level section route', function (string $routeName, string $expectedSection, ?string $expectedChild, string $visibleText) {
    configureEmbeddedRetailStore();

    $response = $this->get(route($routeName, retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText($visibleText)
        ->assertViewHas('appNavigation', function (array $navigation) use ($expectedSection, $expectedChild): bool {
            return ($navigation['activeSection'] ?? null) === $expectedSection
                && ($navigation['activeChild'] ?? null) === $expectedChild;
        })
        ->assertViewHas('pageActions', fn (array $actions): bool => count($actions) === 0);
})->with([
    'dashboard' => ['home', 'dashboard', null, 'Rewards performance snapshot'],
    'rewards overview' => ['shopify.embedded.rewards', 'rewards', 'overview', 'Manage Candle Cash rewards and program settings.'],
    'rewards earn' => ['shopify.embedded.rewards.earn', 'rewards', 'earn', 'Ways to Earn'],
    'rewards redeem' => ['shopify.embedded.rewards.redeem', 'rewards', 'redeem', 'Ways to Redeem'],
    'rewards referrals' => ['shopify.embedded.rewards.referrals', 'rewards', 'referrals', 'Referrals coming soon'],
    'rewards birthdays' => ['shopify.embedded.rewards.birthdays', 'rewards', 'birthdays', 'Birthday rewards coming soon'],
    'rewards vip' => ['shopify.embedded.rewards.vip', 'rewards', 'vip', 'VIP experiences coming soon'],
    'rewards notifications' => ['shopify.embedded.rewards.notifications', 'rewards', 'notifications', 'Notifications coming soon'],
    'customers' => ['shopify.app.customers', 'customers', null, 'Manage customers'],
'settings' => ['shopify.app.settings', 'settings', null, 'Candle Cash program controls and messaging settings are managed in Backstage. This page will expose them soon.'],
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
    'customers root manage' => ['shopify.app.customers', 'manage', 'Manage customers'],
    'customers manage' => ['shopify.app.customers.manage', 'manage', 'Manage customers'],
    'customers activity' => ['shopify.app.customers.activity', 'activity', 'Activity'],
    'customers questions' => ['shopify.app.customers.questions', 'questions', 'Questions'],
    'customers alias root manage' => ['shopify.app.customers', 'manage', 'Manage customers'],
    'customers alias manage' => ['shopify.app.customers.manage', 'manage', 'Manage customers'],
    'customers alias activity' => ['shopify.app.customers.activity', 'activity', 'Activity'],
    'customers alias questions' => ['shopify.app.customers.questions', 'questions', 'Questions'],
]);

test('customers detail route and alias resolve with manage tab active', function (string $routeName) {
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
        ->assertSeeText('Marketing profile ID: '.$profile->id)
        ->assertViewHas('appNavigation', fn (array $navigation): bool => ($navigation['activeSection'] ?? null) === 'customers')
        ->assertViewHas('pageSubnav', function (array $subnav): bool {
            return collect($subnav)->contains(fn (array $item): bool => ($item['key'] ?? null) === 'manage' && ! empty($item['active']));
        });
})->with([
    'customers detail root route' => ['shopify.app.customers.detail'],
    'customers detail alias route' => ['shopify.app.customers.detail'],
]);

test('dashboard no longer shows oversized legacy action labels', function () {
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
        ->assertSee('<s-link href="/shopify/app">Dashboard</s-link>', false)
        ->assertSee('<s-link href="/shopify/app/rewards">Rewards</s-link>', false)
        ->assertSee('<s-link href="/shopify/app/customers/manage">Customers</s-link>', false)
        ->assertSee('<s-link href="/shopify/app/settings">Settings</s-link>', false);
});
