<?php

use App\Services\Shopify\ShopifyEmbeddedPageRegistry;

test('shopify embedded page registry exposes unique page keys and canonical route names', function () {
    $registry = app(ShopifyEmbeddedPageRegistry::class);
    $pages = $registry->pages();

    $keys = collect($pages)
        ->map(fn (array $page): string => (string) ($page['key'] ?? ''))
        ->filter();
    $canonicalRoutes = collect($pages)
        ->map(fn (array $page): string => (string) ($page['route_name'] ?? ''))
        ->filter();

    expect($pages)->not->toBeEmpty()
        ->and($keys->count())->toBe($keys->unique()->count())
        ->and($canonicalRoutes->every(fn (string $name): bool => $name === 'shopify.app' || str_starts_with($name, 'shopify.app.')))->toBeTrue();
});

test('shopify embedded page registry resolves canonical routes from legacy aliases', function () {
    $registry = app(ShopifyEmbeddedPageRegistry::class);

    expect($registry->canonicalRouteName('shopify.embedded.rewards.earn'))->toBe('shopify.app.rewards.earn')
        ->and($registry->canonicalRouteName('shopify.embedded.customers'))->toBe('shopify.app.customers.manage')
        ->and($registry->canonicalRouteName('shopify.embedded.settings'))->toBe('shopify.app.settings')
        ->and($registry->canonicalRouteName('shopify.app.customers.manage'))->toBe('shopify.app.customers.manage');
});

test('shopify embedded page registry groups pages by expected navigation groups', function () {
    $registry = app(ShopifyEmbeddedPageRegistry::class);

    $primaryKeys = collect($registry->pagesForGroup('primary'))
        ->pluck('key')
        ->all();
    $customersSubnavKeys = collect($registry->pagesForGroup('customers_subnav'))
        ->pluck('key')
        ->all();
    $dashboardSubnavKeys = collect($registry->pagesForGroup('dashboard_subnav'))
        ->pluck('key')
        ->all();
    $rewardsChildKeys = collect($registry->pagesForGroup('rewards_children'))
        ->pluck('key')
        ->all();

    expect($primaryKeys)->toBe(['home', 'customers', 'messaging', 'rewards', 'settings'])
        ->and($customersSubnavKeys)->toBe(['customers.all', 'customers.segments', 'customers.activity', 'customers.imports'])
        ->and($dashboardSubnavKeys)->toBe(['home.start', 'home.plans', 'home.store', 'home.integrations'])
        ->and($rewardsChildKeys)->toBe([
            'rewards.overview',
            'rewards.earn',
            'rewards.redeem',
            'rewards.referrals',
            'rewards.birthdays',
            'rewards.vip',
            'rewards.notifications',
        ]);
});
