<?php

namespace App\Services\Shipping;

use Carbon\CarbonImmutable;

class BusinessDayCalculator
{
    public function addBusinessDays(CarbonImmutable $start, int $days): CarbonImmutable
    {
        if ($days <= 0) {
            return $start;
        }

        $current = $start;
        $added = 0;

        while ($added < $days) {
            $current = $current->addDay();

            // TODO: add holiday calendar checks in Phase 2.
            if ($this->isBusinessDay($current)) {
                $added++;
            }
        }

        return $current;
    }

    public function subBusinessDays(CarbonImmutable $start, int $days): CarbonImmutable
    {
        if ($days <= 0) {
            return $start;
        }

        $current = $start;
        $subtracted = 0;

        while ($subtracted < $days) {
            $current = $current->subDay();

            // TODO: add holiday calendar checks in Phase 2.
            if ($this->isBusinessDay($current)) {
                $subtracted++;
            }
        }

        return $current;
    }

    protected function isBusinessDay(CarbonImmutable $date): bool
    {
        return !$date->isWeekend() && !$this->isObservedUsFederalHoliday($date);
    }

    protected function isObservedUsFederalHoliday(CarbonImmutable $date): bool
    {
        $year = $date->year;
        $day = $date->startOfDay();

        $holidays = [
            $this->observedFixedHoliday($year, 1, 1),   // New Year's Day
            $this->nthWeekdayOfMonth($year, 1, CarbonImmutable::MONDAY, 3),   // MLK Day
            $this->nthWeekdayOfMonth($year, 2, CarbonImmutable::MONDAY, 3),   // Presidents Day
            $this->lastWeekdayOfMonth($year, 5, CarbonImmutable::MONDAY),     // Memorial Day
            $this->observedFixedHoliday($year, 6, 19),  // Juneteenth
            $this->observedFixedHoliday($year, 7, 4),   // Independence Day
            $this->nthWeekdayOfMonth($year, 9, CarbonImmutable::MONDAY, 1),   // Labor Day
            $this->nthWeekdayOfMonth($year, 10, CarbonImmutable::MONDAY, 2),  // Columbus / Indigenous Peoples' Day
            $this->observedFixedHoliday($year, 11, 11), // Veterans Day
            $this->nthWeekdayOfMonth($year, 11, CarbonImmutable::THURSDAY, 4),// Thanksgiving
            $this->observedFixedHoliday($year, 12, 25), // Christmas
        ];

        foreach ($holidays as $holiday) {
            if ($holiday && $day->equalTo($holiday)) {
                return true;
            }
        }

        return false;
    }

    protected function observedFixedHoliday(int $year, int $month, int $day): CarbonImmutable
    {
        $holiday = CarbonImmutable::create($year, $month, $day, 0, 0, 0);

        return match (true) {
            $holiday->isSaturday() => $holiday->subDay(),
            $holiday->isSunday() => $holiday->addDay(),
            default => $holiday,
        };
    }

    protected function nthWeekdayOfMonth(int $year, int $month, int $weekday, int $nth): ?CarbonImmutable
    {
        if ($nth < 1) {
            return null;
        }

        $date = CarbonImmutable::create($year, $month, 1, 0, 0, 0);
        while ($date->dayOfWeek !== $weekday) {
            $date = $date->addDay();
        }

        return $date->addWeeks($nth - 1)->startOfDay();
    }

    protected function lastWeekdayOfMonth(int $year, int $month, int $weekday): CarbonImmutable
    {
        $date = CarbonImmutable::create($year, $month, 1, 0, 0, 0)->endOfMonth()->startOfDay();
        while ($date->dayOfWeek !== $weekday) {
            $date = $date->subDay();
        }

        return $date;
    }
}
