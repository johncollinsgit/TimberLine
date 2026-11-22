<?php

namespace App\Services\Watershed;

use Carbon\CarbonImmutable;

class WatershedPlacement
{
    public const CLASSES = ['nursery' => 'Nursery', 'kinder' => 'Kinder', 'lower' => 'Lower Elementary', 'upper' => 'Upper Elementary'];

    public function maximum(int $year): int
    {
        return 6 + max(0, $year - 2026);
    }

    public function classFor(int $grade): string
    {
        return $grade === -2 ? 'nursery' : ($grade <= 0 ? 'kinder' : ($grade <= 3 ? 'lower' : 'upper'));
    }

    public function assess(?string $birthday, int $year): array
    {
        if (! $birthday) {
            return ['eligible' => false, 'reason' => 'Add a birthday before registering.'];
        }
        $birth = CarbonImmutable::parse($birthday)->startOfDay();
        $cutoff = CarbonImmutable::create($year, 9, 1)->startOfDay();
        if ($birth->isFuture()) {
            return ['eligible' => false, 'reason' => 'This child was born after this school year’s cutoff. Contact leadership.'];
        }
        $age = $birth->gt($cutoff) ? 0 : (int) $birth->diffInYears($cutoff);
        $grade = $age < 3 ? -2 : ($age < 5 ? -1 : $age - 5);
        $max = $this->maximum($year);

        return ['eligible' => $grade <= $max, 'age' => $age, 'grade' => $grade, 'class' => $this->classFor($grade), 'maximum' => $max, 'cutoff' => $cutoff->toDateString(), 'reason' => $grade > $max ? 'This school year accepts children through grade '.$max.'.' : null];
    }
}
