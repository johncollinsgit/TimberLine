<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;

class AnalyticsTimeframeService
{
    /** @var array<int,string> */
    protected array $modes = ['rolling', 'fixed'];

    /** @var array<int,string> */
    protected array $presets = [
        'today',
        'yesterday',
        'last_7_days',
        'last_30_days',
        'last_90_days',
        'last_365_days',
        'last_12_months',
        'this_week',
        'this_month',
        'this_quarter',
        'this_year',
        'last_week',
        'last_month',
        'last_quarter',
        'last_year',
        'custom',
    ];

    /** @var array<int,string> */
    protected array $comparisonModes = [
        'none',
        'previous_period',
        'previous_week',
        'previous_month',
        'previous_quarter',
        'previous_year',
        'same_period_last_year',
        'year_over_year',
    ];

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function resolve(array $filters): array
    {
        $mode = $this->normalizeMode($filters['time_mode'] ?? null);
        $preset = $this->normalizePreset($filters['preset'] ?? null);
        $comparisonMode = $this->normalizeComparisonMode($filters['comparison_mode'] ?? null);

        $customStart = $this->parseDate($filters['custom_start_date'] ?? null);
        $customEnd = $this->parseDate($filters['custom_end_date'] ?? null);

        $primary = $this->resolvePrimaryWindow($mode, $preset, $customStart, $customEnd);
        $comparison = $this->resolveComparisonWindow($comparisonMode, $primary);

        return [
            'time_mode' => $mode,
            'preset' => $preset,
            'comparison_mode' => $comparisonMode,
            'primary' => $this->serializeWindow($primary, $mode, $preset),
            'comparison' => $comparison ? $this->serializeWindow($comparison, $mode, $comparisonMode) : null,
            'labels' => [
                'primary' => $this->windowLabel($primary),
                'comparison' => $comparison ? $this->windowLabel($comparison) : null,
            ],
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function modeOptions(): array
    {
        return [
            ['value' => 'rolling', 'label' => 'Rolling'],
            ['value' => 'fixed', 'label' => 'Fixed'],
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function presetOptions(): array
    {
        return [
            ['value' => 'today', 'label' => 'Today'],
            ['value' => 'yesterday', 'label' => 'Yesterday'],
            ['value' => 'last_7_days', 'label' => 'Last 7 days'],
            ['value' => 'last_30_days', 'label' => 'Last 30 days'],
            ['value' => 'last_90_days', 'label' => 'Last 90 days'],
            ['value' => 'last_365_days', 'label' => 'Last 365 days'],
            ['value' => 'last_12_months', 'label' => 'Last 12 months'],
            ['value' => 'this_week', 'label' => 'This week'],
            ['value' => 'this_month', 'label' => 'This month'],
            ['value' => 'this_quarter', 'label' => 'This quarter'],
            ['value' => 'this_year', 'label' => 'This year'],
            ['value' => 'last_week', 'label' => 'Last week'],
            ['value' => 'last_month', 'label' => 'Last month'],
            ['value' => 'last_quarter', 'label' => 'Last quarter'],
            ['value' => 'last_year', 'label' => 'Last year'],
            ['value' => 'custom', 'label' => 'Custom range'],
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function comparisonOptions(): array
    {
        return [
            ['value' => 'none', 'label' => 'No comparison'],
            ['value' => 'previous_period', 'label' => 'Previous period'],
            ['value' => 'previous_week', 'label' => 'Previous week'],
            ['value' => 'previous_month', 'label' => 'Previous month'],
            ['value' => 'previous_quarter', 'label' => 'Previous quarter'],
            ['value' => 'previous_year', 'label' => 'Previous year'],
            ['value' => 'same_period_last_year', 'label' => 'Same period last year'],
            ['value' => 'year_over_year', 'label' => 'Year over year'],
        ];
    }

    protected function normalizeMode(mixed $value): string
    {
        $mode = strtolower(trim((string) $value));

        return in_array($mode, $this->modes, true) ? $mode : 'rolling';
    }

    protected function normalizePreset(mixed $value): string
    {
        $preset = strtolower(trim((string) $value));

        return in_array($preset, $this->presets, true) ? $preset : 'last_30_days';
    }

    protected function normalizeComparisonMode(mixed $value): string
    {
        $mode = strtolower(trim((string) $value));

        return in_array($mode, $this->comparisonModes, true) ? $mode : 'none';
    }

    protected function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{from:CarbonImmutable,to:CarbonImmutable,key:string}
     */
    protected function resolvePrimaryWindow(string $mode, string $preset, ?CarbonImmutable $customStart, ?CarbonImmutable $customEnd): array
    {
        $now = CarbonImmutable::now();

        if ($preset === 'custom') {
            if (! $customStart || ! $customEnd || $customEnd->lt($customStart)) {
                $customEnd = $now->endOfDay();
                $customStart = $customEnd->subDays(29)->startOfDay();
            }

            return [
                'from' => $customStart->startOfDay(),
                'to' => $customEnd->endOfDay(),
                'key' => 'custom',
            ];
        }

        return $mode === 'fixed'
            ? $this->resolveFixedPresetWindow($preset, $now)
            : $this->resolveRollingPresetWindow($preset, $now);
    }

    /**
     * @return array{from:CarbonImmutable,to:CarbonImmutable,key:string}
     */
    protected function resolveRollingPresetWindow(string $preset, CarbonImmutable $now): array
    {
        $days = match ($preset) {
            'today' => 1,
            'yesterday' => 1,
            'last_7_days', 'this_week', 'last_week' => 7,
            'last_30_days', 'this_month', 'last_month' => 30,
            'last_90_days', 'this_quarter', 'last_quarter' => 90,
            'last_365_days', 'last_12_months', 'this_year', 'last_year' => 365,
            default => 30,
        };

        if ($preset === 'yesterday') {
            $anchor = $now->subDay()->endOfDay();
        } else {
            $anchor = $now->endOfDay();
        }

        $from = $anchor->subDays($days - 1)->startOfDay();

        return [
            'from' => $from,
            'to' => $anchor,
            'key' => $preset,
        ];
    }

    /**
     * @return array{from:CarbonImmutable,to:CarbonImmutable,key:string}
     */
    protected function resolveFixedPresetWindow(string $preset, CarbonImmutable $now): array
    {
        return match ($preset) {
            'today' => ['from' => $now->startOfDay(), 'to' => $now->endOfDay(), 'key' => $preset],
            'yesterday' => ['from' => $now->subDay()->startOfDay(), 'to' => $now->subDay()->endOfDay(), 'key' => $preset],
            'last_7_days' => ['from' => $now->subDays(6)->startOfDay(), 'to' => $now->endOfDay(), 'key' => $preset],
            'last_30_days' => ['from' => $now->subDays(29)->startOfDay(), 'to' => $now->endOfDay(), 'key' => $preset],
            'last_90_days' => ['from' => $now->subDays(89)->startOfDay(), 'to' => $now->endOfDay(), 'key' => $preset],
            'last_365_days', 'last_12_months' => ['from' => $now->subDays(364)->startOfDay(), 'to' => $now->endOfDay(), 'key' => $preset],
            'this_week' => ['from' => $now->startOfWeek()->startOfDay(), 'to' => $now->endOfWeek()->endOfDay(), 'key' => $preset],
            'this_month' => ['from' => $now->startOfMonth()->startOfDay(), 'to' => $now->endOfMonth()->endOfDay(), 'key' => $preset],
            'this_quarter' => ['from' => $now->firstOfQuarter()->startOfDay(), 'to' => $now->lastOfQuarter()->endOfDay(), 'key' => $preset],
            'this_year' => ['from' => $now->startOfYear()->startOfDay(), 'to' => $now->endOfYear()->endOfDay(), 'key' => $preset],
            'last_week' => [
                'from' => $now->subWeek()->startOfWeek()->startOfDay(),
                'to' => $now->subWeek()->endOfWeek()->endOfDay(),
                'key' => $preset,
            ],
            'last_month' => [
                'from' => $now->subMonthNoOverflow()->startOfMonth()->startOfDay(),
                'to' => $now->subMonthNoOverflow()->endOfMonth()->endOfDay(),
                'key' => $preset,
            ],
            'last_quarter' => [
                'from' => $now->subQuarter()->firstOfQuarter()->startOfDay(),
                'to' => $now->subQuarter()->lastOfQuarter()->endOfDay(),
                'key' => $preset,
            ],
            'last_year' => [
                'from' => $now->subYear()->startOfYear()->startOfDay(),
                'to' => $now->subYear()->endOfYear()->endOfDay(),
                'key' => $preset,
            ],
            default => ['from' => $now->subDays(29)->startOfDay(), 'to' => $now->endOfDay(), 'key' => 'last_30_days'],
        };
    }

    /**
     * @param  array{from:CarbonImmutable,to:CarbonImmutable,key:string}  $primary
     * @return array{from:CarbonImmutable,to:CarbonImmutable,key:string}|null
     */
    protected function resolveComparisonWindow(string $comparisonMode, array $primary): ?array
    {
        if ($comparisonMode === 'none') {
            return null;
        }

        $from = $primary['from'];
        $to = $primary['to'];

        return match ($comparisonMode) {
            'previous_period' => $this->previousPeriodWindow($from, $to),
            'previous_week' => [
                'from' => $from->subWeek()->startOfWeek()->startOfDay(),
                'to' => $from->subWeek()->endOfWeek()->endOfDay(),
                'key' => 'previous_week',
            ],
            'previous_month' => [
                'from' => $from->subMonthNoOverflow()->startOfMonth()->startOfDay(),
                'to' => $from->subMonthNoOverflow()->endOfMonth()->endOfDay(),
                'key' => 'previous_month',
            ],
            'previous_quarter' => [
                'from' => $from->subQuarter()->firstOfQuarter()->startOfDay(),
                'to' => $from->subQuarter()->lastOfQuarter()->endOfDay(),
                'key' => 'previous_quarter',
            ],
            'previous_year' => [
                'from' => $from->subYear()->startOfYear()->startOfDay(),
                'to' => $from->subYear()->endOfYear()->endOfDay(),
                'key' => 'previous_year',
            ],
            'same_period_last_year', 'year_over_year' => [
                'from' => $from->subYear(),
                'to' => $to->subYear(),
                'key' => $comparisonMode,
            ],
            default => null,
        };
    }

    /**
     * @return array{from:CarbonImmutable,to:CarbonImmutable,key:string}
     */
    protected function previousPeriodWindow(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $seconds = max(1, $to->getTimestamp() - $from->getTimestamp() + 1);
        $comparisonTo = $from->subSecond();
        $comparisonFrom = $comparisonTo->subSeconds($seconds - 1);

        return [
            'from' => $comparisonFrom->startOfDay(),
            'to' => $comparisonTo->endOfDay(),
            'key' => 'previous_period',
        ];
    }

    /**
     * @param  array{from:CarbonImmutable,to:CarbonImmutable,key:string}  $window
     * @return array<string,mixed>
     */
    protected function serializeWindow(array $window, string $mode, string $key): array
    {
        $seconds = max(0, $window['to']->getTimestamp() - $window['from']->getTimestamp());

        return [
            'mode' => $mode,
            'key' => $key,
            'from' => $window['from'],
            'to' => $window['to'],
            'from_date' => $window['from']->toDateString(),
            'to_date' => $window['to']->toDateString(),
            'days' => intdiv($seconds, 86400) + 1,
        ];
    }

    /**
     * @param  array{from:CarbonImmutable,to:CarbonImmutable,key:string}  $window
     */
    protected function windowLabel(array $window): string
    {
        return $window['from']->format('M j, Y').' - '.$window['to']->format('M j, Y');
    }
}
