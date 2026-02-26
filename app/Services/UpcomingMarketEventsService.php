<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Market;
use App\Support\MarketEvents\RequestMetrics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        $startedAt = microtime(true);
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

        $result = [
            'fetched' => count($items),
            'upserted' => count($upserted),
            'events' => $upserted,
        ];

        Log::info('UpcomingMarketEventsService sync complete', [
            'weeks' => $weeks,
            'fetched' => $result['fetched'],
            'upserted' => $result['upserted'],
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    public function fetchCalendarEvents(string $calendarId, string $apiKey, int $weeks): array
    {
        // Include a short lookback so current-month/recent events remain visible in the planner.
        $timeMin = now()->subDays(21)->startOfDay()->toIso8601String();
        $timeMax = now()->addWeeks($weeks)->endOfDay()->toIso8601String();

        $url = 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events';

        $params = [
            'key' => $apiKey,
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'timeMin' => $timeMin,
            'timeMax' => $timeMax,
            'maxResults' => 250,
        ];

        RequestMetrics::recordExternalHttpCall('google_calendar_events', [
            'calendar_id' => $calendarId,
            'weeks' => $weeks,
        ]);

        $response = Http::timeout(20)->get($url, $params);

        if (! $response->successful()) {
            $body = Str::limit(trim((string) $response->body()), 4000, '');
            Log::error('Google Calendar events fetch failed', [
                'url_path' => parse_url($url, PHP_URL_PATH),
                'calendar_id' => $calendarId,
                'query' => [
                    'singleEvents' => 'true',
                    'orderBy' => 'startTime',
                    'timeMin' => $timeMin,
                    'timeMax' => $timeMax,
                    'maxResults' => 250,
                ],
                'status' => $response->status(),
                'response_body' => $body,
            ]);

            throw new RuntimeException(
                'Failed to fetch Google Calendar events: HTTP '.$response->status()
                .($body !== '' ? ' body='.$body : '')
            );
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

        $duplicate = Event::query()
            ->where('source', 'asana_calendar')
            ->when($startsAt, fn ($q) => $q->whereDate('starts_at', $startsAt->toDateString()))
            ->when($endsAt, fn ($q) => $q->whereDate('ends_at', $endsAt->toDateString()))
            ->get()
            ->first(function (Event $candidate) use ($title) {
                return $this->normalizedCalendarTitleKey((string) ($candidate->display_name ?: $candidate->name))
                    === $this->normalizedCalendarTitleKey($title);
            });

        if ($duplicate) {
            $duplicate->fill(collect($payload)->except(['source_ref'])->all())->save();
            return $duplicate;
        }

        return Event::query()->create($payload);
    }

    protected function normalizedCalendarTitleKey(string $title): string
    {
        $normalized = Str::lower($title);
        $normalized = preg_replace('/\b20\d{2}\b/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b\d{1,2}[\/.-]\d{1,2}(?:[\/.-]\d{2,4})?\b/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z0-9\s]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
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
