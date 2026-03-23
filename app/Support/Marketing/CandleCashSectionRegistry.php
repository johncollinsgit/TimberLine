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
                'description' => 'Start here for the current Candle Cash setup and health.',
                'accent' => 'amber',
            ],
            'work' => [
                'label' => 'Rules & Activity',
                'description' => 'Manage earning rules, redemptions, events, and customer balances.',
                'accent' => 'emerald',
            ],
            'growth' => [
                'label' => 'Referrals & Settings',
                'description' => 'Track referrals, gift performance, and program settings.',
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
                'label' => 'Overview',
                'route' => 'marketing.candle-cash',
                'description' => 'Manage Candle Cash rewards and program settings.',
                'group' => 'command',
            ],
            'tasks' => [
                'label' => 'Ways to Earn',
                'route' => 'marketing.candle-cash.tasks',
                'description' => 'Review and manage the live earn rules already powering Candle Cash.',
                'group' => 'work',
            ],
            'redeem' => [
                'label' => 'Ways to Redeem',
                'route' => 'marketing.candle-cash.redeem',
                'description' => 'Review and manage the live reward rows customers can currently redeem.',
                'group' => 'work',
            ],
            'queue' => [
                'label' => 'Activity Queue',
                'route' => 'marketing.candle-cash.queue',
                'description' => 'See verified task events, duplicate blocks, and the small number of fallback/manual items.',
                'group' => 'work',
            ],
            'reviews' => [
                'label' => 'Reviews',
                'route' => 'marketing.candle-cash.reviews',
                'description' => 'Manage native product reviews, Growave imports, and moderation decisions.',
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
            'gifts-report' => [
                'label' => 'Gift Performance',
                'route' => 'marketing.candle-cash.gifts-report',
                'description' => 'Surface Candle Cash send intent, notification status, and post-gift conversions.',
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
