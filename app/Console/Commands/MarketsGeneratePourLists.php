<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Market;
use App\Models\MarketBoxShipment;
use App\Models\MarketPourList;
use App\Models\MarketPourListLine;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MarketsGeneratePourLists extends Command
{
    protected $signature = 'markets:generate-pour-lists {--weeks=4 : Number of weeks ahead to scan}';
    protected $description = 'Generate draft Market Pour Lists for upcoming events from the Asana-to-Skylight Google Calendar.';

    public function handle(): int
    {
        $weeks = max(1, (int) $this->option('weeks'));
        $calendarId = (string) config('services.google_calendar.asana_skylight_calendar_id');
        $apiKey = (string) config('services.google_calendar.api_key');

        if ($calendarId === '' || $apiKey === '') {
            $this->error('Google Calendar config missing. Set GOOGLE_CALENDAR_API_KEY and ASANA_SKYLIGHT_CALENDAR_ID.');
            return self::FAILURE;
        }

        $events = $this->fetchCalendarEvents($calendarId, $apiKey, $weeks);
        if ($events === null) {
            return self::FAILURE;
        }

        $createdOrUpdated = 0;
        foreach ($events as $calendarEvent) {
            $result = DB::transaction(function () use ($calendarEvent) {
                $event = $this->upsertMarketEventFromCalendar($calendarEvent);
                $draft = MarketPourList::query()->updateOrCreate(
                    ['event_id' => $event->id, 'status' => 'draft'],
                    [
                        'title' => ($event->market?->name ?? $event->name).' Market Pour List',
                        'created_by_user_id' => null,
                        'notes' => null,
                    ]
                );

                $draft->events()->syncWithoutDetaching([$event->id]);
                $this->fillDraftLinesFromHistory($draft, $event);

                return $draft;
            });

            if ($result) {
                $createdOrUpdated++;
            }
        }

        $this->info("Draft generation complete. Drafts created/updated: {$createdOrUpdated}");
        return self::SUCCESS;
    }

    private function fetchCalendarEvents(string $calendarId, string $apiKey, int $weeks): ?array
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

        if (!$response->successful()) {
            $this->error('Failed to fetch Google Calendar events: '.$response->status());
            return null;
        }

        return (array) ($response->json('items') ?? []);
    }

    private function upsertMarketEventFromCalendar(array $calendarEvent): Event
    {
        $title = trim((string) ($calendarEvent['summary'] ?? 'Untitled Market Event'));
        $sourceRef = (string) ($calendarEvent['id'] ?? '');
        $startsAt = $this->parseGoogleDate($calendarEvent['start'] ?? []);
        $endsAt = $this->parseGoogleDate($calendarEvent['end'] ?? []);
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

        $event = Event::query()
            ->where('source', 'asana_calendar')
            ->where('source_ref', $sourceRef)
            ->first();

        if ($event) {
            $event->fill($payload)->save();
            return $event;
        }

        return Event::query()->create($payload);
    }

    private function fillDraftLinesFromHistory(MarketPourList $draft, Event $event): void
    {
        $marketId = $event->market_id;
        if (!$marketId) {
            $draft->notes = trim((string) $draft->notes."\nNeeds planning: event is not linked to a market.");
            $draft->save();
            return;
        }

        $historyEvents = Event::query()
            ->where('market_id', $marketId)
            ->where('id', '!=', $event->id)
            ->when($event->starts_at, fn ($q) => $q->whereDate('starts_at', '<', $event->starts_at))
            ->orderByDesc('starts_at')
            ->limit(8)
            ->get(['id']);

        $historyRows = MarketBoxShipment::query()
            ->whereIn('event_id', $historyEvents->pluck('id'))
            ->where('qty', '>', 0)
            ->get();

        if ($historyRows->isEmpty()) {
            $draft->lines()->delete();
            $draft->notes = $this->mergeNote($draft->notes, 'Needs planning: no historical market box data found.');
            $draft->save();
            return;
        }

        $grouped = $historyRows->groupBy(function (MarketBoxShipment $row) {
            return implode('|', [
                Str::lower(trim((string) ($row->product_key ?: ''))),
                Str::lower(trim((string) ($row->scent ?: ''))),
                Str::lower(trim((string) ($row->size ?: ''))),
            ]);
        });

        $draft->lines()->delete();
        foreach ($grouped as $group) {
            $sample = $group->first();
            $avgQty = (int) round($group->avg('qty'));
            if ($avgQty <= 0) {
                continue;
            }

            MarketPourListLine::query()->create([
                'market_pour_list_id' => $draft->id,
                'recommended_qty' => $avgQty,
                'edited_qty' => null,
                'reason_json' => [
                    'source' => 'market_box_history_avg',
                    'history_event_count' => $group->pluck('event_id')->unique()->count(),
                    'product_key' => $sample->product_key,
                    'scent' => $sample->scent,
                    'size' => $sample->size,
                ],
            ]);
        }

        $draft->notes = $this->mergeNote($draft->notes, 'Draft lines generated from historical market box data.');
        $draft->save();
    }

    private function matchOrCreateMarket(string $title, ?string $city, ?string $state): Market
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
            $name = Str::lower($market->name);
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

    private function canonicalMarketName(string $title): string
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

    private function parseGoogleDate(array $node): ?Carbon
    {
        $date = $node['dateTime'] ?? $node['date'] ?? null;
        if (!$date) {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function parseLocation(string $location): array
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

    private function mergeNote(?string $existing, string $append): string
    {
        $existing = trim((string) $existing);
        if ($existing === '') {
            return $append;
        }
        if (str_contains($existing, $append)) {
            return $existing;
        }
        return $existing."\n".$append;
    }
}

