<?php

namespace App\Services\Accounting;

use App\Services\Dashboard\DashboardDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AccountingDateRangeService
{
    public const DEFAULT = 'current_month';

    public function __construct(protected DashboardDateRange $dashboardRanges) {}

    /** @return array<string,string> */
    public function options(): array
    {
        return [
            'current_month' => 'Current month',
            'previous_month' => 'Previous month',
            'last_30_days' => 'Last 30 days',
            'quarter_to_date' => 'Quarter to date',
            'year_to_date' => 'Year to date',
            'calendar_year' => 'Full calendar year',
            'previous_calendar_year' => 'Previous calendar year',
            'custom' => 'Custom dates',
        ];
    }

    /** @return array{key:string,label:string,starts_at:CarbonImmutable,ends_at:CarbonImmutable,aggregation:string,options:array<string,string>} */
    public function resolve(
        ?string $key,
        ?string $customStart = null,
        ?string $customEnd = null,
        Carbon|CarbonImmutable|null $now = null,
    ): array {
        $end = $now instanceof CarbonImmutable
            ? $now
            : ($now instanceof Carbon ? $now->toImmutable() : now()->toImmutable());
        $key = strtolower(trim((string) $key));
        $key = array_key_exists($key, $this->options()) ? $key : self::DEFAULT;

        [$start, $resolvedEnd] = match ($key) {
            'current_month' => $this->dashboardCurrentMonth($end),
            'previous_month' => [$end->subMonthNoOverflow()->startOfMonth(), $end->subMonthNoOverflow()->endOfMonth()],
            'last_30_days' => [$end->subDays(29)->startOfDay(), $end],
            'quarter_to_date' => [$end->startOfQuarter(), $end],
            'year_to_date' => [$end->startOfYear(), $end],
            'calendar_year' => [$end->startOfYear(), $end->endOfYear()],
            'previous_calendar_year' => [$end->subYear()->startOfYear(), $end->subYear()->endOfYear()],
            'custom' => $this->custom($customStart, $customEnd),
        };

        return [
            'key' => $key,
            'label' => $this->options()[$key],
            'starts_at' => $start,
            'ends_at' => $resolvedEnd,
            'aggregation' => $start->diffInDays($resolvedEnd) > 93 ? 'month' : 'day',
            'options' => $this->options(),
        ];
    }

    /** @return array{CarbonImmutable,CarbonImmutable} */
    protected function dashboardCurrentMonth(CarbonImmutable $now): array
    {
        $range = $this->dashboardRanges->resolve('1m', $now);

        return [$range['starts_at'], $range['ends_at']];
    }

    /** @return array{CarbonImmutable,CarbonImmutable} */
    protected function custom(?string $start, ?string $end): array
    {
        try {
            $startsAt = CarbonImmutable::parse((string) $start)->startOfDay();
            $endsAt = CarbonImmutable::parse((string) $end)->endOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['range' => 'Choose valid custom start and end dates.']);
        }

        if ($startsAt->gt($endsAt) || $startsAt->diffInYears($endsAt) > 5) {
            throw ValidationException::withMessages(['range' => 'The custom range must be ordered and no longer than five years.']);
        }

        return [$startsAt, $endsAt];
    }
}
