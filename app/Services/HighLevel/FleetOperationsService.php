<?php

namespace App\Services\HighLevel;

use App\Models\FleetLocationPoint;
use App\Models\FleetTrackingDevice;
use App\Models\HighLevel\FleetOperationRecord as Record;
use App\Models\HighLevel\Installation;
use App\Services\FleetTracking\FleetTrackingAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FleetOperationsService
{
    public function device(Installation $install, int $id): FleetTrackingDevice
    {
        return FleetTrackingDevice::forTenantId($install->tenant_id)->whereKey($id)->where('provider', 'bouncie')->where('status', 'active')
            ->where('integration_connection_id', app(FleetService::class)->connection($install)?->id ?? 0)->firstOrFail();
    }

    public function record(Installation $install, int $id, string $kind): Record
    {
        return Record::forTenantId($install->tenant_id)->whereKey($id)->where('kind', $kind)->firstOrFail();
    }

    public function save(Installation $install, string $kind, array $p, ?int $deviceId = null, ?Record $record = null): Record
    {
        return DB::transaction(function () use ($install, $kind, $p, $deviceId, $record): Record {
            Installation::whereKey($install->id)->lockForUpdate()->firstOrFail();
            abort_unless($install->refresh()->setupAllowed(), 403);
            if ($deviceId !== null) {
                $this->device($install, $deviceId);
            }
            if ($record) {
                $record = $this->record($install, $record->id, $kind);
            }
            if ($kind === 'profile') {
                $record = Record::firstOrNew(['tenant_id' => $install->tenant_id, 'kind' => $kind, 'source_key' => hash('sha256', (string) $deviceId)]);
            }
            $record ??= new Record(['tenant_id' => $install->tenant_id, 'kind' => $kind, 'source_key' => hash('sha256', bin2hex(random_bytes(20)))]);
            $record->fill(['device_id' => $deviceId ?? $record->device_id, 'payload' => array_replace($record->payload ?? [], $p), 'event_at' => $kind === 'trip' ? $record->event_at : now()])->save();

            return $record;
        }, 3);
    }

    public function bootstrap(Installation $install, array $vehicles): array
    {
        if (! $install->hasSubscriptionAccess()) {
            return [];
        }
        $ids = array_column($vehicles, 'id');
        $access = app(FleetTrackingAccessService::class);
        $showTelemetry = $access->enabledFor($install->tenant) && $access->isPolicyApproved($access->settings($install->tenant));
        $days = max(1, min(30, app(FleetTrackingAccessService::class)->settings($install->tenant)->retention_days));
        $query = Record::forTenantId($install->tenant_id)->where(function ($q) use ($ids): void {
            $q->whereNull('device_id')->orWhereIn('device_id', $ids);
        })->where(function ($q) use ($days): void {
            $q->whereNotIn('kind', ['trip', 'job', 'telemetry'])->orWhere('event_at', '>=', now()->subDays($days));
        });
        // Keep enduring plans and crew profiles visible regardless of trip volume.
        $records = (clone $query)->whereNotIn('kind', ['trip', 'service_log', 'alert'])->orderByDesc('event_at')->get();
        foreach (['trip', 'service_log', 'alert'] as $historyKind) {
            $records = $records->concat((clone $query)->where('kind', $historyKind)->orderByDesc('event_at')->limit(200)->get());
        }
        if (! $showTelemetry) {
            $records = $records->reject(fn ($r) => in_array($r->kind, ['telemetry', 'trip', 'alert'], true));
        }
        $this->maintenance($install, $records);
        $tasks = Record::forTenantId($install->tenant_id)->where('kind', 'maintenance_task')->whereIn('device_id', $ids)->where('status', 'open')->get();
        $jobs = $records->where('kind', 'job')->where('status', 'active')->values();
        $trips = $records->where('kind', 'trip')->take(200)->map(function ($trip) use ($jobs): array {
            $p = $trip->payload;
            $start = $p['started_at'] ?? null;
            $end = $p['ended_at'] ?? null;
            $candidates = $jobs->filter(function ($job) use ($trip, $start, $end): bool {
                $j = $job->payload;
                $scheduled = $j['scheduled_start'] ?? $j['crm_start'] ?? null;

                return $scheduled && $start && $end && (int) ($j['device_id'] ?? 0) === $trip->device_id
                    && CarbonImmutable::parse($scheduled)->between(CarbonImmutable::parse($start)->subHour(), CarbonImmutable::parse($end)->addHour());
            })->map(fn ($job) => ['id' => $job->id, 'title' => $job->payload['title']])->values()->all();
            $distance = $p['distance_miles'] ?? null;
            $expected = $p['expected_miles'] ?? null;
            $threshold = $expected !== null ? (float) $expected + max((float) ($p['extra_miles'] ?? 5), (float) $expected * (float) ($p['extra_percent'] ?? 30) / 100) : null;

            return $this->serialize($trip) + ['drive_seconds' => isset($p['duration_seconds'], $p['idle_seconds']) ? max(0, $p['duration_seconds'] - $p['idle_seconds']) : null,
                'candidates' => $candidates, 'route_review' => $expected === null ? 'baseline_needed' : ($distance === null ? 'distance_pending' : ($distance > $threshold ? 'review' : 'within_tolerance'))];
        })->values()->all();

        return ['profiles' => $records->where('kind', 'profile')->map(fn ($r) => $this->serialize($r))->values()->all(),
            'telemetry' => $records->where('kind', 'telemetry')->map(fn ($r) => $this->serialize($r))->values()->all(),
            'jobs' => $jobs->map(fn ($r) => $this->serialize($r))->all(), 'job_source' => $records->firstWhere('kind', 'job_source')?->payload,
            'trips' => $trips, 'service_plans' => $records->where('kind', 'service_plan')->map(fn ($r) => $this->serialize($r))->values()->all(),
            'service_logs' => $records->where('kind', 'service_log')->take(200)->map(fn ($r) => $this->serialize($r))->values()->all(),
            'maintenance_tasks' => $tasks->map(fn ($r) => $this->serialize($r))->all(),
            'alerts' => $records->where('kind', 'alert')->take(200)->map(fn ($r) => $this->serialize($r))->values()->all()];
    }

    public function maintenance(Installation $install, $records = null): void
    {
        if (! $install->setupAllowed()) {
            return;
        }
        $records ??= Record::forTenantId($install->tenant_id)->whereIn('kind', ['service_plan', 'telemetry'])->get();
        foreach ($records->where('kind', 'service_plan')->where('status', 'open') as $plan) {
            $p = $plan->payload;
            $mileage = $records->where('kind', 'telemetry')->firstWhere('device_id', $plan->device_id)?->payload['odometer'] ?? null;
            $dueMiles = isset($p['due_miles']) && $mileage !== null && $mileage >= $p['due_miles'];
            $dueDate = isset($p['due_date']) && CarbonImmutable::parse($p['due_date'])->endOfDay()->lte(now());
            if (! $dueMiles && ! $dueDate) {
                continue;
            }
            Record::firstOrCreate(['tenant_id' => $install->tenant_id, 'kind' => 'maintenance_task', 'source_key' => hash('sha256', 'plan|'.$plan->id)],
                ['device_id' => $plan->device_id, 'event_at' => now(), 'payload' => ['plan_id' => $plan->id, 'title' => $p['title'], 'assignee' => $p['assignee'] ?? '',
                    'reason' => $dueMiles ? 'Mileage threshold reached' : 'Service date reached']]);
        }
    }

    public function completeService(Installation $install, array $p): void
    {
        DB::transaction(function () use ($install, $p): void {
            $plan = $this->record($install, $p['plan_id'], 'service_plan');
            $plan = Record::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->device($install, $plan->device_id);
            abort_unless($plan->status === 'open', 409, 'This service plan is already completed.');
            $this->save($install, 'service_log', $p + ['title' => $plan->payload['title']], $plan->device_id);
            $plan->update(['status' => 'completed']);
            Record::forTenantId($install->tenant_id)->where('kind', 'maintenance_task')->where('source_key', hash('sha256', 'plan|'.$plan->id))->update(['status' => 'resolved']);
            $old = $plan->payload;
            if (! empty($old['interval_miles']) || ! empty($old['interval_days'])) {
                $next = $old;
                $next['due_miles'] = ! empty($old['interval_miles']) ? $p['odometer'] + $old['interval_miles'] : null;
                $next['due_date'] = ! empty($old['interval_days']) ? CarbonImmutable::parse($p['serviced_at'])->addDays($old['interval_days'])->toDateString() : null;
                $this->save($install, 'service_plan', $next, $plan->device_id);
            }
        }, 3);
    }

    public function path(Installation $install, Record $trip): array
    {
        $this->device($install, $trip->device_id);
        $access = app(FleetTrackingAccessService::class);
        $settings = $access->settings($install->tenant);
        abort_unless($install->hasSubscriptionAccess() && $access->enabledFor($install->tenant) && $access->isPolicyApproved($settings), 403);
        $p = $trip->payload;
        if (empty($p['started_at']) || empty($p['ended_at'])) {
            return ['points' => [], 'message' => 'Trip timestamps are still pending.'];
        }
        $points = FleetLocationPoint::forTenantId($install->tenant_id)->where('fleet_tracking_device_id', $trip->device_id)->where('source', 'bouncie')
            ->where('recorded_at', '>=', now()->subDays(max(1, min(30, $settings->retention_days))))
            ->whereBetween('recorded_at', [CarbonImmutable::parse($p['started_at']), CarbonImmutable::parse($p['ended_at'])])->orderBy('recorded_at')->limit(2001)->get();

        return ['points' => $points->take(2000)->map(fn ($point) => ['lat' => (float) $point->latitude, 'lng' => (float) $point->longitude, 'at' => $point->recorded_at->toIso8601String()])->all(),
            'truncated' => $points->count() > 2000, 'message' => 'Reported GPS samples; gaps between samples are not a verified road route.'];
    }

    public function dispatch(Installation $install, Record $job, array $vehicles): array
    {
        $j = $job->payload;
        abort_unless($job->status === 'active' && isset($j['latitude'], $j['longitude']), 422, 'Set job coordinates before requesting suggestions.');
        $profiles = Record::forTenantId($install->tenant_id)->where('kind', 'profile')->get()->keyBy('device_id');
        $jobs = Record::forTenantId($install->tenant_id)->where('kind', 'job')->where('status', 'active')->get();
        $scheduledStart = $j['scheduled_start'] ?? $j['crm_start'] ?? null;
        $scheduledEnd = $j['scheduled_end'] ?? $j['crm_end'] ?? null;
        abort_unless($scheduledStart && $scheduledEnd, 422, 'Set a job start and end time before requesting suggestions.');
        $start = CarbonImmutable::parse($scheduledStart);
        $end = CarbonImmutable::parse($scheduledEnd);
        abort_unless($end->gt($start), 422, 'The job end time must follow its start time.');
        $suggestions = [];
        foreach ($vehicles as $v) {
            $p = $profiles->get($v['id'])?->payload ?? [];
            if (empty($p['available']) || empty($p['crew']) || ! $v['location'] || $v['location']['older_reading']) {
                continue;
            }
            $conflict = $jobs->contains(function ($other) use ($v, $job, $start, $end): bool {
                if ($other->id === $job->id) {
                    return false;
                }
                $p = $other->payload;
                if ((int) ($p['device_id'] ?? 0) !== $v['id']) {
                    return false;
                }
                $s = $p['scheduled_start'] ?? $p['crm_start'] ?? null;
                $e = $p['scheduled_end'] ?? $p['crm_end'] ?? null;

                // Unknown schedules cannot prove availability.
                return ! $s || ! $e || (CarbonImmutable::parse($s)->lt($end) && CarbonImmutable::parse($e)->gt($start));
            });
            if ($conflict || array_diff($j['skills'] ?? [], $p['skills'] ?? [])) {
                continue;
            }
            $missing = [];
            foreach ($j['materials'] ?? [] as $name => $quantity) {
                if (($p['materials'][$name] ?? 0) < $quantity) {
                    $missing[] = $name;
                }
            }
            if ($missing) {
                continue;
            }
            $suggestions[] = ['device_id' => $v['id'], 'name' => $v['name'], 'crew' => $p['crew'],
                'straight_line_miles' => round($this->miles($v['location']['latitude'], $v['location']['longitude'], $j['latitude'], $j['longitude']), 1),
                'reported_at' => $v['location']['reported_at']];
        }
        usort($suggestions, fn ($a, $b) => $a['straight_line_miles'] <=> $b['straight_line_miles']);

        return ['suggestions' => $suggestions, 'basis' => 'Distance is straight-line, not road mileage or ETA. Availability, skills and stock are manager-maintained. Confirm before dispatch.'];
    }

    private function miles(float $a, float $b, float $c, float $d): float
    {
        $h = sin(deg2rad($c - $a) / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin(deg2rad($d - $b) / 2) ** 2;

        return 3958.8 * 2 * asin(sqrt(min(1, max(0, $h))));
    }

    private function serialize(Record $r): array
    {
        return ['id' => $r->id, 'device_id' => $r->device_id, 'status' => $r->status, 'event_at' => $r->event_at?->toIso8601String(), 'details' => $r->payload];
    }
}
