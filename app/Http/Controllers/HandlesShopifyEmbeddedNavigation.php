<?php

namespace App\Http\Controllers;

use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Services\Tenancy\TenantModuleAccessResolver;

trait HandlesShopifyEmbeddedNavigation
{
    protected function embeddedAppNavigation(string $activeSection, ?string $activeChild = null, ?int $tenantId = null): array
    {
        $displayLabels = $this->embeddedDisplayLabels($tenantId);
        $items = $this->embeddedAppNavigationItems($displayLabels);
        $moduleStates = $this->embeddedNavigationModuleStates($tenantId);
        $items = $this->attachEmbeddedNavigationModuleStates($items, $moduleStates);

        return [
            'items' => $items,
            'activeSection' => $activeSection,
            'activeChild' => $activeChild,
            'moduleStates' => $moduleStates,
            'tenantId' => $tenantId,
            'displayLabels' => $displayLabels,
        ];
    }

    /**
     * @param  array<string,string>  $displayLabels
     */
    protected function embeddedAppNavigationItems(array $displayLabels = []): array
    {
        $rewardsLabel = trim((string) ($displayLabels['rewards_label'] ?? $displayLabels['rewards'] ?? ''));
        if ($rewardsLabel === '') {
            $rewardsLabel = 'Rewards';
        }

        return [
            [
                'key' => 'dashboard',
                'label' => 'Dashboard',
                'href' => route('home', [], false),
                'children' => [],
            ],
            [
                'key' => 'rewards',
                'label' => $rewardsLabel,
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
                'href' => route('shopify.app.customers', [], false),
                'children' => [],
            ],
            [
                'key' => 'settings',
                'label' => 'Settings',
                'href' => route('shopify.app.settings', [], false),
                'children' => [],
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    protected function embeddedDisplayLabels(?int $tenantId): array
    {
        /** @var TenantDisplayLabelResolver $resolver */
        $resolver = app(TenantDisplayLabelResolver::class);
        $resolved = $resolver->resolve($tenantId);

        return is_array($resolved['labels'] ?? null)
            ? (array) $resolved['labels']
            : [];
    }

    /**
     * @return array<string,array{
     *   module_key:string,
     *   label:string,
     *   classification:string,
     *   has_access:bool,
     *   access_sources:array<int,string>,
     *   setup_status:string,
     *   coming_soon:bool,
     *   ui_state:string,
     *   upgrade_prompt_eligible:bool
     * }>
     */
    protected function embeddedNavigationModuleStates(?int $tenantId): array
    {
        $moduleKeys = [
            'dashboard',
            'rewards',
            'birthdays',
            'referrals',
            'vip',
            'notifications',
            'customers',
            'activity',
            'questions',
            'settings',
        ];

        /** @var TenantModuleAccessResolver $resolver */
        $resolver = app(TenantModuleAccessResolver::class);
        $resolved = $resolver->resolveForTenant($tenantId, $moduleKeys);

        return (array) ($resolved['modules'] ?? []);
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<string,array<string,mixed>>  $moduleStates
     * @return array<int,array<string,mixed>>
     */
    protected function attachEmbeddedNavigationModuleStates(array $items, array $moduleStates): array
    {
        $topLevelModuleMap = [
            'dashboard' => 'dashboard',
            'rewards' => 'rewards',
            'customers' => 'customers',
            'settings' => 'settings',
        ];

        $childModuleMap = [
            'overview' => 'rewards',
            'earn' => 'rewards',
            'redeem' => 'rewards',
            'referrals' => 'referrals',
            'birthdays' => 'birthdays',
            'vip' => 'vip',
            'notifications' => 'notifications',
            'manage' => 'customers',
            'activity' => 'activity',
            'questions' => 'questions',
        ];

        return array_map(function (array $item) use ($topLevelModuleMap, $childModuleMap, $moduleStates): array {
            $itemKey = strtolower(trim((string) ($item['key'] ?? '')));
            $moduleKey = $topLevelModuleMap[$itemKey] ?? null;
            if ($moduleKey !== null && isset($moduleStates[$moduleKey])) {
                $item['module_state'] = $moduleStates[$moduleKey];
            }

            $children = is_array($item['children'] ?? null) ? $item['children'] : [];
            if ($children === []) {
                return $item;
            }

            $item['children'] = array_map(function (array $child) use ($childModuleMap, $moduleStates): array {
                $childKey = strtolower(trim((string) ($child['key'] ?? '')));
                $moduleKey = $childModuleMap[$childKey] ?? null;
                if ($moduleKey !== null && isset($moduleStates[$moduleKey])) {
                    $child['module_state'] = $moduleStates[$moduleKey];
                }

                return $child;
            }, $children);

            return $item;
        }, $items);
    }
}
