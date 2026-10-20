<?php

namespace App\Services\Team\Operations;

use App\Models\TeamOperationRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OperationsWeather
{
    public function event(TeamOperationRecord $event): array
    {
        abort_unless(config('team_operations.weather.enabled') && config('team_operations.weather.commercial') && config('team_operations.weather.api_key'), 503, 'Weather needs a configured commercial provider.');
        $d = $event->data;
        $latitude = $d['latitude'] ?? null;
        $longitude = $d['longitude'] ?? null;
        abort_unless(is_numeric($latitude) && $latitude >= -90 && $latitude <= 90 && is_numeric($longitude) && $longitude >= -180 && $longitude <= 180 && $event->starts_on, 422, 'Confirm event coordinates and dates first.');
        $future = $event->starts_on->isAfter(now()->startOfDay()->addDays(15));
        if ($future) {
            return ['type' => 'outside_forecast_horizon', 'available' => false, 'notice' => 'Daily forecast is not available this far ahead. Historical comparisons are context, not a forecast.', 'provider' => 'Open-Meteo'];
        }
        abort_unless(in_array($d['timezone'] ?? '', timezone_identifiers_list(), true), 422, 'Confirm the venue timezone before requesting weather.');
        $historical = $event->ends_on?->isBefore(now()->subDays(5)) ?? $event->starts_on->isBefore(now()->subDays(5));
        $params = ['latitude' => $latitude, 'longitude' => $longitude, 'timezone' => $d['timezone'] ?? 'America/New_York', 'temperature_unit' => 'fahrenheit', 'wind_speed_unit' => 'mph', 'precipitation_unit' => 'inch', 'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum,wind_gusts_10m_max', 'start_date' => $event->starts_on->toDateString(), 'end_date' => ($event->ends_on ?? $event->starts_on)->min(now()->addDays(15))->toDateString(), 'apikey' => config('team_operations.weather.api_key')];
        $key = 'team-weather:'.hash('sha256', json_encode(array_diff_key($params, ['apikey' => true])));

        return Cache::remember($key, $historical ? 86400 : 1800, function () use ($params, $historical) {
            $response = Http::connectTimeout(5)->timeout(15)->get($historical ? 'https://customer-archive-api.open-meteo.com/v1/archive' : 'https://customer-api.open-meteo.com/v1/forecast', $params);
            abort_unless($response->successful() && is_array($response->json('daily')), 503, 'Weather is temporarily unavailable.');

            return ['available' => true, 'type' => $historical ? 'historical_reanalysis' : 'forecast', 'daily' => $response->json('daily'), 'units' => $response->json('daily_units'), 'provider' => 'Open-Meteo', 'attribution_url' => 'https://open-meteo.com/', 'observed_at' => now()->toIso8601String()];
        });
    }
}
