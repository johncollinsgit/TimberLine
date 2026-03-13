<?php

namespace App\Support\Birthdays;

class BirthdaySectionRegistry
{
    /**
     * @return array<string,array{label:string,description:string,accent:string}>
     */
    public static function groups(): array
    {
        return [
            'club' => [
                'label' => 'Club',
                'description' => 'Customers, campaigns, and day-to-day birthday work.',
                'accent' => 'rose',
            ],
            'performance' => [
                'label' => 'Performance',
                'description' => 'Rewards, activity, and conversion reporting.',
                'accent' => 'amber',
            ],
            'setup' => [
                'label' => 'Setup',
                'description' => 'Config and reward rules.',
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
            'customers' => [
                'label' => 'Customers',
                'route' => 'birthdays.customers',
                'description' => 'Import, search, and manage the birthday club customer list.',
                'group' => 'club',
            ],
            'campaigns' => [
                'label' => 'Campaigns',
                'route' => 'birthdays.campaigns',
                'description' => 'Birthday email and SMS message settings and performance.',
                'group' => 'club',
            ],
            'analytics' => [
                'label' => 'Analytics',
                'route' => 'birthdays.analytics',
                'description' => 'Signups, birthdays, sends, activations, and reward outcomes.',
                'group' => 'performance',
            ],
            'rewards' => [
                'label' => 'Rewards',
                'route' => 'birthdays.rewards',
                'description' => 'Birthday reward settings and issuance lifecycle.',
                'group' => 'performance',
            ],
            'activity' => [
                'label' => 'Activity',
                'route' => 'birthdays.activity',
                'description' => 'Imports, audits, sends, and reward changes.',
                'group' => 'performance',
            ],
            'settings' => [
                'label' => 'Settings',
                'route' => 'birthdays.settings',
                'description' => 'Capture rules, message timing, and Shopify-facing defaults.',
                'group' => 'setup',
            ],
        ];
    }

    /**
     * @return array{label:string,route:string,description:string,group:string}
     */
    public static function section(string $key): array
    {
        return self::sections()[$key] ?? self::sections()['customers'];
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
                    'accent' => 'rose',
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
