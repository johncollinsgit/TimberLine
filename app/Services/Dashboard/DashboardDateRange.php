<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

class DashboardDateRange
{
    public const DEFAULT = '1m';

    /** @return array<string,string> */
    public function options(): array
    {
        return [
            '1d' => '1 day',
            '1w' => '1 week',
            '1m' => '1 month',
            '30d' => 'Last 30 days',
            'ytd' => 'This year',
        ];
    }

    /** @return array{key:string,label:string,short_label:string,starts_at:CarbonImmutable,ends_at:CarbonImmutable,options:array<string,string>} */
    public function resolve(?string $key, Carbon|CarbonImmutable|null $now = null): array
    {
        $options = $this->options();
        $key = strtolower(trim((string) $key));
        if (! array_key_exists($key, $options)) {
            $key = self::DEFAULT;
        }

        $end = $now instanceof CarbonImmutable
            ? $now
            : ($now instanceof Carbon ? $now->toImmutable() : now()->toImmutable());
        $start = match ($key) {
            '1d' => $end->startOfDay(),
            '1w' => $end->subDays(6)->startOfDay(),
            '30d' => $end->subDays(29)->startOfDay(),
            'ytd' => $end->startOfYear(),
            default => $end->startOfMonth(),
        };

        return [
            'key' => $key,
            'label' => $options[$key],
            'short_label' => $key === '1m' ? 'Current month' : $options[$key],
            'starts_at' => $start,
            'ends_at' => $end,
            'options' => $options,
        ];
    }
}
