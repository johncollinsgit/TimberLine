<?php

namespace App\Services\Shopify;

class ShopifyEmbeddedPageRegistry
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function pages(): array
    {
        return [
            [
                'key' => 'home',
                'route_name' => 'shopify.app',
                'legacy_route_names' => ['home'],
                'label' => 'Home',
                'section' => 'home',
                'group' => 'primary',
                'icon_key' => 'home',
                'module_key' => 'dashboard',
                'searchable' => true,
                'search_badge' => 'Section',
                'search_subtitle' => 'Revenue, setup progress, and recent activity.',
                'search_keywords' => ['dashboard', 'overview', 'home'],
                'prefetch_priority' => 'high',
            ],
            [
                'key' => 'home.start',
                'route_name' => 'shopify.app.start',
                'label' => 'Start Here',
                'section' => 'home',
                'group' => 'dashboard_subnav',
                'icon_key' => 'sparkles',
                'module_key' => 'onboarding',
                'searchable' => true,
                'search_badge' => 'Setup',
                'search_subtitle' => 'Walk through onboarding and launch steps.',
                'search_keywords' => ['setup', 'getting started', 'onboarding'],
                'prefetch_priority' => 'normal',
            ],
            [
                'key' => 'home.plans',
                'route_name' => 'shopify.app.plans',
                'label' => 'Plans & Add-ons',
                'section' => 'home',
                'group' => 'dashboard_subnav',
                'icon_key' => 'credit-card',
                'module_key' => 'onboarding',
                'searchable' => true,
                'search_badge' => 'Setup',
                'search_subtitle' => 'Review plan options and add-on modules.',
                'search_keywords' => ['plans', 'addons', 'pricing'],
                'prefetch_priority' => 'normal',
            ],
            [
                'key' => 'home.store',
                'route_name' => 'shopify.app.store',
                'label' => 'App Store',
                'section' => 'home',
                'group' => 'dashboard_subnav',
                'icon_key' => 'squares-plus',
                'module_key' => 'onboarding',
                'searchable' => true,
                'search_badge' => 'Modules',
                'search_subtitle' => 'Browse modules available for this store.',
                'search_keywords' => ['modules', 'store', 'apps'],
                'prefetch_priority' => 'normal',
            ],
            [
                'key' => 'home.integrations',
                'route_name' => 'shopify.app.integrations',
                'label' => 'Integrations',
                'section' => 'home',
                'group' => 'dashboard_subnav',
                'icon_key' => 'link',
                'module_key' => 'integrations',
                'searchable' => true,
                'search_badge' => 'Sync',
                'search_subtitle' => 'Customer sync and connected app health.',
                'search_keywords' => ['sync', 'imports', 'integrations'],
                'prefetch_priority' => 'normal',
            ],
            [
                'key' => 'customers',
                'route_name' => 'shopify.app.customers.manage',
                'legacy_route_names' => ['shopify.app.customers', 'shopify.embedded.customers', 'shopify.embedded.customers.manage'],
                'label' => 'Customers',
                'section' => 'customers',
                'group' => 'primary',
                'icon_key' => 'users',
                'module_key' => 'customers',
                'searchable' => true,
                'search_badge' => 'Customers',
                'search_subtitle' => 'Search profiles, segments, and customer activity.',
                'search_keywords' => ['customers', 'profiles', 'audience'],
                'prefetch_priority' => 'high',
            ],
            [
                'key' => 'customers.all',
                'route_name' => 'shopify.app.customers.manage',
                'label' => 'All customers',
                'section' => 'customers',
                'group' => 'customers_subnav',
                'icon_key' => 'users',
                'module_key' => 'customers',
                'searchable' => false,
                'prefetch_priority' => 'high',
            ],
            [
                'key' => 'customers.segments',
                'route_name' => 'shopify.app.customers.segments',
                'legacy_route_names' => ['shopify.embedded.customers.segments'],
                'label' => 'Segments',
                'section' => 'customers',
                'group' => 'customers_subnav',
                'icon_key' => 'funnel',
                'module_key' => 'customers',
                'searchable' => true,
                'search_badge' => 'Customers',
                'search_subtitle' => 'Review reusable customer groupings.',
                'search_keywords' => ['segments', 'groups', 'customers'],
                'prefetch_priority' => 'normal',
            ],
            [
                'key' => 'customers.activity',
                'route_name' => 'shopify.app.customers.activity',
                'legacy_route_names' => ['shopify.embedded.customers.activity'],
                'label' => 'Activity',
                'section' => 'customers',
                'group' => 'customers_subnav',
                'icon_key' => 'clock',
                'module_key' => 'activity',
                'searchable' => true,
                'search_badge' => 'Customers',
                'search_subtitle' => 'See recent customer and rewards events.',
                'search_keywords' => ['activity', 'events', 'customers'],
                'prefetch_priority' => 'normal',
            ],
            [
                'key' => 'customers.imports',
                'route_name' => 'shopify.app.customers.imports',
                'legacy_route_names' => ['shopify.embedded.customers.imports', 'shopify.embedded.customers.questions', 'shopify.app.customers.questions'],
                'label' => 'Imports',
                'section' => 'customers',
                'group' => 'customers_subnav',
                'icon_key' => 'arrow-down-tray',
                'module_key' => 'customers',
                'searchable' => true,
                'search_badge' => 'Sync',
                'search_subtitle' => 'Inspect imports and sync history.',
                'search_keywords' => ['imports', 'sync', 'customers'],
                'prefetch_priority' => 'normal',
            ],
            [
                'key' => 'customers.detail',
                'route_name' => 'shopify.app.customers.detail',
                'legacy_route_names' => ['shopify.embedded.customers.detail'],
                'label' => 'Customer Detail',
                'section' => 'customers',
                'group' => 'customer_detail',
                'icon_key' => 'user',
                'module_key' => 'customers',
                'searchable' => false,
                'prefetch_priority' => 'normal',
            ],
            [
                'key' => 'messaging',
                'route_name' => 'shopify.app.messaging',
                'legacy_route_names' => ['shopify.embedded.messaging'],
                'label' => 'Messages',
                'section' => 'messaging',
                'group' => 'primary',
                'icon_key' => 'chat-bubble-left-right',
                'module_key' => 'messaging',
                'requires_enabled_access' => true,
                'searchable' => true,
                'search_badge' => 'Messaging',
                'search_subtitle' => 'Send direct messages to individuals, groups, and subscribed audiences.',
                'search_keywords' => ['messaging', 'sms', 'email', 'groups', 'subscribed'],
                'prefetch_priority' => 'high',
            ],
            [
                'key' => 'rewards',
                'route_name' => 'shopify.app.rewards',
                'legacy_route_names' => ['shopify.embedded.rewards'],
                'label' => 'Rewards',
                'label_key' => 'rewards_label',
                'section' => 'rewards',
                'group' => 'primary',
                'icon_key' => 'gift',
                'module_key' => 'rewards',
                'searchable' => true,
                'search_badge' => 'Rewards',
                'search_subtitle' => 'Review performance, rules, and live status.',
                'search_keywords' => ['rewards', 'loyalty', 'analytics'],
                'prefetch_priority' => 'high',
            ],
            [
                'key' => 'rewards.overview',
                'route_name' => 'shopify.app.rewards',
                'legacy_route_names' => ['shopify.embedded.rewards'],
                'label' => 'Overview',
                'section' => 'rewards',
                'group' => 'rewards_children',
                'icon_key' => 'chart-bar',
                'module_key' => 'rewards',
                'searchable' => false,
                'prefetch_priority' => 'normal',
            ],
            [
                'key' => 'rewards.earn',
                'route_name' => 'shopify.app.rewards.earn',
                'legacy_route_names' => ['shopify.embedded.rewards.earn'],
                'label' => 'Ways to Earn',
                'section' => 'rewards',
                'group' => 'rewards_children',
                'icon_key' => 'sparkles',
                'module_key' => 'rewards',
                'searchable' => true,
                'search_badge' => 'Rewards',
                'search_subtitle' => 'Manage how customers earn rewards.',
                'search_keywords' => ['earn', 'rules', 'rewards'],
                'prefetch_priority' => 'normal',
            ],
            [
                'key' => 'rewards.redeem',
                'route_name' => 'shopify.app.rewards.redeem',
                'legacy_route_names' => ['shopify.embedded.rewards.redeem'],
                'label' => 'Ways to Redeem',
                'section' => 'rewards',
                'group' => 'rewards_children',
                'icon_key' => 'ticket',
                'module_key' => 'rewards',
                'searchable' => true,
                'search_badge' => 'Rewards',
                'search_subtitle' => 'Manage redemption options and discounts.',
                'search_keywords' => ['redeem', 'discounts', 'rewards'],
                'prefetch_priority' => 'normal',
            ],
            [
                'key' => 'rewards.referrals',
                'route_name' => 'shopify.app.rewards.referrals',
                'legacy_route_names' => ['shopify.embedded.rewards.referrals'],
                'label' => 'Referrals',
                'section' => 'rewards',
                'group' => 'rewards_children',
                'icon_key' => 'megaphone',
                'module_key' => 'referrals',
                'searchable' => false,
                'prefetch_priority' => 'low',
            ],
            [
                'key' => 'rewards.birthdays',
                'route_name' => 'shopify.app.rewards.birthdays',
                'legacy_route_names' => ['shopify.embedded.rewards.birthdays'],
                'label' => 'Birthdays',
                'section' => 'rewards',
                'group' => 'rewards_children',
                'icon_key' => 'cake',
                'module_key' => 'birthdays',
                'searchable' => false,
                'prefetch_priority' => 'low',
            ],
            [
                'key' => 'rewards.vip',
                'route_name' => 'shopify.app.rewards.vip',
                'legacy_route_names' => ['shopify.embedded.rewards.vip'],
                'label' => 'VIP',
                'section' => 'rewards',
                'group' => 'rewards_children',
                'icon_key' => 'star',
                'module_key' => 'vip',
                'searchable' => false,
                'prefetch_priority' => 'low',
            ],
            [
                'key' => 'rewards.notifications',
                'route_name' => 'shopify.app.rewards.notifications',
                'legacy_route_names' => ['shopify.embedded.rewards.notifications'],
                'label' => 'Notifications',
                'section' => 'rewards',
                'group' => 'rewards_children',
                'icon_key' => 'bell',
                'module_key' => 'rewards',
                'searchable' => true,
                'search_badge' => 'Rewards',
                'search_subtitle' => 'Configure reminder and program messaging.',
                'search_keywords' => ['notifications', 'emails', 'reminders'],
                'prefetch_priority' => 'normal',
            ],
            [
                'key' => 'settings',
                'route_name' => 'shopify.app.settings',
                'legacy_route_names' => ['shopify.embedded.settings'],
                'label' => 'Settings',
                'section' => 'settings',
                'group' => 'primary',
                'icon_key' => 'cog-6-tooth',
                'module_key' => 'settings',
                'searchable' => true,
                'search_badge' => 'Settings',
                'search_subtitle' => 'Email sender, branding, and workspace preferences.',
                'search_keywords' => ['settings', 'email', 'preferences'],
                'prefetch_priority' => 'normal',
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function pagesForGroup(string $group): array
    {
        return array_values(array_filter(
            $this->pages(),
            fn (array $page): bool => (string) ($page['group'] ?? '') === $group
        ));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function pagesForSection(string $section): array
    {
        return array_values(array_filter(
            $this->pages(),
            fn (array $page): bool => (string) ($page['section'] ?? '') === $section
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function pageByKey(string $key): ?array
    {
        foreach ($this->pages() as $page) {
            if ((string) ($page['key'] ?? '') === $key) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function pageByRouteName(string $routeName): ?array
    {
        $normalizedRoute = strtolower(trim($routeName));
        if ($normalizedRoute === '') {
            return null;
        }

        foreach ($this->pages() as $page) {
            if (strtolower((string) ($page['route_name'] ?? '')) === $normalizedRoute) {
                return $page;
            }

            $aliases = is_array($page['legacy_route_names'] ?? null)
                ? (array) $page['legacy_route_names']
                : [];

            foreach ($aliases as $aliasRoute) {
                if (strtolower(trim((string) $aliasRoute)) === $normalizedRoute) {
                    return $page;
                }
            }
        }

        return null;
    }

    public function canonicalRouteName(string $routeName): string
    {
        $page = $this->pageByRouteName($routeName);

        if (! is_array($page)) {
            return $routeName;
        }

        return (string) ($page['route_name'] ?? $routeName);
    }
}
