<?php

use App\SoftwareSupport\ResponseCalendar;
use Carbon\CarbonImmutable;

it('uses Eastern service hours and explicit excluded dates without guessing a holiday list', function (): void {
    $calendar = new ResponseCalendar;
    expect($calendar->snapshot(123, CarbonImmutable::now()))->toBeNull();
    config(['software_support.clients.123.support_expectations' => ['approved' => true]]);
    $snapshot = $calendar->snapshot(123, CarbonImmutable::parse('2026-03-06T16:00:00-05:00'));
    expect($snapshot['due_at'])->toBeNull()->and($snapshot['calendar_reviewed'])->toBeFalse();
    config(['software_support.clients.123.support_expectations' => ['approved' => true,
        'holiday_calendar_reviewed' => true, 'holiday_dates' => ['2026-03-09'], 'business_day_basis' => 'eight_service_hours']]);
    $snapshot = $calendar->snapshot(123, CarbonImmutable::parse('2026-03-06T16:00:00-05:00'));
    // The explicit fictional excluded date and DST weekend are both respected.
    expect($snapshot['due_at'])->toBe('2026-03-10T20:00:00+00:00')->and($snapshot['timezone'])->toBe('America/New_York');
    expect($calendar->dueAt(CarbonImmutable::parse('2026-03-06T18:00:00-05:00'), [])->toIso8601String())->toBe('2026-03-09T17:00:00-04:00');
    expect($calendar->dueAt(CarbonImmutable::parse('2026-10-23T08:00:00-04:00'), [])->toIso8601String())->toBe('2026-10-23T17:00:00-04:00');
    config(['software_support.clients.123.support_expectations.holiday_dates' => ['2026-02-30']]);
    $invalid = $calendar->snapshot(123, CarbonImmutable::parse('2026-03-06T16:00:00-05:00'));
    expect($invalid['calendar_reviewed'])->toBeFalse()->and($invalid['due_at'])->toBeNull();
});
