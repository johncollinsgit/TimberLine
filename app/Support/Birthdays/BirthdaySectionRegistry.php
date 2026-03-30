<?php

namespace App\Support\Birthdays;

use App\Services\Tenancy\TenantDisplayLabelResolver;

class BirthdaySectionRegistry
{
    /**
     * @return array<string,array{label:string,description:string,accent:string}>
     */
    public static function groups(): array
    {
        return self::replaceLabelTokens([
            'club' => [
                'label' => 'Club',
                'description' => 'Customers, campaigns, and day-to-day birthday work.',
                'accent' => 'rose',
            ],
            'performance' => [
                'label' => 'Performance',
                'description' => '{{birthday_reward_label}} activity and conversion reporting.',
                'accent' => 'amber',
            ],
            'setup' => [
                'label' => 'Setup',
                'description' => 'Config and reward rules.',
                'accent' => 'sky',
            ],
        ]);
    }

    /**
     * @return array<string,array{label:string,route:string,description:string,group:string}>
     */
    public static function sections(): array
    {
        return self::replaceLabelTokens([
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
                'label' => '{{birthdays_label}}',
                'route' => 'birthdays.rewards',
                'description' => '{{birthday_reward_label}} settings and issuance lifecycle.',
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
        ]);
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

    /**
     * @param  array<mixed,mixed>  $value
     * @return array<mixed,mixed>
     */
    protected static function replaceLabelTokens(array $value): array
    {
        $tokens = self::labelTokens();

        $replace = function (mixed $item) use (&$replace, $tokens): mixed {
            if (is_array($item)) {
                $updated = [];
                foreach ($item as $key => $nested) {
                    $updated[$key] = $replace($nested);
                }

                return $updated;
            }

            if (is_string($item)) {
                return strtr($item, $tokens);
            }

            return $item;
        };

        return $replace($value);
    }

    /**
     * @return array<string,string>
     */
    protected static function labelTokens(): array
    {
        $tenantId = request()?->attributes->get('current_tenant_id');
        $resolvedTenantId = is_numeric($tenantId) ? (int) $tenantId : null;

        /** @var TenantDisplayLabelResolver $resolver */
        $resolver = app(TenantDisplayLabelResolver::class);
        $resolved = $resolver->resolve($resolvedTenantId);
        $labels = is_array($resolved['labels'] ?? null) ? (array) $resolved['labels'] : [];

        $birthdaysLabel = trim((string) ($labels['birthdays_label'] ?? $labels['birthdays'] ?? 'Rewards'));
        if ($birthdaysLabel === '') {
            $birthdaysLabel = 'Rewards';
        }

        $birthdayRewardLabel = trim((string) ($labels['birthday_reward_label'] ?? 'Birthday reward'));
        if ($birthdayRewardLabel === '') {
            $birthdayRewardLabel = 'Birthday reward';
        }

        return [
            '{{birthdays_label}}' => $birthdaysLabel,
            '{{birthday_reward_label}}' => $birthdayRewardLabel,
        ];
    }
}
