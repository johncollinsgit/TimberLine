<?php

namespace App\SoftwareSupport;

use Carbon\CarbonImmutable;

final class ResponseCalendar
{
    public const TIMEZONE = 'America/New_York';

    /** No operational target is calculated until the client's actual excluded-date calendar is reviewed. */
    public function snapshot(int $clientId, CarbonImmutable $receivedAt): ?array
    {
        $policy = config('software_support.clients.'.$clientId.'.support_expectations', []);
        if (! ($policy['approved'] ?? false)) {
            return null;
        }
        $reviewed = ($policy['holiday_calendar_reviewed'] ?? false) === true
            && ($policy['business_day_basis'] ?? null) === 'eight_service_hours' && is_array($policy['holiday_dates'] ?? null);
        $holidays = $reviewed ? $policy['holiday_dates'] : [];
        foreach ($holidays as $date) {
            $parsed = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            if (! $parsed || $parsed->format('Y-m-d') !== $date) {
                $reviewed = false;
            }
        }

        return ['policy' => 'weekday-eastern-initial-human-response-v1', 'timezone' => self::TIMEZONE,
            'service_hours' => 'Monday–Friday, 9 AM–5 PM Eastern, holidays excluded',
            'initial_response' => 'Within one business day; automatic receipts do not count.',
            'resolution' => 'No guaranteed resolution time.', 'calendar_reviewed' => $reviewed,
            'holiday_calendar_hash' => $reviewed ? hash('sha256', json_encode($holidays)) : null,
            'due_at' => $reviewed ? $this->dueAt($receivedAt, $holidays)->utc()->toIso8601String() : null];
    }

    /** Proposed operational interpretation: one business day = eight open service hours. */
    public function dueAt(CarbonImmutable $receivedAt, array $holidayDates): CarbonImmutable
    {
        $cursor = $receivedAt->setTimezone(self::TIMEZONE);
        $remaining = 8 * 60 * 60;
        while ($remaining > 0) {
            $open = $cursor->setTime(9, 0);
            $close = $cursor->setTime(17, 0);
            if ($cursor->isWeekend() || in_array($cursor->toDateString(), $holidayDates, true) || $cursor >= $close) {
                $cursor = $cursor->addDay()->setTime(9, 0);

                continue;
            }
            if ($cursor < $open) {
                $cursor = $open;
            }
            $available = (int) $cursor->diffInSeconds($close);
            if ($available >= $remaining) {
                return $cursor->addSeconds($remaining);
            }
            $remaining -= $available;
            $cursor = $cursor->addDay()->setTime(9, 0);
        }

        return $cursor;
    }
}
