<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Market;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class UpcomingMarketEventsService
{
    /**
     * Fetch upcoming events from the Asana->Google Calendar feed and upsert them.
     *
     * @return array{fetched:int,upserted:int,events:array<int,array{id:int,title:string,date:?string}>}
     */
    public function syncUpcoming(int $weeks = 6): array
    {
        $weeks = max(1, $weeks);
        $calendarId = (string) config('services.google_calendar.asana_skylight_calendar_id');
        $apiKey = (string) config('services.google_calendar.api_key');

        if ($calendarId === '' || $apiKey === '') {
            throw new RuntimeException('Google Calendar config missing. Set GOOGLE_CALENDAR_API_KEY and ASANA_SKYLIGHT_CALENDAR_ID.');
        }

        $payload = $this->fetchCalendarEvents($calendarId, $apiKey, $weeks);
        $items = (array) ($payload['items'] ?? []);

        $upserted = [];

        foreach ($items as $calendarEvent) {
            if (!is_array($calendarEvent)) {
                continue;
            }

            $event = DB::transaction(fn () => $this->upsertMarketEventFromCalendar($calendarEvent));
            $upserted[] = [
                'id' => (int) $event->id,
                'title' => (string) ($event->display_name ?: $event->name),
                'date' => $event->starts_at?->toDateString(),
            ];
        }

        return [
            'fetched' => count($items),
            'upserted' => count($upserted),
            'events' => $upserted,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function fetchCalendarEvents(string $calendarId, string $apiKey, int $weeks): array
    {
        $timeMin = now()->startOfDay()->toIso8601String();
        $timeMax = now()->addWeeks($weeks)->endOfDay()->toIso8601String();

        $url = 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events';

        $response = Http::timeout(20)->get($url, [
            'key' => $apiKey,
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'timeMin' => $timeMin,
            'timeMax' => $timeMax,
            'maxResults' => 250,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to fetch Google Calendar events: HTTP '.$response->status());
        }

        return (array) $response->json();
    }

    public function upsertMarketEventFromCalendar(array $calendarEvent): Event
    {
        $title = trim((string) ($calendarEvent['summary'] ?? 'Untitled Market Event'));
        $sourceRef = (string) ($calendarEvent['id'] ?? '');
        $startsAt = $this->parseGoogleDate((array) ($calendarEvent['start'] ?? []));
        $endsAt = $this->parseGoogleDate((array) ($calendarEvent['end'] ?? []));
        $location = trim((string) ($calendarEvent['location'] ?? ''));

        [$city, $state] = $this->parseLocation($location);
        $market = $this->matchOrCreateMarket($title, $city, $state);

        $payload = [
            'market_id' => $market->id,
            'year' => $startsAt?->year,
            'name' => $market->name,
            'display_name' => $title,
            'starts_at' => $startsAt?->toDateString(),
            'ends_at' => $endsAt?->toDateString(),
            'city' => $city,
            'state' => $state,
            'venue' => $location !== '' ? $location : null,
            'source' => 'asana_calendar',
            'source_ref' => $sourceRef,
            'status' => 'planned',
        ];

        $existing = Event::query()
            ->where('source', 'asana_calendar')
            ->where('source_ref', $sourceRef)
            ->first();

        if ($existing) {
            $existing->fill($payload)->save();
            return $existing;
        }

        return Event::query()->create($payload);
    }

    protected function matchOrCreateMarket(string $title, ?string $city, ?string $state): Market
    {
        $canonical = $this->canonicalMarketName($title);
        $slug = Str::slug($canonical);
        if ($slug === '') {
            $slug = 'market-'.Str::random(8);
        }

        $exact = Market::query()->where('slug', $slug)->first();
        if ($exact) {
            $exact->fill([
                'default_location_city' => $exact->default_location_city ?: $city,
                'default_location_state' => $exact->default_location_state ?: $state,
            ])->save();

            return $exact;
        }

        $normalized = Str::lower($canonical);
        $candidate = Market::query()->get()->first(function (Market $market) use ($normalized) {
            $name = Str::lower((string) $market->name);
            return str_contains($name, $normalized) || str_contains($normalized, $name);
        });

        if ($candidate) {
            return $candidate;
        }

        return Market::query()->create([
            'name' => $canonical,
            'slug' => $slug,
            'default_location_city' => $city,
            'default_location_state' => $state,
        ]);
    }

    protected function canonicalMarketName(string $title): string
    {
        $clean = Str::of($title)
            ->replaceMatches('/\b20\d{2}\b/', ' ')
            ->replaceMatches('/\b(mon|tue|wed|thu|fri|sat|sun)\b/i', ' ')
            ->replaceMatches('/\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?/', ' ')
            ->replace(['(', ')', '|'], ' ')
            ->squish()
            ->value();

        return $clean !== '' ? $clean : $title;
    }

    protected function parseGoogleDate(array $node): ?Carbon
    {
        $date = $node['dateTime'] ?? $node['date'] ?? null;
        if (! $date) {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{0:?string,1:?string}
     */
    protected function parseLocation(string $location): array
    {
        if ($location === '') {
            return [null, null];
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $location))));
        $state = null;
        $city = null;

        if (count($parts) >= 2) {
            $city = $parts[count($parts) - 2];
            if (preg_match('/\b([A-Z]{2})\b/', strtoupper($parts[count($parts) - 1]), $m)) {
                $state = $m[1];
            }
        }

        return [$city, $state];
    }
}
