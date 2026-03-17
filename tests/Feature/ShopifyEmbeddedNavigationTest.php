<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

test('embedded app navigation metadata matches each sidebar route', function (string $routeName, string $expectedSection, ?string $expectedChild, string $visibleText, bool $shouldHaveActions) {
    configureEmbeddedRetailStore();

    $response = $this->get(route($routeName, retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText($visibleText)
        ->assertViewHas('appNavigation', function (array $navigation) use ($expectedSection, $expectedChild) {
            return ($navigation['activeSection'] ?? null) === $expectedSection
                && ($navigation['activeChild'] ?? null) === $expectedChild;
        })
        ->assertViewHas('pageActions', function (array $actions) use ($shouldHaveActions) {
            return $shouldHaveActions ? count($actions) > 0 : count($actions) === 0;
        });
})->with([
    'dashboard' => ['home', 'dashboard', null, 'Forestry rewards are connected', true],
    'rewards overview' => ['shopify.embedded.rewards', 'rewards', 'overview', 'Manage Candle Cash rewards and program settings.', false],
    'rewards earn' => ['shopify.embedded.rewards.earn', 'rewards', 'earn', 'Ways to Earn', false],
    'rewards redeem' => ['shopify.embedded.rewards.redeem', 'rewards', 'redeem', 'Ways to Redeem', false],
    'rewards referrals' => ['shopify.embedded.rewards.referrals', 'rewards', 'referrals', 'Referrals coming soon', false],
    'rewards birthdays' => ['shopify.embedded.rewards.birthdays', 'rewards', 'birthdays', 'Birthday rewards coming soon', false],
    'rewards vip' => ['shopify.embedded.rewards.vip', 'rewards', 'vip', 'VIP experiences coming soon', false],
    'rewards notifications' => ['shopify.embedded.rewards.notifications', 'rewards', 'notifications', 'Notifications coming soon', false],
    'customers' => ['shopify.embedded.customers', 'customers', null, 'Customer intelligence is arriving', false],
    'settings' => ['shopify.embedded.settings', 'settings', null, 'Program settings are coming into view', false],
]);
