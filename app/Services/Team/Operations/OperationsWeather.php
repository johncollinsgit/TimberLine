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
        if ($reviewed !== null) {
            $d = array_replace($d, array_fill_keys(['latitude', 'longitude', 'timezone', 'daily_hours'], null), array_intersect_key($reviewed, array_flip(['latitude', 'longitude', 'timezone', 'daily_hours'])));
            $event = clone $event;
            $event->starts_on = $reviewed['starts_on'] ?? $event->starts_on;
            $event->ends_on = $reviewed['ends_on'] ?? $event->ends_on;
        }
        $latitude = $d['latitude'] ?? null;
        $longitude = $d['longitude'] ?? null;
        abort_unless(is_numeric($latitude) && $latitude >= -90 && $latitude <= 90 && is_numeric($longitude) && $longitude >= -180 && $longitude <= 180 && $event->starts_on, 422, 'Confirm the historical venue coordinates and event dates first.');
        if ($event->starts_on->toDateString() > now('UTC')->addDays(16)->toDateString()) {
            return ['type' => 'outside_forecast_horizon', 'available' => false, 'notice' => 'Daily forecast is not available this far ahead.', 'provider' => 'Open-Meteo'];
        }
        abort_unless($event->ends_on, 422, 'Confirm the last show date before requesting weather.');
        abort_unless(in_array($d['timezone'] ?? '', timezone_identifiers_list(), true), 422, 'Confirm the venue timezone before requesting weather.');
        $today = CarbonImmutable::now($d['timezone'])->startOfDay();
        $start = CarbonImmutable::parse($event->starts_on->toDateString(), $d['timezone']);
        $end = CarbonImmutable::parse($event->ends_on->toDateString(), $d['timezone']);
        abort_unless($end >= $start && $start->diffInDays($end) <= 366, 422, 'Weather requests cover at most one event year.');
        if ($start > $today->addDays(15)) {
            return ['type' => 'outside_forecast_horizon', 'available' => false, 'notice' => 'Daily forecast is not available this far ahead. Historical comparisons are context, not a forecast.', 'provider' => 'Open-Meteo'];
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
        $show = [];
        foreach ($d['daily_hours'] ?? [] as $hours) {
            if (! isset($hours['date'], $hours['opens'], $hours['closes'])) {
                continue;
            }
            $opens = $hours['date'].'T'.$hours['opens'];
            $closes = $hours['date'].'T'.$hours['closes'];
            if ($closes <= $opens) {
                $closes = CarbonImmutable::parse($hours['date'], $d['timezone'])->addDay()->toDateString().'T'.$hours['closes'];
            }
            $samples = array_values(array_filter($hourly, fn ($h) => $h['time'] >= $opens && $h['time'] < $closes));
            // Precipitation is the preceding hour; select interval END timestamps.
            $rain = array_values(array_filter($hourly, fn ($h) => $h['time'] > $opens && $h['time'] <= $closes));
            $show[] = ['date' => $hours['date'], 'opens' => $hours['opens'], 'closes' => $hours['closes'], 'timezone' => $d['timezone'], 'type' => $types[$hours['date']]['type'] ?? 'unavailable', 'hourly_samples' => $samples, 'precipitation_intervals' => $rain, 'notice' => 'Hourly model samples in the venue timezone; partial hours are not interpolated.'];
        }
        $unique = array_values(array_unique(array_column($types, 'type')));

        return ['available' => true, 'type' => count($unique) === 1 ? $unique[0] : 'mixed', 'day_sources' => $types, 'daily' => $daily, 'show_hours' => $show, 'units' => $units, 'timezone' => $d['timezone'], 'venue' => ['latitude' => $latitude, 'longitude' => $longitude], 'complete_range' => $last->equalTo($end), 'provider' => 'Open-Meteo', 'attribution_url' => 'https://open-meteo.com/', 'observed_at' => now()->toIso8601String()];
    }
}
