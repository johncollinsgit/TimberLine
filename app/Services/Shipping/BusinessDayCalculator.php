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
        return !$date->isWeekend();
    }
}
