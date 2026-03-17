<?php

namespace App\Http\Controllers;

trait HandlesShopifyEmbeddedNavigation
{
    protected function embeddedAppNavigation(string $activeSection, ?string $activeChild = null): array
    {
        return [
            'items' => $this->embeddedAppNavigationItems(),
            'activeSection' => $activeSection,
            'activeChild' => $activeChild,
        ];
    }

    protected function embeddedAppNavigationItems(): array
    {
        return [
            [
                'key' => 'dashboard',
                'label' => 'Dashboard',
                'href' => route('home', [], false),
                'children' => [],
            ],
            [
                'key' => 'rewards',
                'label' => 'Rewards',
                'href' => route('shopify.embedded.rewards', [], false),
                'children' => [
                    ['key' => 'overview', 'label' => 'Overview', 'href' => route('shopify.embedded.rewards', [], false)],
                    ['key' => 'earn', 'label' => 'Ways to Earn', 'href' => route('shopify.embedded.rewards.earn', [], false)],
                    ['key' => 'redeem', 'label' => 'Ways to Redeem', 'href' => route('shopify.embedded.rewards.redeem', [], false)],
                    ['key' => 'referrals', 'label' => 'Referrals', 'href' => route('shopify.embedded.rewards.referrals', [], false)],
                    ['key' => 'birthdays', 'label' => 'Birthdays', 'href' => route('shopify.embedded.rewards.birthdays', [], false)],
                    ['key' => 'vip', 'label' => 'VIP', 'href' => route('shopify.embedded.rewards.vip', [], false)],
                    ['key' => 'notifications', 'label' => 'Notifications', 'href' => route('shopify.embedded.rewards.notifications', [], false)],
                ],
            ],
            [
                'key' => 'customers',
                'label' => 'Customers',
                'href' => route('shopify.embedded.customers', [], false),
                'children' => [],
            ],
            [
                'key' => 'settings',
                'label' => 'Settings',
                'href' => route('shopify.embedded.settings', [], false),
                'children' => [],
            ],
        ];
    }
}
