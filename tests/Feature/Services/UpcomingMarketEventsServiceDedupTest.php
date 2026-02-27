<?php

use App\Models\Event;
use App\Services\UpcomingMarketEventsService;

test('upsert uses stable dedupe keys for google id and normalized fallback title/date', function () {
    $service = app(UpcomingMarketEventsService::class);

    $firstByGoogleId = $service->upsertMarketEventFromCalendar([
        'id' => 'google-event-abc',
        'summary' => 'Downtown Market 2026',
        'start' => ['date' => '2026-03-10'],
        'end' => ['date' => '2026-03-10'],
        'location' => 'Franklin, TN',
    ]);

    $secondByGoogleId = $service->upsertMarketEventFromCalendar([
        'id' => 'google-event-abc',
        'summary' => 'Downtown   Market - 03/10/2026',
        'start' => ['date' => '2026-03-10'],
        'end' => ['date' => '2026-03-10'],
        'location' => 'Franklin, TN',
    ]);

    expect($secondByGoogleId->id)->toBe($firstByGoogleId->id);
    expect(Event::query()->where('source', 'asana_calendar')->count())->toBe(1);

    $firstNoId = $service->upsertMarketEventFromCalendar([
        'summary' => 'Main Street Market 2026-03-15',
        'start' => ['date' => '2026-03-15'],
        'end' => ['date' => '2026-03-15'],
        'location' => 'Nashville, TN',
    ]);

    $secondNoIdSameEvent = $service->upsertMarketEventFromCalendar([
        'summary' => 'Main   Street Market',
        'start' => ['date' => '2026-03-15'],
        'end' => ['date' => '2026-03-15'],
        'location' => 'Nashville, TN',
    ]);

    expect($secondNoIdSameEvent->id)->toBe($firstNoId->id);

    $thirdNoIdDistinctEvent = $service->upsertMarketEventFromCalendar([
        'summary' => 'Riverside Sunset Market',
        'start' => ['date' => '2026-03-22'],
        'end' => ['date' => '2026-03-22'],
        'location' => 'Nashville, TN',
    ]);

    expect($thirdNoIdDistinctEvent->id)->not->toBe($firstNoId->id);
});

test('syncUpcoming reports unique upserted events when source feed includes duplicates', function () {
    config()->set('services.google_calendar.asana_skylight_calendar_id', 'test-calendar-id');
    config()->set('services.google_calendar.api_key', 'test-key');

    $service = \Mockery::mock(UpcomingMarketEventsService::class)->makePartial();
    $service->shouldReceive('fetchCalendarEvents')
        ->once()
        ->andReturn([
            'items' => [
                [
                    'id' => 'dup-google-id-1',
                    'summary' => 'East Market 2026',
                    'start' => ['date' => '2026-04-01'],
                    'end' => ['date' => '2026-04-01'],
                    'location' => 'Nashville, TN',
                ],
                [
                    'id' => 'dup-google-id-1',
                    'summary' => 'East   Market - 04/01/2026',
                    'start' => ['date' => '2026-04-01'],
                    'end' => ['date' => '2026-04-01'],
                    'location' => 'Nashville, TN',
                ],
            ],
        ]);

    $result = $service->syncUpcoming(4);

    expect((int) ($result['fetched'] ?? 0))->toBe(2);
    expect((int) ($result['upserted'] ?? 0))->toBe(1);
    expect(count((array) ($result['events'] ?? [])))->toBe(1);
});

