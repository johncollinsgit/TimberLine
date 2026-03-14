<?php

namespace App\Support\Marketing;

class CandleCashSectionRegistry
{
    /**
     * @return array<string,array{label:string,description:string,accent:string}>
     */
    public static function groups(): array
    {
        return [
            'command' => [
                'label' => 'Overview',
                'description' => 'Program health, costs, and task performance.',
                'accent' => 'amber',
            ],
            'work' => [
                'label' => 'Tasks & Events',
                'description' => 'Tasks, verified events, and customer reward operations.',
                'accent' => 'emerald',
            ],
            'growth' => [
                'label' => 'Growth',
                'description' => 'Referrals, conversion tasks, and program rules.',
                'accent' => 'sky',
            ],
        ];
    }

    /**
     * @return array<string,array{label:string,route:string,description:string,group:string}>
     */
    public static function sections(): array
    {
        return [
            'dashboard' => [
                'label' => 'Dashboard',
                'route' => 'marketing.candle-cash',
                'description' => 'See balances issued, top tasks, pending approvals, and referral growth at a glance.',
                'group' => 'command',
            ],
            'tasks' => [
                'label' => 'Tasks',
                'route' => 'marketing.candle-cash.tasks',
                'description' => 'Control which Candle Cash tasks are live, how much they pay, and how they are completed.',
                'group' => 'work',
            ],
            'queue' => [
                'label' => 'Events',
                'route' => 'marketing.candle-cash.queue',
                'description' => 'See verified task events, duplicate blocks, and the small number of fallback/manual items.',
                'group' => 'work',
            ],
            'customers' => [
                'label' => 'Customers',
                'route' => 'marketing.candle-cash.customers',
                'description' => 'Inspect each customer’s balance, task history, referrals, and manual adjustments.',
                'group' => 'work',
            ],
            'referrals' => [
                'label' => 'Referrals',
                'route' => 'marketing.candle-cash.referrals',
                'description' => 'Track referrers, referred customers, qualifying orders, and reprocessing needs.',
                'group' => 'growth',
            ],
            'settings' => [
                'label' => 'Settings',
                'route' => 'marketing.candle-cash.settings',
                'description' => 'Configure Candle Cash copy, referral amounts, integration matching, and fraud rules.',
                'group' => 'growth',
            ],
        ];
    }

    public static function section(string $key): array
    {
        return self::sections()[$key] ?? self::sections()['dashboard'];
    }

    /**
     * @param array<int,array{key:string,label:string,href:string,current:bool}> $items
     * @return array<int,array{key:string,label:string,description:string,accent:string,current:bool,items:array<int,array{key:string,label:string,href:string,current:bool}>}>
     */
    public static function groupNavigationItems(array $items): array
    {
        $grouped = [];
        $groups = self::groups();

        foreach ($items as $item) {
            $section = self::sections()[$item['key']] ?? null;
            if (! $section) {
                continue;
            }

            $groupKey = $section['group'];
            if (! isset($grouped[$groupKey])) {
                $group = $groups[$groupKey] ?? [
                    'label' => ucfirst($groupKey),
                    'description' => '',
                    'accent' => 'amber',
                ];

                $grouped[$groupKey] = [
                    'key' => $groupKey,
                    'label' => $group['label'],
                    'description' => $group['description'],
                    'accent' => $group['accent'],
                    'current' => false,
                    'items' => [],
                ];
            }

            $grouped[$groupKey]['items'][] = $item;
            $grouped[$groupKey]['current'] = $grouped[$groupKey]['current'] || (bool) ($item['current'] ?? false);
        }

        return array_values($grouped);
    }
}
