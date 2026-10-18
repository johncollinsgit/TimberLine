<?php

namespace App\Services\FleetTracking;

use App\Models\FieldServiceVehicle;
use App\Models\FleetLocationPoint;
use App\Models\FleetTrackingDevice;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\Integrations\Bouncie\BouncieConnector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only map/history projection. Provider geometry is never persisted or cached here. */
class FleetMapService
{
    public function __construct(private FleetTrackingAccessService $access, private BouncieConnector $bouncie) {}

    public function range(Tenant $tenant, Request $request): array
    {
        $zone = $tenant->fieldServiceReminderSetting?->timezone ?: 'America/New_York';
        if (! in_array($zone, timezone_identifiers_list(), true)) {
            $zone = 'America/New_York';
        }
        $days = max(1, min(30, (int) $this->access->settings($tenant)->retention_days));
        $today = CarbonImmutable::now($zone)->startOfDay();
        $cutoff = CarbonImmutable::now('UTC')->subDays($days);
        $input = $request->validate([
            'start' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:'.$cutoff->setTimezone($zone)->toDateString(), 'before_or_equal:'.$today->toDateString()],
            'end' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:'.($request->input('start') ?: $today->toDateString()), 'before_or_equal:'.$today->toDateString()],
        ]);
        $start = CarbonImmutable::parse(($input['start'] ?? $today->toDateString()).' 00:00:00', $zone)->utc()->max($cutoff);
        $end = CarbonImmutable::parse(($input['end'] ?? $input['start'] ?? $today->toDateString()).' 00:00:00', $zone)->addDay()->utc()->min(CarbonImmutable::now('UTC'));

        return ['timezone' => $zone, 'start' => $input['start'] ?? $today->toDateString(), 'end' => $input['end'] ?? $input['start'] ?? $today->toDateString(),
            'today' => $today->toDateString(), 'yesterday' => $today->subDay()->toDateString(), 'earliest' => $cutoff->setTimezone($zone)->toDateString(),
            'from' => $start->toIso8601String(), 'until' => $end->toIso8601String(), 'retention_days' => $days, 'partial_first_day' => $start->gt(CarbonImmutable::parse(($input['start'] ?? $today->toDateString()).' 00:00:00', $zone)->utc())];
    }

    public function snapshot(Tenant $tenant, Request $request): array
    {
        $range = $this->range($tenant, $request);
        $settings = $this->access->settings($tenant);
        $installation = \App\Models\HighLevel\Installation::where('tenant_id', $tenant->id)->first();
        $available = $settings->bouncie_tracking_enabled && $this->access->isPolicyApproved($settings)
            && (! $installation || $installation->collectionAllowed());
        $connection = $this->connection($tenant);
        $devices = FleetTrackingDevice::forTenantId($tenant->id)->where('provider', 'bouncie')->get()->keyBy('field_service_vehicle_id');
        // Rank per device: a busy van must never crowd another van out of a global LIMIT.
        $ranked = DB::table('fleet_location_points')->where('tenant_id', $tenant->id)->where('source', 'bouncie')
            ->whereIn('fleet_tracking_device_id', $devices->modelKeys())
            ->where('recorded_at', '>=', CarbonImmutable::now()->subDays($range['retention_days']))->where('recorded_at', '<=', now())
            ->selectRaw('*, row_number() over (partition by fleet_tracking_device_id order by recorded_at desc, id desc) as location_rank');
        $points = $available ? DB::query()->fromSub($ranked, 'ranked')->where('location_rank', 1)->get()->keyBy('fleet_tracking_device_id') : collect();
        $vehicles = FieldServiceVehicle::forTenantId($tenant->id)->where('status', 'active')->orderBy('name')->orderBy('id')->get()->map(function ($vehicle) use ($tenant, $devices, $points, $connection, $available): array {
            $device = $devices->get($vehicle->id);
            $point = $device ? $points->get($device->id) : null;
            // Connection-bound mappings cannot expose another connection's saved position.
            if ($device?->integration_connection_id && (int) $device->integration_connection_id !== (int) $connection?->id) {
                $point = null;
            }
            $at = $point ? CarbonImmutable::parse($point->recorded_at) : null;
            $age = $at ? max(0, (int) $at->diffInSeconds(now())) : null;
            $safe = $point ? json_decode($point->safe_payload ?: '{}', true) : [];
            $speed = $safe['speed'] ?? null;
            $status = ! $available ? 'disabled' : (! $device ? 'unmapped' : ($device->status !== 'active' ? 'disconnected' : (! $connection?->isConnected() ? 'disconnected' : ($point ? ($age > 900 ? 'stale' : 'available') : 'unavailable'))));
            $signal = $device && $available ? app(FleetDeviceHealthService::class)->latest($tenant, $device) : null;
            if ($signal && $signal['status'] === 'disconnected' && (! $at || $point?->event_type === 'vehicleSnapshot' || CarbonImmutable::parse($signal['at'])->gte($at))) {
                $status = 'disconnected';
            }
            // A document snapshot is not a new GPS fix, and must not assert current motion.
            $motion = $status === 'available' && $point?->event_type !== 'vehicleSnapshot' && is_numeric($speed)
                ? ((float) $speed > 0 ? 'Moving' : 'Stopped') : 'Status unknown';

            return ['id' => (int) $vehicle->id, 'name' => $vehicle->name, 'color' => $this->color((int) $vehicle->id), 'status' => $status, 'motion' => $motion,
                'device_signal_at' => $signal['at'] ?? null,
                'history_url' => route('field-service.fleet-tracking.history', ['vehicleId' => $vehicle->id, 'tenant' => $tenant->slug], false),
                'location' => $point ? ['latitude' => (float) $point->latitude, 'longitude' => (float) $point->longitude, 'recorded_at' => $at->toIso8601String(), 'age_seconds' => $age,
                    'source' => $point->event_type === 'vehicleSnapshot' ? 'provider_snapshot' : 'gps', 'stale' => $age > 900 || $status !== 'available'] : null];
        })->all();

        return ['vehicles' => $vehicles, 'range' => $range, 'checked_at' => now()->toIso8601String(),
            'feed' => ['status' => ! $available ? 'disabled' : ($connection?->isConnected() ? 'connected' : 'disconnected')],
            'selection_key' => 'fleet-map:v1:'.$tenant->id.':'.$request->user()->id];
    }

