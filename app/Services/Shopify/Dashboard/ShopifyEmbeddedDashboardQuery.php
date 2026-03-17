<?php

namespace App\Services\Shopify\Dashboard;

use Carbon\CarbonImmutable;

class ShopifyEmbeddedDashboardQuery
{
    /**
     * @var array<int,string>
     */
    protected array $timeframes = [
        'today',
        'yesterday',
        'last_7_days',
        'last_30_days',
        'month_to_date',
        'quarter_to_date',
        'year_to_date',
        'full_year',
        'custom',
    ];

    /**
     * @var array<int,string>
     */
    protected array $comparisons = [
        'none',
        'previous_period',
        'previous_year',
    ];

    /**
     * @var array<int,string>
     */
    protected array $locationGroupings = [
        'country',
        'state',
        'city',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    public function resolve(array $input, array $config): array
    {
        $timeframe = $this->normalize(
            (string) ($input['timeframe'] ?? ''),
            $this->timeframes,
            (string) ($config['defaultTimeframe'] ?? 'last_30_days')
        );
        $comparison = $this->normalize(
            (string) ($input['comparison'] ?? ''),
            $this->comparisons,
            (string) ($config['defaultComparison'] ?? 'previous_period')
        );
        $locationGrouping = $this->normalize(
            (string) ($input['location_grouping'] ?? ''),
            $this->locationGroupings,
            (string) ($config['locationGroupingPreference'] ?? 'state')
        );

        $customStartDate = $this->normalizeDateString($input['custom_start_date'] ?? null);
        $customEndDate = $this->normalizeDateString($input['custom_end_date'] ?? null);
        $chartMetric = trim((string) ($input['chart_metric'] ?? ($config['chartDefaultMetric'] ?? 'rewards_sales')));
        $chartMetric = $chartMetric !== '' ? $chartMetric : 'rewards_sales';

        $window = $this->windowFor($timeframe, $customStartDate, $customEndDate);
        $comparisonWindow = $this->comparisonWindowFor($comparison, $timeframe, $window);
        $interval = $this->intervalFor($timeframe, $window['from'], $window['to']);

        return [
            'timeframe' => $timeframe,
            'comparison' => $comparison,
            'locationGrouping' => $locationGrouping,
            'chartMetric' => $chartMetric,
            'customStartDate' => $customStartDate,
            'customEndDate' => $customEndDate,
            'primary' => $this->serializeWindow($window),
            'comparisonWindow' => $comparisonWindow ? $this->serializeWindow($comparisonWindow) : null,
            'interval' => $interval,
            'visualization' => in_array($timeframe, ['year_to_date', 'full_year'], true) ? 'grouped_bar' : 'line',
        ];
    }

    /**
     * @return array{from:CarbonImmutable,to:CarbonImmutable}
     */
    public function rehydrateWindow(array $serialized): array
    {
        return [
            'from' => CarbonImmutable::parse((string) $serialized['from'])->startOfDay(),
            'to' => CarbonImmutable::parse((string) $serialized['to'])->endOfDay(),
        ];
    }

    protected function normalize(string $value, array $allowed, string $fallback): string
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, $allowed, true) ? $normalized : $fallback;
    }

    protected function normalizeDateString(mixed $value): ?string
    {
        $candidate = trim((string) $value);

        if ($candidate === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($candidate)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{from:CarbonImmutable,to:CarbonImmutable}
     */
    protected function windowFor(string $timeframe, ?string $customStartDate, ?string $customEndDate): array
    {
        $now = CarbonImmutable::now();

        return match ($timeframe) {
            'today' => ['from' => $now->startOfDay(), 'to' => $now->endOfDay()],
            'yesterday' => ['from' => $now->subDay()->startOfDay(), 'to' => $now->subDay()->endOfDay()],
            'last_7_days' => ['from' => $now->subDays(6)->startOfDay(), 'to' => $now->endOfDay()],
            'last_30_days' => ['from' => $now->subDays(29)->startOfDay(), 'to' => $now->endOfDay()],
            'month_to_date' => ['from' => $now->startOfMonth()->startOfDay(), 'to' => $now->endOfDay()],
            'quarter_to_date' => ['from' => $now->firstOfQuarter()->startOfDay(), 'to' => $now->endOfDay()],
            'year_to_date' => ['from' => $now->startOfYear()->startOfDay(), 'to' => $now->endOfDay()],
            'full_year' => ['from' => $now->startOfYear()->startOfDay(), 'to' => $now->endOfYear()->endOfDay()],
            'custom' => $this->customWindow($customStartDate, $customEndDate, $now),
            default => ['from' => $now->subDays(29)->startOfDay(), 'to' => $now->endOfDay()],
        };
    }

    /**
     * @return array{from:CarbonImmutable,to:CarbonImmutable}
     */
    protected function customWindow(?string $customStartDate, ?string $customEndDate, CarbonImmutable $now): array
    {
        try {
            $from = $customStartDate ? CarbonImmutable::parse($customStartDate)->startOfDay() : null;
            $to = $customEndDate ? CarbonImmutable::parse($customEndDate)->endOfDay() : null;
        } catch (\Throwable) {
            $from = null;
            $to = null;
        }

        if (! $from || ! $to || $to->lt($from)) {
            $to = $now->endOfDay();
            $from = $to->subDays(29)->startOfDay();
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @param  array{from:CarbonImmutable,to:CarbonImmutable}  $window
     * @return array{from:CarbonImmutable,to:CarbonImmutable}|null
     */
    protected function comparisonWindowFor(string $comparison, string $timeframe, array $window): ?array
    {
        if ($comparison === 'none') {
            return null;
        }

        if ($comparison === 'previous_year') {
            if ($timeframe === 'full_year') {
                return [
                    'from' => $window['from']->subYear()->startOfYear()->startOfDay(),
                    'to' => $window['to']->subYear()->endOfYear()->endOfDay(),
                ];
            }

            return [
                'from' => $window['from']->subYear()->startOfDay(),
                'to' => $window['to']->subYear()->endOfDay(),
            ];
        }

        $days = max(1, $window['from']->diffInDays($window['to']) + 1);

        return [
            'from' => $window['from']->subDays($days)->startOfDay(),
            'to' => $window['to']->subDays($days)->endOfDay(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function intervalFor(string $timeframe, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = max(1, $from->diffInDays($to) + 1);

        if (in_array($timeframe, ['today', 'yesterday'], true)) {
            return [
                'unit' => 'hour',
                'displayFormat' => 'ga',
                'bucketCount' => 24,
            ];
        }

        if (in_array($timeframe, ['year_to_date', 'full_year'], true) || $days > 180) {
            return [
                'unit' => 'month',
                'displayFormat' => 'M',
                'bucketCount' => max(1, $from->diffInMonths($to) + 1),
            ];
        }

        if ($days > 45) {
            return [
                'unit' => 'week',
                'displayFormat' => 'M j',
                'bucketCount' => (int) ceil($days / 7),
            ];
        }

        return [
            'unit' => 'day',
            'displayFormat' => 'M j',
            'bucketCount' => $days,
        ];
    }

    /**
     * @param  array{from:CarbonImmutable,to:CarbonImmutable}  $window
     * @return array<string,string>
     */
    protected function serializeWindow(array $window): array
    {
        return [
            'from' => $window['from']->toIso8601String(),
            'to' => $window['to']->toIso8601String(),
            'label' => $this->windowLabel($window['from'], $window['to']),
        ];
    }

    protected function windowLabel(CarbonImmutable $from, CarbonImmutable $to): string
    {
        if ($from->toDateString() === $to->toDateString()) {
            return $from->format('M j, Y');
        }

        if ($from->year === $to->year) {
            return $from->format('M j') . ' - ' . $to->format('M j, Y');
        }

        return $from->format('M j, Y') . ' - ' . $to->format('M j, Y');
    }
}
