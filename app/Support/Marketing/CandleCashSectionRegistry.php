<?php

namespace App\Support\Marketing;

use App\Services\Tenancy\TenantDisplayLabelResolver;

class CandleCashSectionRegistry
{
    /**
     * @return array<string,array{label:string,description:string,accent:string}>
     */
    public static function groups(): array
    {
        return self::replaceLabelTokens([
            'command' => [
                'label' => 'Program',
                'description' => 'Simple overview of how {{rewards_label_lc}} are currently structured.',
                'accent' => 'amber',
            ],
            'work' => [
                'label' => 'Live Rules',
                'description' => 'Review the live earn and redeem rows already maintained for {{rewards_label_lc}}.',
                'accent' => 'emerald',
            ],
            'growth' => [
                'label' => 'Growth',
                'description' => 'Referrals, conversion tasks, and program rules.',
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
            'dashboard' => [
                'label' => '{{rewards_label}}',
                'route' => 'marketing.candle-cash',
                'description' => 'Manage {{rewards_label_lc}} and program settings.',
                'group' => 'command',
            ],
            'tasks' => [
                'label' => 'Ways to Earn',
                'route' => 'marketing.candle-cash.tasks',
                'description' => 'Review and manage the live earn rules already powering {{rewards_label_lc}}.',
                'group' => 'work',
            ],
            'redeem' => [
                'label' => 'Ways to Redeem',
                'route' => 'marketing.candle-cash.redeem',
                'description' => 'Review and manage the live redemption rows customers can currently redeem.',
                'group' => 'work',
            ],
            'queue' => [
                'label' => 'Events',
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
                'label' => 'Gift insights',
                'route' => 'marketing.candle-cash.gifts-report',
                'description' => 'Surface reward-credit send intent, notification status, and post-gift conversions.',
                'group' => 'growth',
            ],
            'settings' => [
                'label' => 'Settings',
                'route' => 'marketing.candle-cash.settings',
                'description' => 'Configure {{rewards_label_lc}} copy, referral amounts, integration matching, and fraud rules.',
                'group' => 'growth',
            ],
        ]);
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

        $rewardsLabel = trim((string) ($labels['rewards_label'] ?? $labels['rewards'] ?? 'Rewards'));
        if ($rewardsLabel === '') {
            $rewardsLabel = 'Rewards';
        }

        return [
            '{{rewards_label}}' => $rewardsLabel,
            '{{rewards_label_lc}}' => strtolower($rewardsLabel),
        ];
    }
}