    public function history(Tenant $tenant, int $vehicleId, Request $request): array
    {
        $vehicle = FieldServiceVehicle::forTenantId($tenant->id)->where('status', 'active')->findOrFail($vehicleId);
        $range = $this->range($tenant, $request);
        $base = ['vehicle_id' => (int) $vehicle->id, 'range' => $range, 'trips' => [], 'samples' => [], 'status' => 'unavailable', 'message' => ''];
        $settings = $this->access->settings($tenant);
        if (! $settings->bouncie_tracking_enabled || ! $this->access->isPolicyApproved($settings)) {
            return array_replace($base, ['status' => 'disabled', 'message' => 'Company-van tracking is paused or policy approval is required.']);
        }
        $device = FleetTrackingDevice::forTenantId($tenant->id)->where('field_service_vehicle_id', $vehicleId)->where('provider', 'bouncie')->first();
        if (! $device) {
            return array_replace($base, ['status' => 'unmapped', 'message' => 'No Bouncie tracker is linked to this van.']);
        }
        if (FleetTrackingDevice::withoutGlobalScopes()->where('provider', 'bouncie')->where('external_device_id', $device->external_device_id)->where('status', 'active')->count() > 1) {
            return array_replace($base, ['message' => 'This tracker mapping is ambiguous. An administrator must review it before history can be shown.']);
        }
        $from = CarbonImmutable::parse($range['from']);
        $until = CarbonImmutable::parse($range['until']);
        $connection = $this->connection($tenant);
        if (! $connection?->isConnected() || $device->status !== 'active'
            || ($device->integration_connection_id && (int) $device->integration_connection_id !== (int) $connection->id)) {
            return $this->withSamples(array_replace($base, ['status' => 'disconnected', 'message' => 'Tracking is disconnected. An owner or administrator can review Bouncie settings. Retained samples are shown where available.']), $tenant, $device, $from, $until);
        }
        // Do not bypass the CRM installation's separate activation/account limits.
        $install = \App\Models\HighLevel\Installation::where('tenant_id', $tenant->id)->first();
        if ($install && ! app(\App\Services\HighLevel\FleetTelemetryService::class)->allowed($device)) {
            return array_replace($base, ['status' => 'disabled', 'message' => 'History collection is not active for this account.']);
        }
        $trips = [];
        $failed = false;
        $unauthorized = false;
        try {
            $client = $this->bouncie->client($connection);
            $cutoff = CarbonImmutable::now('UTC')->subDays($range['retention_days']);
            // Five-day chunks plus a one-day boundary margin on each side stay <= 7 days.
            // Bouncie searches fully contained trips, so also include adjacent-day trips.
            for ($cursor = $from; $cursor->lt($until); $cursor = $next) {
                $next = $cursor->addDays(5)->min($until);
                try {
                    $rows = $client->trips($device->external_device_id, $cursor->subDay()->max($cutoff)->toIso8601String(), $next->addDay()->min(CarbonImmutable::now('UTC'))->toIso8601String());
                    foreach ($rows as $row) {
                        if (($row['imei'] ?? null) !== $device->external_device_id) {
                            continue;
                        }
                        $trip = $this->trip($row, $from, $until, $cutoff);
                        if ($trip) {
                            $trips[$trip['id']] = $trip;
                        }
                    }
                } catch (\Throwable $e) {
                    $failed = true;
                    $unauthorized = $this->authorizationFailed($e);
                    if ($unauthorized) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $failed = true;
            $unauthorized = $this->authorizationFailed($e);
        }
        usort($trips, fn ($a, $b) => strcmp($a['started_at'], $b['started_at']));

        $result = array_replace($base, ['trips' => array_values($trips), 'status' => $unauthorized ? 'authorization_expired' : ($failed ? 'partial' : (count($trips) ? 'ready' : 'no_trips')),
            'message' => $unauthorized ? 'Bouncie authorization expired or was denied. An owner or administrator needs to reconnect. Retained samples remain available.'
                : ($failed ? 'Some Bouncie history could not be loaded. Available trips and retained samples are shown. Retry this van.'
                    : (count($trips) ? 'Bouncie GPS traces. Trips remain separate; missing geometry and large jumps are not connected. GPS sampling can leave gaps.' : 'No completed Bouncie trips were returned for this period. This does not prove the van did not travel; trips may be in progress, delayed, or missing. Retained samples are shown separately.')),
            'boundary_notice' => 'Trips crossing a date boundary are shown in full when returned. Trips extending more than a day beyond the range or beyond retention may be unavailable.']);

        return ! $trips || $failed || collect($trips)->contains(fn ($t) => $t['geometry_status'] === 'missing')
            ? $this->withSamples($result, $tenant, $device, $from, $until) : $result;
    }

    private function trip(array $row, CarbonImmutable $from, CarbonImmutable $until, CarbonImmutable $cutoff): ?array
    {
        try {
            if (empty($row['startTime']) || empty($row['endTime']) || ! is_string($row['transactionId'] ?? null)) {
                return null;
            }
            $start = CarbonImmutable::parse($row['startTime']);
            $end = CarbonImmutable::parse($row['endTime']);
        } catch (\Throwable) {
            return null;
        }
        if ($end->lte($start) || $start->gte($until) || $end->lte($from) || $end->isFuture() || $start->lt($cutoff)) {
            return null;
        }
        $geometry = $row['gps'] ?? null;
        if (is_string($geometry)) {
            $geometry = json_decode($geometry, true);
        }
        $limited = false;
        $segments = $this->segments($geometry, $limited);
        $count = array_sum(array_map('count', $segments));
        $traceSegments = array_map(fn ($s) => count($s) > 2, $segments);
        $segments = array_map(fn ($s) => count($s) > 2 ? $this->simplify($s) : $s, $segments);
        $distance = $row['distance'] ?? null;

        return ['id' => hash('sha256', $row['transactionId']), 'started_at' => $start->toIso8601String(), 'ended_at' => $end->toIso8601String(),
            'duration_seconds' => (int) $start->diffInSeconds($end), 'distance_miles' => is_numeric($distance) && is_finite((float) $distance) && $distance >= 0 ? (float) $distance : null,
            'crosses_range' => $start->lt($from) || $end->gt($until), 'segments' => $segments, 'trace_segments' => $traceSegments,
            'geometry_status' => in_array(true, $traceSegments, true) ? 'trace' : ($count ? 'sparse' : 'missing'),
            'geometry_limited' => $limited, 'simplification_meters' => 12];
    }

    /** Representative evidence only; normal trips use their simplified provider geometry. */
    private function withSamples(array $result, Tenant $tenant, FleetTrackingDevice $device, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $query = FleetLocationPoint::forTenantId($tenant->id)->where('fleet_tracking_device_id', $device->id)->where('source', 'bouncie')
            ->where('recorded_at', '>=', $from)->where('recorded_at', '<', $until);
        $ranked = (clone $query)->selectRaw('*, row_number() over (partition by cast(strftime(\'%s\', recorded_at) as integer) / 300 order by recorded_at desc, id desc) as sample_rank');
        // SQL date functions differ across the supported SQLite test and MySQL runtime.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $ranked = (clone $query)->selectRaw('*, row_number() over (partition by floor(unix_timestamp(recorded_at) / 300) order by recorded_at desc, id desc) as sample_rank');
        }
        $samples = DB::query()->fromSub($ranked->toBase(), 'representative_samples')->where('sample_rank', 1)->orderByDesc('recorded_at')->limit(101)->get();
        $result['samples_truncated'] = $samples->count() > 100;
        $result['samples'] = $samples->take(100)->reverse()->values()->map(fn ($p) => ['latitude' => (float) $p->latitude, 'longitude' => (float) $p->longitude, 'recorded_at' => CarbonImmutable::parse($p->recorded_at)->toIso8601String()])->all();

        return $result;
    }

    /** Douglas–Peucker removes redundant samples (12m tolerance), retaining actual coordinates and turns. */
    private function simplify(array $points): array
    {
        $keep = [0 => true, count($points) - 1 => true];
        $stack = [[0, count($points) - 1]];
        while ($stack) {
            [$first, $last] = array_pop($stack);
            $scaleX = 111320 * cos(deg2rad(($points[$first][0] + $points[$last][0]) / 2));
            $dx = ($points[$last][1] - $points[$first][1]) * $scaleX;
            $dy = ($points[$last][0] - $points[$first][0]) * 111320;
            $length = $dx * $dx + $dy * $dy;
            $farthest = null;
            $max = 144.0;
            for ($i = $first + 1; $i < $last; $i++) {
                $x = ($points[$i][1] - $points[$first][1]) * $scaleX;
                $y = ($points[$i][0] - $points[$first][0]) * 111320;
                $t = $length > 0 ? max(0, min(1, ($x * $dx + $y * $dy) / $length)) : 0;
                $distance = ($x - $t * $dx) ** 2 + ($y - $t * $dy) ** 2;
                if ($distance > $max) {
                    $max = $distance;
                    $farthest = $i;
                }
            }
            if ($farthest !== null) {
                $keep[$farthest] = true;
                $stack[] = [$first, $farthest];
                $stack[] = [$farthest, $last];
            }
        }
        ksort($keep);

        return array_map(fn ($index) => $points[$index], array_keys($keep));
    }

    /** Keep GeoJSON segments separate; don't infer roads across >quarter-mile sampling gaps. */
    private function segments(mixed $geometry, bool &$limited = false): array
    {
        if (! is_array($geometry)) {
            return [];
        }
        $type = $geometry['type'] ?? '';
        if ($type === 'Feature') {
            return $this->segments($geometry['geometry'] ?? null, $limited);
        }
        if ($type === 'FeatureCollection') {
            $result = [];
            foreach (array_slice($geometry['features'] ?? [], 0, 100) as $feature) {
                array_push($result, ...$this->segments($feature, $limited));
            }

            return $result;
        }
        $lines = $type === 'LineString' ? [$geometry['coordinates'] ?? []] : ($type === 'MultiLineString' ? ($geometry['coordinates'] ?? []) : []);
        $segments = [];
        $remaining = 20000;
        foreach ($lines as $line) {
            $segment = [];
            foreach (is_array($line) ? $line : [] as $pair) {
                if (--$remaining < 0) {
                    $limited = true;
                    break;
                }
                $valid = is_array($pair) && count($pair) >= 2 && is_numeric($pair[0]) && is_numeric($pair[1])
                    && is_finite((float) $pair[0]) && is_finite((float) $pair[1]) && abs((float) $pair[0]) <= 180 && abs((float) $pair[1]) <= 90;
                $point = $valid ? [(float) $pair[1], (float) $pair[0]] : null;
                if (! $point || ($segment && $this->miles(end($segment), $point) > 0.25)) {
                    if ($segment) {
                        $segments[] = $segment;
                        $segment = [];
                    }
                }
                if ($point) {
                    $segment[] = $point;
                }
            }
            if ($segment) {
                $segments[] = $segment;
            }
            if ($limited) {
                break;
            }
        }

        return $segments;
    }

    private function miles(array $a, array $b): float
    {
        $h = sin(deg2rad($a[0] - $b[0]) / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin(deg2rad($a[1] - $b[1]) / 2) ** 2;

        return 7917.5226 * asin(min(1, sqrt($h)));
    }

    private function color(int $id): string
    {
        // Stable per vehicle, without repeating a short palette for non-contiguous IDs.
        return 'hsl('.round(fmod($id * 137.508, 360), 2).', 72%, 34%)';
    }

    private function connection(Tenant $tenant): ?IntegrationConnection
    {
        return IntegrationConnection::forTenantId($tenant->id)->where('provider', 'bouncie')->first();
    }

    private function authorizationFailed(\Throwable $e): bool
    {
        return $e instanceof \Illuminate\Http\Client\RequestException && (in_array($e->response->status(), [401, 403], true)
            || ($e->response->status() === 400 && $e->response->json('error') === 'invalid_grant'));
    }
}
