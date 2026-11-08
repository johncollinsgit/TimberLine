<?php

namespace App\Services\Team\Operations;

use App\Models\TeamOperationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OperationsWeather
{
    public function event(TeamOperationRecord $event, ?array $reviewed = null): array
    {
        abort_unless(config('team_operations.weather.enabled') && config('team_operations.weather.commercial') && config('team_operations.weather.api_key'), 503, 'Weather needs a configured commercial provider.');
        $d = array_replace($event->data ?? [], $event->data['reviewed_fields'] ?? []);
        $startDate = $event->starts_on?->toDateString();
        $endDate = $event->ends_on?->toDateString();
        $calendarData = $d;
        $hasCalendar = array_key_exists('sessions', $d) || is_array($d['_teams_enrichment']['calendar'] ?? null);
        if ($reviewed !== null) {
            // A historical review cannot borrow later source venue, dates or sessions.
            $fields = ['latitude', 'longitude', 'timezone', 'daily_hours'];
            $d = array_replace(array_fill_keys($fields, null), array_intersect_key($reviewed, array_flip($fields)));
            $startDate = $reviewed['starts_on'] ?? null;
            $endDate = $reviewed['ends_on'] ?? null;
            $calendarData = $reviewed['calendar'] ?? array_intersect_key($reviewed, array_flip(['timezone', 'sessions', 'schedule_complete']));
            $hasCalendar = array_key_exists('sessions', $reviewed) || (array_key_exists('sessions', $calendarData) && ($calendarData['basis'] ?? null) !== 'not_reviewed');
        }
        $calendar = app(EventSchedule::class)->calendar($calendarData, $startDate, $endDate);
        if ($reviewed === null && $hasCalendar && $calendar['timezone']) {
            $d['timezone'] = $calendar['timezone'];
        }
        $sellingDates = $hasCalendar ? $calendar['selling_dates'] : null;
        $hours = $hasCalendar ? array_values(array_filter($calendar['sessions'], fn ($s) => ($s['kind'] ?? null) === 'selling' && isset($s['date'], $s['opens'], $s['closes']))) : ($d['daily_hours'] ?? []);
        $coverage = ['basis' => $hasCalendar ? 'explicit_selling_sessions' : 'legacy_unclassified_event_range', 'reviewed_calendar' => $reviewed !== null && $hasCalendar,
            'schedule_complete' => $hasCalendar && $calendar['schedule_complete'], 'selling_dates' => $sellingDates,
            'unknown_selling_date_count' => $hasCalendar ? count(array_filter($calendar['sessions'], fn ($s) => ($s['kind'] ?? null) === 'selling' && ! isset($s['date']))) : null,
            'show_hours_unknown_dates' => $hasCalendar ? array_values(array_unique(array_column(array_filter($calendar['sessions'], fn ($s) => ($s['kind'] ?? null) === 'selling' && isset($s['date']) && ! isset($s['opens'], $s['closes'])), 'date'))) : null,
            'notice' => $hasCalendar ? 'Weather for recorded selling dates only; setup, travel and gap dates are excluded. Session windows are not staff shifts or paid hours.' : 'Unclassified recorded range weather is context only; selling dates and schedule completeness are unknown.'];
        $latitude = $d['latitude'] ?? null;
        $longitude = $d['longitude'] ?? null;
        abort_unless(is_numeric($latitude) && $latitude >= -90 && $latitude <= 90 && is_numeric($longitude) && $longitude >= -180 && $longitude <= 180, 422, 'Confirm the historical venue coordinates and event dates first.');
        $horizonStart = $sellingDates[0] ?? $startDate;
        if ($horizonStart && $horizonStart > now('UTC')->addDays(16)->toDateString()) {
            return ['type' => 'outside_forecast_horizon', 'available' => false, 'coverage' => [...$coverage, 'available_dates' => [], 'missing_dates' => $sellingDates, 'complete' => false], 'notice' => 'Daily forecast is not available this far ahead. Historical comparisons are context, not a forecast.', 'provider' => 'Open-Meteo'];
        }
        abort_unless(in_array($d['timezone'] ?? '', timezone_identifiers_list(), true), 422, 'Confirm the venue timezone before requesting weather.');
        if ($hasCalendar && ! $sellingDates) {
            return ['type' => 'selling_dates_unknown', 'available' => false, 'daily' => ['time' => []], 'day_sources' => [], 'show_hours' => [], 'coverage' => [...$coverage, 'available_dates' => [], 'missing_dates' => [], 'complete' => false], 'timezone' => $d['timezone'], 'venue' => ['latitude' => $latitude, 'longitude' => $longitude], 'complete_range' => false, 'notice' => 'Record a dated selling session before requesting show weather.', 'provider' => 'Open-Meteo'];
        }
        $startDate = $sellingDates[0] ?? $startDate;
        $endDate = $sellingDates ? $sellingDates[count($sellingDates) - 1] : $endDate;
        abort_unless($startDate && $endDate, 422, 'Confirm the first and last show dates before requesting weather.');
        $today = CarbonImmutable::now($d['timezone'])->startOfDay();
        $start = CarbonImmutable::parse($startDate, $d['timezone']);
        $end = CarbonImmutable::parse($endDate, $d['timezone']);
        abort_unless($end >= $start && $start->diffInDays($end) <= 366, 422, 'Weather requests cover at most one event year.');
        $hours = array_values(array_filter($hours, fn ($window) => isset($window['date']) && $window['date'] >= $startDate && $window['date'] <= $endDate));
        $dailyEnd = $end;
        // Legacy overnight show windows need the following local day's interval ends.
        foreach ($hours as $window) {
            if (isset($window['date'], $window['opens'], $window['closes']) && $window['closes'] <= $window['opens']) {
                $end = $end->max(CarbonImmutable::parse($window['date'], $d['timezone'])->addDay());
            }
        }
        if ($start > $today->addDays(15)) {
            return ['type' => 'outside_forecast_horizon', 'available' => false, 'coverage' => [...$coverage, 'available_dates' => [], 'missing_dates' => $sellingDates, 'complete' => false], 'notice' => 'Daily forecast is not available this far ahead. Historical comparisons are context, not a forecast.', 'provider' => 'Open-Meteo'];
        }
        $last = $end->min($today->addDays(15));
        $cutoff = $today->subDays(5);
        $segments = [];
        if ($start <= $cutoff) {
            $segments[] = [$start, $last->min($cutoff), true];
        }
        if ($last > $cutoff) {
            $segments[] = [$start->max($cutoff->addDay()), $last, false];
        }
        $daily = ['time' => []];
        $hourly = [];
        $units = [];
        $types = [];
        foreach ($segments as [$from, $to, $archive]) {
            $params = ['latitude' => $latitude, 'longitude' => $longitude, 'timezone' => $d['timezone'], 'temperature_unit' => 'fahrenheit', 'wind_speed_unit' => 'mph', 'precipitation_unit' => 'inch', 'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,wind_gusts_10m_max', 'hourly' => 'temperature_2m,precipitation,wind_gusts_10m,weather_code', 'start_date' => $from->toDateString(), 'end_date' => $to->toDateString(), 'apikey' => config('team_operations.weather.api_key')];
            $key = 'team-weather:v2:'.hash('sha256', json_encode([$archive, array_diff_key($params, ['apikey' => true])]));
            $result = Cache::remember($key, $archive ? 86400 : 1800, function () use ($params, $archive) {
                $response = Http::connectTimeout(5)->timeout(15)->get($archive ? 'https://customer-archive-api.open-meteo.com/v1/archive' : 'https://customer-api.open-meteo.com/v1/forecast', $params);
                abort_unless($response->successful() && is_array($response->json('daily.time')), 503, 'Weather is temporarily unavailable.');

                return ['daily' => $response->json('daily'), 'hourly' => $response->json('hourly') ?? [], 'units' => $response->json('daily_units'), 'retrieved_at' => now()->toIso8601String()];
            });
            foreach ($result['daily'] as $field => $values) {
                $daily[$field] = [...($daily[$field] ?? []), ...$values];
            }
            foreach ($result['hourly']['time'] ?? [] as $i => $time) {
                $hourly[] = ['time' => $time, 'temperature_f' => $result['hourly']['temperature_2m'][$i] ?? null, 'precipitation_inches' => $result['hourly']['precipitation'][$i] ?? null, 'gust_mph' => $result['hourly']['wind_gusts_10m'][$i] ?? null, 'weather_code' => $result['hourly']['weather_code'][$i] ?? null];
            }
            $units = $result['units'];
            foreach ($result['daily']['time'] as $date) {
                $types[$date] = ['type' => $archive ? 'historical_reanalysis' : ($date < $today->toDateString() ? 'recent_estimate' : 'forecast'), 'retrieved_at' => $result['retrieved_at']];
            }
        }
        $indices = [];
        foreach ($daily['time'] as $i => $date) {
            if ($sellingDates !== null ? in_array($date, $sellingDates, true) : ($date >= $startDate && $date <= $dailyEnd->toDateString())) {
                $indices[] = $i;
            }
        }
        foreach ($daily as $field => $values) {
            $daily[$field] = array_map(fn ($i) => $values[$i] ?? null, $indices);
        }
        $types = array_intersect_key($types, array_fill_keys($daily['time'], true));
        $show = [];
        foreach ($hours as $window) {
            if (! isset($window['date'], $window['opens'], $window['closes'])) {
                continue;
            }
            $opens = $window['date'].'T'.$window['opens'];
            $closes = $window['date'].'T'.$window['closes'];
            if ($closes <= $opens) {
                $closes = CarbonImmutable::parse($window['date'], $d['timezone'])->addDay()->toDateString().'T'.$window['closes'];
            }
            $samples = array_values(array_filter($hourly, fn ($h) => $h['time'] >= $opens && $h['time'] < $closes));
            // Precipitation is the preceding hour; select interval END timestamps.
            $rain = array_values(array_filter($hourly, fn ($h) => $h['time'] > $opens && $h['time'] <= $closes));
            $missingSamples = $this->missingHourlyTimes($samples, $opens, $closes, $d['timezone'], false);
            $missingRain = $this->missingHourlyTimes($rain, $opens, $closes, $d['timezone'], true);
            $show[] = ['date' => $window['date'], 'opens' => $window['opens'], 'closes' => $window['closes'], 'session_id' => $window['id'] ?? null, 'kind' => 'selling', 'timezone' => $d['timezone'], 'type' => $types[$window['date']]['type'] ?? 'unavailable', 'hourly_samples' => $samples, 'precipitation_intervals' => $rain, 'missing_hourly_sample_times' => $missingSamples, 'missing_precipitation_interval_times' => $missingRain, 'timestep_coverage_complete' => ! $missingSamples && ! $missingRain, 'notice' => 'Hourly model samples in the venue timezone; partial hours are not interpolated. These are show windows, not staff shifts.'];
        }
        $unique = array_values(array_unique(array_column($types, 'type')));
        $missingDates = $sellingDates === null ? null : array_values(array_diff($sellingDates, array_keys($types)));
        $hourlyComplete = $show && ! in_array(false, array_column($show, 'timestep_coverage_complete'), true);
        $coverage += ['available_dates' => array_keys($types), 'missing_dates' => $missingDates, 'hourly_windows_complete' => (bool) $hourlyComplete, 'complete' => $hasCalendar && ! $calendar['needs_review'] && ! $missingDates && $last->equalTo($end) && $hourlyComplete];

        return ['available' => (bool) $types, 'type' => count($unique) === 1 ? $unique[0] : ($unique ? 'mixed' : 'unavailable'), 'day_sources' => $types, 'daily' => $daily, 'show_hours' => $show, 'coverage' => $coverage, 'units' => $units, 'timezone' => $d['timezone'], 'venue' => ['latitude' => $latitude, 'longitude' => $longitude], 'complete_range' => $last->equalTo($end), 'provider' => 'Open-Meteo', 'attribution_url' => 'https://open-meteo.com/', 'observed_at' => now()->toIso8601String()];
    }

    /** Check actual model timestamps; gaps never become interpolated weather. */
    private function missingHourlyTimes(array $samples, string $opens, string $closes, string $timezone, bool $intervalEnds): array
    {
        $available = array_count_values(array_column($samples, 'time'));
        $start = CarbonImmutable::parse($opens, $timezone);
        $end = CarbonImmutable::parse($closes, $timezone);
        $missing = [];
        for ($stamp = $start->startOfHour()->getTimestamp(); $stamp <= $end->getTimestamp(); $stamp += 3600) {
            if ($intervalEnds ? ($stamp <= $start->getTimestamp()) : ($stamp < $start->getTimestamp() || $stamp === $end->getTimestamp())) {
                continue;
            }
            $time = CarbonImmutable::createFromTimestamp($stamp, $timezone)->format('Y-m-d\TH:i');
            if (($available[$time] ?? 0) > 0) {
                $available[$time]--;
            } else {
                $missing[] = $time;
            }
        }

        return $missing;
    }
}
