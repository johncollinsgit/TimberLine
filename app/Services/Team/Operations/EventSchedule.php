<?php

namespace App\Services\Team\Operations;

use Carbon\CarbonImmutable;

/** An explicit event range is distinct from the source task's reminder/deadline. */
class EventSchedule
{
    public function source(array $task): array
    {
        $ranges = [];
        $pattern = '/(?<!\d)(\d{1,2})[.\/](\d{1,2})[.\/](\d{2}|\d{4})\s*(?:-|–|—|to)\s*(\d{1,2})[.\/](\d{1,2})[.\/](\d{2}|\d{4})(?!\d)/u';
        foreach (['name', 'notes'] as $field) {
            preg_match_all($pattern, $task[$field] ?? '', $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $start = $this->date($match[1], $match[2], $match[3]);
                $end = $this->date($match[4], $match[5], $match[6]);
                if ($start && $end && $end >= $start && CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) <= 366) {
                    $ranges[$start.'|'.$end] = ['starts_on' => $start, 'ends_on' => $end, 'evidence' => ['field' => $field, 'passage' => $match[0]]];
                }
            }
        }
        $range = count($ranges) === 1 ? array_values($ranges)[0] : ['starts_on' => null, 'ends_on' => null];

        return [...$range, 'basis' => count($ranges) === 1 ? 'explicit_source_range' : (count($ranges) > 1 ? 'conflicting_source_ranges' : 'unconfirmed'), 'needs_review' => count($ranges) !== 1,
            'source_schedule' => array_intersect_key($task, array_flip(['start_on', 'due_on', 'start_at', 'due_at'])), 'source_candidates' => array_values($ranges)];
    }

    private function date(string $month, string $day, string $year): ?string
    {
        $year = strlen($year) === 2 ? 2000 + (int) $year : (int) $year;
        if (! checkdate((int) $month, (int) $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
